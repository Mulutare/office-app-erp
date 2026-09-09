<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

/** Peer decisions extend the existing request allocation and controlled transfer ledger. */
trait StockRequestPeerWorkflow
{
    private function uncommittedLinesLocked(PDO $c,int $company,int $request,int $exclude=0): array
    {
        $lines=$this->remainingLinesLocked($c,$company,$request);
        $s=$c->prepare("SELECT request_line_id,SUM(quantity) quantity FROM inventory_peer_proposals
            WHERE company_id=? AND request_id=? AND state='proposed' AND proposal_id<>? GROUP BY request_line_id");
        $s->execute([$company,$request,$exclude]); $held=array_column($s->fetchAll(PDO::FETCH_ASSOC),'quantity','request_line_id');
        foreach ($lines as &$line) $line['remaining_quantity']=max(0,round((float)$line['remaining_quantity']-(float)($held[$line['request_line_id']]??0),3));
        unset($line);
        return array_values(array_filter($lines,static fn($l)=>$l['remaining_quantity']>0.0005));
    }

    private function peerCandidates(int $company,int $actor,?array $request): array
    {
        if (!$request || ($request['request_kind']??'')!=='manager_replenishment'
            || (int)$request['current_handler_user_id']!==$actor
            || !$this->actorCan($company,$actor,'inventory.stock_requests.process')) return [];
        $h=new StockHierarchy(); $all=$h->authorities($company); $destination=null;
        foreach ($all as $a) if ((int)$a['authority_id']===(int)$request['serving_authority_id']) $destination=$a;
        if (!$destination) return [];
        $parent=$h->parent($destination,$all);
        if (!$parent || (int)$parent['user_id']!==$actor) return [];
        $result=[];
        foreach ($all as $a) {
            if ($a['authority_level']!==$destination['authority_level'] || (int)$a['warehouse_id']===(int)$destination['warehouse_id']) continue;
            $p=$h->parent($a,$all);
            if ($p && (int)$p['authority_id']===(int)$parent['authority_id']) $result[]=$a;
        }
        return $result;
    }

    private function peerWorkspace(int $company,int $actor,?int $request): array
    {
        $users=(new StockHierarchy())->userIds($company,$actor);
        $ids=implode(',',array_map('intval',$users?:[$actor]));
        $scope=(new InventoryReadScope())->isAdministrator($company,$actor)?'1=1':"(p.proposed_by IN ($ids) OR p.source_owner_user_id=? OR p.destination_owner_user_id=?)";
        $s=\db()->prepare("SELECT p.*,r.request_number,l.product_id,sp.name product_name,
            sw.name source_name,dw.name destination_name,u.display_name proposer_name,
            owner.display_name source_owner_name,receiver.display_name destination_owner_name,
            b.quantity_available,tl.transfer_id,t.transfer_number,t.status transfer_status,
            t.dispatched_by,t.dispatched_at,t.posted_by,t.posted_at
            FROM inventory_peer_proposals p
            JOIN inventory_stock_requests r ON r.company_id=p.company_id AND r.request_id=p.request_id
            JOIN inventory_stock_request_lines l ON l.company_id=p.company_id AND l.request_line_id=p.request_line_id AND l.request_id=p.request_id
            JOIN sales_products sp ON sp.company_id=l.company_id AND sp.product_id=l.product_id
            JOIN inventory_stock_authorities sa ON sa.company_id=p.company_id AND sa.authority_id=p.source_authority_id
            JOIN inventory_stock_authorities da ON da.company_id=p.company_id AND da.authority_id=p.destination_authority_id
            JOIN inventory_warehouses sw ON sw.company_id=sa.company_id AND sw.warehouse_id=p.source_warehouse_id
            JOIN inventory_warehouses dw ON dw.company_id=da.company_id AND dw.warehouse_id=p.destination_warehouse_id
            JOIN users u ON u.user_id=p.proposed_by
            JOIN users owner ON owner.user_id=p.source_owner_user_id
            JOIN users receiver ON receiver.user_id=p.destination_owner_user_id
            LEFT JOIN inventory_stock_balances b ON b.company_id=sa.company_id AND b.warehouse_id=sa.warehouse_id AND b.location_id=sa.location_id AND b.product_id=l.product_id
            LEFT JOIN inventory_transfer_lines tl ON tl.company_id=p.company_id AND tl.transfer_line_id=p.transfer_line_id
            LEFT JOIN inventory_transfers t ON t.company_id=tl.company_id AND t.transfer_id=tl.transfer_id
            WHERE p.company_id=? AND $scope".($request?' AND p.request_id=?':'')." ORDER BY p.proposal_id DESC");
        $params=$scope==='1=1'?[$company]:[$company,$actor,$actor];
        if ($request) $params[]=$request;
        $s->execute($params); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            // A destination may inspect the invitation, never the sibling's stock balance.
            if ((int)$row['source_owner_user_id']!==$actor && !in_array((int)$row['source_owner_user_id'],$users,true)) $row['quantity_available']=null;
        }
        return $rows;
    }

    public function proposePeer(int $requestId,array $input,int $actor): void
    {
        $company=$this->tenant->companyId(); $c=\db(); $c->beginTransaction();
        try {
            $request=$this->requestForUpdate($c,$company,$requestId);
            if (!in_array($request['status'],['pending_review','awaiting_transfer'],true)) throw new RuntimeException('This request cannot accept a proposal.');
            $candidates=$this->peerCandidates($company,$actor,$request); $source=null;
            foreach ($candidates as $candidate) if ((int)$candidate['authority_id']===(int)($input['source_authority_id']??0)) $source=$candidate;
            if (!$source) throw new RuntimeException('Only the common upper manager can propose a sibling source.');
            $destination=$this->authorityById($company,(int)$request['serving_authority_id'],true);
            $quantity=round((float)($input['quantity']??0),3); $lineId=(int)($input['request_line_id']??0);
            if (!is_finite($quantity) || $quantity<=0) throw new RuntimeException('Enter a positive quantity.');
            $s=$c->prepare("SELECT proposal_id FROM inventory_peer_proposals WHERE company_id=? AND request_id=? AND request_line_id=? AND source_authority_id=? AND quantity=? AND state='proposed'");
            $s->execute([$company,$requestId,$lineId,$source['authority_id'],$quantity]);
            if ($s->fetchColumn()) { $c->commit(); return; }
            $valid=false; $product=0;
            foreach ($this->uncommittedLinesLocked($c,$company,$requestId) as $line) if ((int)$line['request_line_id']===$lineId && $quantity<=(float)$line['remaining_quantity']+0.0005) { $valid=true; $product=(int)$line['product_id']; }
            if (!$valid) throw new RuntimeException('The proposal exceeds the uncommitted remaining request quantity.');
            $number='PEER-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
            $s=$c->prepare('INSERT INTO inventory_peer_proposals(company_id,proposal_number,request_id,request_line_id,source_authority_id,destination_authority_id,proposed_by,source_owner_user_id,destination_owner_user_id,quantity,source_warehouse_id,source_location_id,destination_warehouse_id,destination_location_id,product_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$company,$number,$requestId,$lineId,$source['authority_id'],$destination['authority_id'],$actor,$source['user_id'],$destination['user_id'],$quantity,$source['warehouse_id'],$source['location_id'],$destination['warehouse_id'],$destination['location_id'],$product]);
            $id=(int)$c->lastInsertId();
            $this->audit($actor,'peer.proposed','inventory_peer_proposals',$id,['request_id'=>$requestId,'quantity'=>$quantity,'reference'=>$number,'source_authority_id'=>$source['authority_id'],'destination_authority_id'=>$destination['authority_id']]);
            $c->commit();
        } catch (Throwable $e) { if ($c->inTransaction()) $c->rollBack(); throw $e; }
    }

    public function decidePeer(int $id,string $decision,string $reason,int $actor): void
    {
        $company=$this->tenant->companyId(); $c=\db(); $c->beginTransaction();
        try {
            $s=$c->prepare('SELECT request_id FROM inventory_peer_proposals WHERE company_id=? AND proposal_id=?'); $s->execute([$company,$id]);
            $request=$this->requestForUpdate($c,$company,(int)$s->fetchColumn());
            $s=$c->prepare('SELECT * FROM inventory_peer_proposals WHERE company_id=? AND proposal_id=? FOR UPDATE'); $s->execute([$company,$id]); $p=$s->fetch(PDO::FETCH_ASSOC);
            if (!$p || $p['state']!=='proposed') throw new RuntimeException('Only an undecided proposal can be changed.');
            $source=$this->authorityById($company,(int)$p['source_authority_id'],true);
            $destination=$this->authorityById($company,(int)$p['destination_authority_id'],true);
            if (!$source || !$destination) throw new RuntimeException('The proposal requires active source and destination authorities.');
            if ($decision==='cancel') {
                if ((int)$p['proposed_by']!==$actor || !$this->actorCan($company,$actor,'inventory.stock_requests.process')) throw new RuntimeException('Only the proposing manager may cancel an undecided proposal.');
                $c->prepare("UPDATE inventory_peer_proposals SET state='cancelled',cancelled_by=?,cancelled_at=NOW() WHERE company_id=? AND proposal_id=?")->execute([$actor,$company,$id]);
            } else {
                if ((int)$source['user_id']!==$actor || (int)$p['source_owner_user_id']!==$actor || !$this->actorCan($company,$actor,'inventory.stock_requests.process')) throw new RuntimeException('Only the current source warehouse manager may decide this proposal.');
                $this->assertManagerAuthorityLevel($company,$actor,(string)$source['authority_level']);
                if (!in_array($decision,['approve','reject'],true)) throw new RuntimeException('Invalid source decision.');
                if ($decision==='reject' && trim($reason)==='') throw new RuntimeException('Enter a rejection reason.');
                if ($decision==='approve') {
                    $candidates=$this->peerCandidates($company,(int)$p['proposed_by'],$request);
                    if (!in_array((int)$source['authority_id'],array_map('intval',array_column($candidates,'authority_id')),true)) throw new RuntimeException('The common-manager relationship or responsible handler changed.');
                    $this->approvePeerLocked($c,$company,$request,$p,$source,$destination,$actor);
                }
                $c->prepare('UPDATE inventory_peer_proposals SET state=?,source_decided_by=?,source_decided_at=NOW(),rejection_reason=? WHERE company_id=? AND proposal_id=?')
                    ->execute([$decision==='approve'?'source_approved':'source_rejected',$actor,$decision==='reject'?mb_substr(trim($reason),0,1000):null,$company,$id]);
            }
            $this->audit($actor,'peer.'.$decision,'inventory_peer_proposals',$id,['request_id'=>$request['request_id'],'reason'=>trim($reason)]);
            $c->commit();
        } catch (Throwable $e) { if ($c->inTransaction()) $c->rollBack(); throw $e; }
    }

    private function approvePeerLocked(PDO $c,int $company,array $request,array $p,array $source,array $destination,int $actor): void
    {
        (new InventoryOperationalAccessService())->assertAuthorizedSource($company,$actor,(int)$source['warehouse_id'],(int)$source['location_id']);
        $line=null;
        foreach ($this->uncommittedLinesLocked($c,$company,(int)$request['request_id'],(int)$p['proposal_id']) as $l) if ((int)$l['request_line_id']===(int)$p['request_line_id']) $line=$l;
        $qty=(float)$p['quantity'];
        if (!$line || $qty>(float)$line['remaining_quantity']+0.0005) throw new RuntimeException('The proposal exceeds the remaining quantity.');
        $s=$c->prepare('SELECT * FROM inventory_stock_balances WHERE company_id=? AND warehouse_id=? AND location_id=? AND product_id=? FOR UPDATE');
        $s->execute([$company,$source['warehouse_id'],$source['location_id'],$line['product_id']]); $balance=$s->fetch(PDO::FETCH_ASSOC);
        if (!$balance || (float)$balance['quantity_available']+0.0005<$qty) throw new RuntimeException('The source has insufficient available stock. Reject this proposal and request a smaller one.');
        $s=$c->prepare("SELECT operation_type_id FROM inventory_operation_types WHERE company_id=? AND warehouse_id=? AND operation_kind='internal_transfer' AND active=TRUE AND is_default=TRUE");
        $s->execute([$company,$source['warehouse_id']]); $operation=(int)$s->fetchColumn();
        if (!$operation) throw new RuntimeException('The source needs a default internal transfer operation.');
        // Create the ordinary document as draft; this explicit source-owner decision
        // is the only transition that approves a peer transfer.
        $s=$c->prepare("INSERT INTO inventory_transfers(company_id,source_warehouse_id,destination_warehouse_id,operation_type_id,transfer_number,transfer_date,status,reason,created_by) VALUES(?,?,?,?,?,CURRENT_DATE,'draft',?,?)");
        $s->execute([$company,$source['warehouse_id'],$destination['warehouse_id'],$operation,'TRF-'.$p['proposal_number'],'Peer proposal '.$p['proposal_number'],$actor]); $transfer=(int)$c->lastInsertId();
        $s=$c->prepare('INSERT INTO inventory_transfer_lines(company_id,transfer_id,source_warehouse_id,source_location_id,destination_warehouse_id,destination_location_id,product_id,quantity,unit_cost) VALUES(?,?,?,?,?,?,?,?,?)');
        $s->execute([$company,$transfer,$source['warehouse_id'],$source['location_id'],$destination['warehouse_id'],$destination['location_id'],$line['product_id'],$qty,$balance['average_unit_cost']]); $transferLine=(int)$c->lastInsertId();
        $s=$c->prepare('UPDATE inventory_stock_balances SET quantity_reserved=quantity_reserved+?,version_number=version_number+1 WHERE company_id=? AND stock_balance_id=? AND quantity_available>=?');
        $s->execute([$qty,$company,$balance['stock_balance_id'],$qty]); if ($s->rowCount()!==1) throw new RuntimeException('Available stock changed.');
        $s=$c->prepare("INSERT INTO inventory_stock_request_allocations(company_id,request_id,request_line_id,authority_id,source_warehouse_id,source_location_id,destination_warehouse_id,destination_location_id,quantity,status,reserved_at,created_by,transfer_id,transfer_line_id) VALUES(?,?,?,?,?,?,?,?,?,'source_reserved',NOW(),?,?,?)");
        $s->execute([$company,$request['request_id'],$line['request_line_id'],$source['authority_id'],$source['warehouse_id'],$source['location_id'],$destination['warehouse_id'],$destination['location_id'],$qty,$actor,$transfer,$transferLine]);
        $c->prepare('UPDATE inventory_peer_proposals SET transfer_line_id=? WHERE company_id=? AND proposal_id=?')->execute([$transferLine,$company,$p['proposal_id']]);
        $c->prepare("UPDATE inventory_transfers SET status='approved',submitted_by=?,submitted_at=NOW(),approved_by=?,approved_at=NOW() WHERE company_id=? AND transfer_id=? AND status='draft'")->execute([$actor,$actor,$company,$transfer]);
        $this->refreshRequestStatusLocked($c,$company,(int)$request['request_id']);
    }

    /** Invoked inside the normal transfer transaction before and after stock accounting. */
    public function peerTransferTransition(int $company,int $transfer,int $actor,bool $receiving,bool $after=false): void
    {
        $c=\db();
        $s=$c->prepare('SELECT p.*,tl.quantity transfer_quantity,tl.dispatched_quantity,tl.received_quantity,tl.source_warehouse_id,tl.source_location_id,tl.destination_warehouse_id,tl.destination_location_id,
            sa.active source_active,da.active destination_active,sa.user_id current_source,sa.warehouse_id current_source_warehouse,sa.location_id current_source_location,
            da.user_id current_destination,da.warehouse_id current_destination_warehouse,da.location_id current_destination_location
            FROM inventory_peer_proposals p JOIN inventory_transfer_lines tl ON tl.company_id=p.company_id AND tl.transfer_line_id=p.transfer_line_id
            JOIN inventory_stock_authorities sa ON sa.company_id=p.company_id AND sa.authority_id=p.source_authority_id
            JOIN inventory_stock_authorities da ON da.company_id=p.company_id AND da.authority_id=p.destination_authority_id
            WHERE tl.company_id=? AND tl.transfer_id=? FOR UPDATE');
        $s->execute([$company,$transfer]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $p) {
            if (!$this->actorCan($company,$actor,$receiving?'inventory.transfers.receive':'inventory.transfers.dispatch')) throw new RuntimeException('Transfer operation is not permitted.');
            $expected=$receiving?'dispatched':'source_approved';
            if (empty($p['source_active']) || empty($p['destination_active']) || $p['state']!==$expected || (int)$p[$receiving?'current_destination':'current_source']!==$actor
                || (int)$p[$receiving?'destination_owner_user_id':'source_owner_user_id']!==$actor) throw new RuntimeException('Only the current peer warehouse manager may perform this transfer step.');
            foreach (['source','destination'] as $side) foreach (['warehouse','location'] as $kind) {
                if ((int)$p[$side.'_'.$kind.'_id']!==(int)$p['current_'.$side.'_'.$kind]) throw new RuntimeException('The peer warehouse route changed.');
            }
            if (abs((float)$p['quantity']-(float)$p['transfer_quantity'])>0.0005 || ($receiving && abs((float)$p['dispatched_quantity']-(float)$p['quantity'])>0.0005)) throw new RuntimeException('The transfer quantity must equal the source-approved quantity.');
            if ($after) {
                $state=$receiving?'completed':'dispatched';
                $c->prepare('UPDATE inventory_peer_proposals SET state=? WHERE company_id=? AND proposal_id=?')->execute([$state,$company,$p['proposal_id']]);
                $this->audit($actor,$receiving?'peer.received_completed':'peer.dispatched','inventory_peer_proposals',(int)$p['proposal_id'],['transfer_id'=>$transfer,'quantity'=>(float)$p['quantity'],'state'=>$state]);
            }
        }
    }
}
