<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InventoryRepository;
use App\Repositories\RepositoryFactory;
use RuntimeException;

final class InventoryService
{
    private InventoryRepository $inventory;
    private TenantContext $tenant;

    public function __construct(
        ?InventoryRepository $inventory = null,
        ?TenantContext $tenant = null
    ) {
        $this->inventory = $inventory
            ?? RepositoryFactory::inventory();

        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * Post an approved goods receipt into inventory exactly once.
     *
     * @return array<string, mixed>
     */
    public function postGoodsReceipt(
        int $goodsReceiptId,
        int $actorId
    ): array {
        if ($goodsReceiptId < 1) {
            throw new \InvalidArgumentException(
                'A valid goods receipt ID is required.'
            );
        }

        if ($actorId < 1) {
            throw new \InvalidArgumentException(
                'A valid actor ID is required.'
            );
        }

        try {
            if($this->receiptForActor($goodsReceiptId,$actorId)===null)throw new RuntimeException('Goods receipt was not found.');
            $companyId = $this->tenant->companyId();
            $result = $this->inventory->postGoodsReceipt(
                $companyId,
                $goodsReceiptId,
                $actorId,
                date('Y-m-d H:i:s')
            );

            $resumeWarning = null;
            try {
                (new StockRequestService())->resumeFromGoodsReceipt(
                    $companyId,
                    $goodsReceiptId,
                    $actorId
                );
            } catch (\Throwable $resumeException) {
                error_log('Stock request resume after goods receipt failed: ' . $resumeException->getMessage());
                $resumeWarning = 'Receipt posted, but the linked stock request could not be resumed automatically. Regional can recheck the request manually.';
            }

            return [
                'successful' => true,
                'result' => $result,
                'warning' => $resumeWarning,
            ];
        } catch (\Throwable $exception) {
            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
        }
    }

    public function receipts(): array{$actor=(int)($_SESSION['auth']['user_id']??0);return array_values(array_filter($this->inventory->goodsReceipts($this->tenant->companyId()),fn(array $row):bool=>$this->receiptForActor((int)$row['goods_receipt_id'],$actor)!==null));}
    public function receipt(int $id): ?array{return $this->receiptForActor($id,(int)($_SESSION['auth']['user_id']??0));}
    public function receiptOptions(): array
    {$company=$this->tenant->companyId();$actor=(int)($_SESSION['auth']['user_id']??0);$access=new InventoryOperationalAccessService();$p=\db()->prepare("SELECT product_id,sku,name,unit_of_measure FROM sales_products WHERE company_id=:company_id AND active=TRUE AND deleted_at IS NULL AND product_type NOT IN('service','fixed_asset') ORDER BY name");$p->execute(['company_id'=>$company]);return['warehouses'=>$access->warehousesForUser($company,$actor),'locations'=>$access->receivingLocationsForUser($company,$actor),'products'=>$p->fetchAll(\PDO::FETCH_ASSOC)];}
    public function createGoodsReceipt(array $input,int $actorId): array
    {$warehouse=(int)($input['warehouse_id']??0);$destination=(int)($input['destination_location_id']??0);$supplier=trim((string)($input['supplier_name']??''));$date=trim((string)($input['receipt_date']??''));$currency=strtoupper(trim((string)($input['currency']??'ETB')));$productIds=(array)($input['product_id']??[]);$quantities=(array)($input['quantity']??[]);$costs=(array)($input['unit_cost']??[]);$lines=[];foreach($productIds as $i=>$productId){$pid=(int)$productId;$qty=(float)($quantities[$i]??0);$cost=(float)($costs[$i]??0);if($pid>0&&$qty>0)$lines[]=['product_id'=>$pid,'quantity'=>$qty,'unit_cost'=>$cost,'notes'=>null];}if($warehouse<1||$destination<1||$supplier===''||preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)!==1||preg_match('/^[A-Z]{3}$/',$currency)!==1||$lines===[])return['successful'=>false,'errors'=>['form'=>'Warehouse, receiving location, supplier, receipt date, currency and at least one positive product quantity are required.']];try{(new InventoryOperationalAccessService())->assertAuthorizedDestination($this->tenant->companyId(),$actorId,$warehouse,$destination);$id=$this->inventory->createGoodsReceipt($this->tenant->companyId(),['warehouse_id'=>$warehouse,'destination_location_id'=>$destination,'supplier_name'=>$supplier,'supplier_reference'=>trim((string)($input['supplier_reference']??''))?:null,'receipt_date'=>$date,'currency'=>$currency,'notes'=>trim((string)($input['notes']??''))?:null],$lines,$actorId);return['successful'=>true,'id'=>$id];}catch(\Throwable $e){return['successful'=>false,'errors'=>['form'=>$e->getMessage()]];}}
    public function approveGoodsReceipt(int $id,int $actorId): array
    {try{if($this->receiptForActor($id,$actorId)===null)throw new RuntimeException('Goods receipt was not found.');$this->inventory->approveGoodsReceipt($this->tenant->companyId(),$id,$actorId,date('Y-m-d H:i:s'));return['successful'=>true,'id'=>$id];}catch(\Throwable $e){return['successful'=>false,'errors'=>['form'=>$e->getMessage()]];}}

    private function receiptForActor(int $id,int $actorId): ?array
    {$company=$this->tenant->companyId();$row=$this->inventory->goodsReceipt($company,$id);$access=new InventoryOperationalAccessService();return is_array($row)&&$access->canAccessRecord($company,$actorId,$row,'warehouse_id','destination_location_id')?$row:null;}

    /** @return array<string, mixed> */
    public function postTransfer(int $transferId, int $actorId): array
    {
        if ($transferId < 1 || $actorId < 1) {
            return [
                'successful' => false,
                'errors' => ['form' => 'A valid transfer and actor are required.'],
            ];
        }

        try {
            return [
                'successful' => true,
                'result' => $this->inventory->postTransfer(
                    $this->tenant->companyId(),
                    $transferId,
                    $actorId,
                    date('Y-m-d H:i:s')
                ),
            ];
        } catch (\Throwable $exception) {
            return [
                'successful' => false,
                'errors' => ['form' => $exception->getMessage()],
            ];
        }
    }

    /** @return array<string,mixed> */
    public function transferWorkspace(?int $transferId=null): array
    {
        $company=$this->tenant->companyId();$actor=(int)($_SESSION['auth']['user_id']??0);$access=new InventoryOperationalAccessService();$connection=\db();
        $list=$connection->prepare("SELECT t.*,sw.name source_warehouse_name,dw.name destination_warehouse_name,MAX(sl.name) source_location_name,MAX(dl.name) destination_location_name,COALESCE(SUM(l.quantity),0) requested_quantity,COALESCE(SUM(l.dispatched_quantity),0) dispatched_quantity,COALESCE(SUM(l.received_quantity),0) received_quantity,GROUP_CONCAT(CONCAT(p.name,' x ',l.quantity) ORDER BY l.transfer_line_id SEPARATOR ', ') product_summary FROM inventory_transfers t INNER JOIN inventory_warehouses sw ON sw.company_id=t.company_id AND sw.warehouse_id=t.source_warehouse_id INNER JOIN inventory_warehouses dw ON dw.company_id=t.company_id AND dw.warehouse_id=t.destination_warehouse_id LEFT JOIN inventory_transfer_lines l ON l.company_id=t.company_id AND l.transfer_id=t.transfer_id LEFT JOIN inventory_warehouse_locations sl ON sl.company_id=l.company_id AND sl.warehouse_id=l.source_warehouse_id AND sl.location_id=l.source_location_id LEFT JOIN inventory_warehouse_locations dl ON dl.company_id=l.company_id AND dl.warehouse_id=l.destination_warehouse_id AND dl.location_id=l.destination_location_id LEFT JOIN sales_products p ON p.company_id=l.company_id AND p.product_id=l.product_id WHERE t.company_id=:company_id GROUP BY t.transfer_id ORDER BY t.transfer_id DESC");$list->execute(['company_id'=>$company]);
        $products=$connection->prepare("SELECT product_id,sku,name FROM sales_products WHERE company_id=:company_id AND active=TRUE AND deleted_at IS NULL AND product_type NOT IN('service','fixed_asset') ORDER BY name");$products->execute(['company_id'=>$company]);
        $data=['transfers'=>$list->fetchAll(\PDO::FETCH_ASSOC),'warehouses'=>$access->warehousesForUser($company,$actor),'locations'=>$access->locationsForUser($company,$actor),'products'=>$products->fetchAll(\PDO::FETCH_ASSOC),'transfer'=>null];
        if($transferId!==null){foreach($data['transfers'] as $row)if((int)$row['transfer_id']===$transferId){$data['transfer']=$row;break;}if(is_array($data['transfer'])){$lines=$connection->prepare("SELECT l.*,p.sku,p.name product_name,b.quantity_on_hand,b.quantity_reserved,b.quantity_available FROM inventory_transfer_lines l INNER JOIN sales_products p ON p.company_id=l.company_id AND p.product_id=l.product_id LEFT JOIN inventory_stock_balances b ON b.company_id=l.company_id AND b.warehouse_id=l.source_warehouse_id AND b.location_id=l.source_location_id AND b.product_id=l.product_id WHERE l.company_id=:company_id AND l.transfer_id=:transfer_id ORDER BY l.transfer_line_id");$lines->execute(['company_id'=>$company,'transfer_id'=>$transferId]);$data['transfer']['lines']=$lines->fetchAll(\PDO::FETCH_ASSOC);}}
        return $data;
    }

    public function createTransfer(array $input,int $actorId): int
    {
        $company=$this->tenant->companyId();$sourceWarehouse=(int)($input['source_warehouse_id']??0);$sourceLocation=(int)($input['source_location_id']??0);$destinationWarehouse=(int)($input['destination_warehouse_id']??0);$destinationLocation=(int)($input['destination_location_id']??0);$reason=trim((string)($input['reason']??''));$productIds=(array)($input['product_id']??[]);$quantities=(array)($input['quantity']??[]);if($reason===''||min($sourceWarehouse,$sourceLocation,$destinationWarehouse,$destinationLocation)<1)throw new RuntimeException('Source, destination and transfer reason are required.');if($sourceWarehouse===$destinationWarehouse&&$sourceLocation===$destinationLocation)throw new RuntimeException('Source and destination locations must differ.');$access=new InventoryOperationalAccessService();$access->assertAuthorizedSource($company,$actorId,$sourceWarehouse,$sourceLocation);$access->assertAuthorizedTransferDestination($company,$actorId,$destinationWarehouse,$destinationLocation);$lines=[];foreach($productIds as $index=>$productId){$product=(int)$productId;$quantity=round((float)($quantities[$index]??0),3);if($product>0&&$quantity>0)$lines[]=['product_id'=>$product,'quantity'=>$quantity];}if($lines===[])throw new RuntimeException('At least one positive product quantity is required.');$availability=$access->availability($company,$actorId,$sourceWarehouse,$sourceLocation,array_column($lines,'product_id'));foreach($lines as $line){$available=(float)($availability[$line['product_id']]['quantity_available']??0);$negative=!empty($availability[$line['product_id']]['allow_negative_stock']);if(!$negative&&$line['quantity']>$available+0.0005)throw new RuntimeException('Transfer quantity exceeds exact source-location available stock.');}
        $connection=\db();$connection->beginTransaction();try{$operation=$connection->prepare("SELECT operation_type_id FROM inventory_operation_types WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND operation_kind='internal_transfer' AND active=TRUE AND is_default=TRUE LIMIT 1");$operation->execute(['company_id'=>$company,'warehouse_id'=>$sourceWarehouse]);$operationId=(int)$operation->fetchColumn();if($operationId<1)throw new RuntimeException('The source warehouse internal-transfer operation is not configured.');$number='TRF-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));$header=$connection->prepare("INSERT INTO inventory_transfers(company_id,source_warehouse_id,destination_warehouse_id,operation_type_id,transfer_number,transfer_date,status,notes,reason,created_by) VALUES(:company_id,:source_warehouse_id,:destination_warehouse_id,:operation_type_id,:number,CURRENT_DATE,'draft',:notes,:reason,:actor)");$header->execute(['company_id'=>$company,'source_warehouse_id'=>$sourceWarehouse,'destination_warehouse_id'=>$destinationWarehouse,'operation_type_id'=>$operationId,'number'=>$number,'notes'=>trim((string)($input['notes']??''))?:null,'reason'=>$reason,'actor'=>$actorId]);$id=(int)$connection->lastInsertId();$insert=$connection->prepare("INSERT INTO inventory_transfer_lines(company_id,transfer_id,source_warehouse_id,source_location_id,destination_warehouse_id,destination_location_id,product_id,quantity,unit_cost,notes) VALUES(:company_id,:transfer_id,:source_warehouse_id,:source_location_id,:destination_warehouse_id,:destination_location_id,:product_id,:quantity,:unit_cost,:notes)");foreach($lines as $line){$unitCost=(float)($availability[$line['product_id']]['average_unit_cost']??0);$insert->execute(['company_id'=>$company,'transfer_id'=>$id,'source_warehouse_id'=>$sourceWarehouse,'source_location_id'=>$sourceLocation,'destination_warehouse_id'=>$destinationWarehouse,'destination_location_id'=>$destinationLocation,'product_id'=>$line['product_id'],'quantity'=>$line['quantity'],'unit_cost'=>$unitCost,'notes'=>$reason]);}$connection->commit();$this->auditTransfer($actorId,'CREATE',$id,[],['status'=>'draft']);return $id;}catch(\Throwable $e){if($connection->inTransaction())$connection->rollBack();throw $e;}
    }

    public function transitionTransfer(int $transferId,string $action,int $actorId): void
    {
        $company=$this->tenant->companyId();
        $map=[
            'submit'=>['draft','submitted','submitted_by','submitted_at'],
            'approve'=>['submitted','approved','approved_by','approved_at'],
            'cancel'=>[['draft','submitted','approved'],'cancelled','cancelled_by','cancelled_at'],
        ];
        if(!isset($map[$action]))throw new RuntimeException('Invalid transfer transition.');
        $connection=\db();
        $scope=$connection->prepare('SELECT source_warehouse_id,source_location_id,destination_warehouse_id,destination_location_id FROM inventory_transfer_lines WHERE company_id=:company_id AND transfer_id=:transfer_id LIMIT 1');
        $scope->execute(['company_id'=>$company,'transfer_id'=>$transferId]);
        $route=$scope->fetch(\PDO::FETCH_ASSOC);
        if(!is_array($route))throw new RuntimeException('The transfer has no valid route.');
        $access=new InventoryOperationalAccessService();
        $access->assertAuthorizedSource($company,$actorId,(int)$route['source_warehouse_id'],(int)$route['source_location_id']);
        $access->assertAuthorizedTransferDestination($company,$actorId,(int)$route['destination_warehouse_id'],(int)$route['destination_location_id']);
        $spec=$map[$action];$from=$spec[0];$to=$spec[1];$column=$spec[2];$time=$spec[3];
        $owns=!$connection->inTransaction();
        try{
            if($owns)$connection->beginTransaction();
            $where=is_array($from)?"status IN('draft','submitted','approved')":'status=:from_status';
            $sql="UPDATE inventory_transfers SET status=:to_status,$column=:actor,$time=NOW() WHERE company_id=:company_id AND transfer_id=:transfer_id AND $where".($action==='approve'?' AND created_by<>:maker':'');
            $statement=$connection->prepare($sql);
            $parameters=['to_status'=>$to,'actor'=>$actorId,'company_id'=>$company,'transfer_id'=>$transferId];
            if(!is_array($from))$parameters['from_status']=$from;
            if($action==='approve')$parameters['maker']=$actorId;
            $statement->execute($parameters);
            if($statement->rowCount()!==1)throw new RuntimeException('The transfer transition is stale, unsafe, or violates maker/checker separation.');
            if($action==='cancel'){
                (new StockRequestService())->onTransferCancelled($company,$transferId);
            }
            if($owns)$connection->commit();
            $this->auditTransfer($actorId,strtoupper($action),$transferId,['status'=>$from],['status'=>$to]);
        }catch(\Throwable $e){
            if($owns&&$connection->inTransaction())$connection->rollBack();
            throw $e;
        }
    }

    public function dispatchTransfer(int $transferId,int $actorId): array { return $this->moveTransfer($transferId,$actorId,false); }
    public function receiveTransfer(int $transferId,int $actorId): array { return $this->moveTransfer($transferId,$actorId,true); }

    /** @return array<string,mixed> */
    private function moveTransfer(int $transferId,int $actorId,bool $receiving): array
    {
        $company=$this->tenant->companyId();$connection=\db();$connection->beginTransaction();try{$header=$connection->prepare('SELECT * FROM inventory_transfers WHERE company_id=:company_id AND transfer_id=:transfer_id FOR UPDATE');$header->execute(['company_id'=>$company,'transfer_id'=>$transferId]);$transfer=$header->fetch(\PDO::FETCH_ASSOC);$required=$receiving?'in_transit':'approved';$done=$receiving?'done':'in_transit';if(!is_array($transfer)||(string)$transfer['status']!==$required)throw new RuntimeException($receiving?'Only an in-transit transfer can be received.':'Only an approved transfer can be dispatched.');$lines=$connection->prepare('SELECT * FROM inventory_transfer_lines WHERE company_id=:company_id AND transfer_id=:transfer_id ORDER BY transfer_line_id FOR UPDATE');$lines->execute(['company_id'=>$company,'transfer_id'=>$transferId]);$rows=$lines->fetchAll(\PDO::FETCH_ASSOC);if($rows===[])throw new RuntimeException('The transfer has no lines.');$stockRequests=new StockRequestService();if(!$receiving)$stockRequests->beforeTransferDispatch($company,$transferId);$access=new InventoryOperationalAccessService();$movementCount=0;foreach($rows as $line){if($receiving)$access->assertAuthorizedTransferDestination($company,$actorId,(int)$line['destination_warehouse_id'],(int)$line['destination_location_id']);else $access->assertAuthorizedSource($company,$actorId,(int)$line['source_warehouse_id'],(int)$line['source_location_id']);$transit=$connection->prepare("SELECT location_id FROM inventory_warehouse_locations WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND location_usage='transit' AND active=TRUE AND deleted_at IS NULL ORDER BY location_id LIMIT 1");$transit->execute(['company_id'=>$company,'warehouse_id'=>$line['source_warehouse_id']]);$transitLocation=(int)$transit->fetchColumn();if($transitLocation<1)throw new RuntimeException('The source warehouse has no active in-transit location.');$quantity=$receiving?(float)$line['dispatched_quantity']:(float)$line['quantity'];$result=$this->inventory->completeStockMovement(['companyId'=>$company,'productId'=>(int)$line['product_id'],'sourceWarehouseId'=>(int)$line['source_warehouse_id'],'sourceLocationId'=>$receiving?$transitLocation:(int)$line['source_location_id'],'destinationWarehouseId'=>$receiving?(int)$line['destination_warehouse_id']:(int)$line['source_warehouse_id'],'destinationLocationId'=>$receiving?(int)$line['destination_location_id']:$transitLocation,'quantity'=>$quantity,'unitCost'=>(float)$line['unit_cost'],'movementType'=>$receiving?'transfer_in':'transfer_out','operationTypeId'=>(int)$transfer['operation_type_id'],'currency'=>(string)($_SESSION['auth']['company']['default_currency']??'ETB'),'referenceType'=>'inventory_transfer','referenceId'=>$transferId,'referenceNumber'=>$transfer['transfer_number'],'idempotencyKey'=>sprintf('inventory-transfer:%d:line:%d:%s',$transferId,$line['transfer_line_id'],$receiving?'receive':'dispatch'),'notes'=>$transfer['reason']??$transfer['notes'],'occurredAt'=>date('Y-m-d H:i:s'),'actorId'=>$actorId]);if(empty($result['replayed']))$movementCount++;$column=$receiving?'received_quantity':'dispatched_quantity';$connection->prepare("UPDATE inventory_transfer_lines SET $column=:quantity WHERE company_id=:company_id AND transfer_line_id=:line_id")->execute(['quantity'=>$quantity,'company_id'=>$company,'line_id'=>$line['transfer_line_id']]);}$actorColumn=$receiving?'received_by':'dispatched_by';$timeColumn=$receiving?'received_at':'dispatched_at';$connection->prepare("UPDATE inventory_transfers SET status=:status,$actorColumn=:actor,$timeColumn=NOW(),posted_by=IF(:is_receiving=1,:actor_two,posted_by),posted_at=IF(:is_receiving_two=1,NOW(),posted_at) WHERE company_id=:company_id AND transfer_id=:transfer_id AND status=:required")->execute(['status'=>$done,'actor'=>$actorId,'is_receiving'=>$receiving?1:0,'actor_two'=>$actorId,'is_receiving_two'=>$receiving?1:0,'company_id'=>$company,'transfer_id'=>$transferId,'required'=>$required]);if($receiving)$stockRequests->afterTransferReceive($company,$transferId);else $stockRequests->afterTransferDispatch($company,$transferId);$connection->commit();$this->auditTransfer($actorId,$receiving?'RECEIVE':'DISPATCH',$transferId,['status'=>$required],['status'=>$done]);return['transferId'=>$transferId,'status'=>$done,'movementCount'=>$movementCount];}catch(\Throwable $e){if($connection->inTransaction())$connection->rollBack();throw $e;}
    }

    private function auditTransfer(int $actorId,string $action,int $transferId,array $before,array $after): void { (new \App\Models\AuditLog())->record($actorId,$action,'inventory','inventory_transfers',(string)$transferId,$before,$after); }

    /** @return array<string,mixed> */
    public function capitalizeAssetStock(int $warehouseId,int $locationId,int $productId,float $quantity,int $assetId,string $assetNumber,int $actorId): array
    {
        if($warehouseId<1||$locationId<1||$productId<1||$quantity<=0||$assetId<1||$actorId<1)throw new RuntimeException('Valid source stock, asset, quantity and actor are required.');
        return $this->inventory->completeStockMovement([
            'companyId'=>$this->tenant->companyId(),'sourceWarehouseId'=>$warehouseId,'sourceLocationId'=>$locationId,
            'destinationWarehouseId'=>null,'destinationLocationId'=>null,'productId'=>$productId,'quantity'=>$quantity,
            'movementType'=>'issue','currency'=>(string)($_SESSION['auth']['company']['default_currency']??'ETB'),
            'referenceType'=>'asset_capitalization','referenceId'=>$assetId,'referenceNumber'=>$assetNumber,
            'idempotencyKey'=>'asset-capitalization-'.$this->tenant->companyId().'-'.$assetId,
            'notes'=>'Stock issued to Internal Asset Use','occurredAt'=>date('Y-m-d H:i:s'),'actorId'=>$actorId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function workspace(): array
    {
        $companyId = $this->tenant->companyId();
        $connection = \db();

        $summary = $connection->prepare(
            "SELECT
                (
                    SELECT COUNT(*)
                    FROM inventory_warehouses
                    WHERE company_id = :warehouse_company
                ) AS warehouse_count,
                (
                    SELECT COUNT(*)
                    FROM inventory_stock_balances balances
                    INNER JOIN inventory_warehouse_locations locations
                      ON locations.company_id = balances.company_id
                     AND locations.warehouse_id = balances.warehouse_id
                     AND locations.location_id = balances.location_id
                    WHERE balances.company_id = :balance_company
                      AND locations.location_usage IN ('internal', 'transit')
                ) AS stock_item_count,
                (
                    SELECT COALESCE(
                        SUM(quantity_on_hand),
                        0
                    )
                    FROM inventory_stock_balances balances
                    INNER JOIN inventory_warehouse_locations locations
                      ON locations.company_id = balances.company_id
                     AND locations.warehouse_id = balances.warehouse_id
                     AND locations.location_id = balances.location_id
                    WHERE balances.company_id = :quantity_company
                      AND locations.location_usage IN ('internal', 'transit')
                ) AS total_quantity,
                (
                    SELECT COUNT(*)
                    FROM inventory_goods_receipts
                    WHERE company_id = :receipt_company
                      AND status <> 'posted'
                ) AS pending_receipt_count"
        );

        $summary->execute([
            'warehouse_company' => $companyId,
            'balance_company' => $companyId,
            'quantity_company' => $companyId,
            'receipt_company' => $companyId,
        ]);

        $summaryRow = $summary->fetch(
            \PDO::FETCH_ASSOC
        );

        if (!is_array($summaryRow)) {
            $summaryRow = [];
        }

        $stock = $connection->prepare(
            "SELECT
                balances.stock_balance_id,
                balances.warehouse_id,
                balances.location_id,
                balances.product_id,
                products.sku,
                products.name AS product_name,
                balances.quantity_on_hand,
                balances.quantity_reserved,
                (
                    balances.quantity_on_hand
                    - balances.quantity_reserved
                ) AS quantity_available,
                balances.average_unit_cost,
                balances.last_movement_at,
                warehouses.name AS warehouse_name,
                locations.name AS location_name
             FROM inventory_stock_balances balances
             LEFT JOIN sales_products products
                ON products.company_id =
                    balances.company_id
               AND products.product_id =
                    balances.product_id
             LEFT JOIN inventory_warehouses warehouses
                ON warehouses.company_id = balances.company_id
               AND warehouses.warehouse_id = balances.warehouse_id
             LEFT JOIN inventory_warehouse_locations locations
                ON locations.company_id = balances.company_id
               AND locations.warehouse_id = balances.warehouse_id
               AND locations.location_id = balances.location_id
             WHERE balances.company_id =
                :company_id
               AND locations.location_usage IN ('internal', 'transit')
             ORDER BY
                products.name,
                balances.stock_balance_id
             LIMIT 100"
        );

        $stock->execute([
            'company_id' => $companyId,
        ]);

        $receipts = $connection->prepare(
            "SELECT
                goods_receipt_id,
                receipt_number,
                status,
                currency,
                receipt_date,
                posted_at
             FROM inventory_goods_receipts
             WHERE company_id = :company_id
             ORDER BY goods_receipt_id DESC
             LIMIT 25"
        );

        $receipts->execute([
            'company_id' => $companyId,
        ]);

        $movements = $connection->prepare(
            "SELECT
                movements.movement_id,
                movements.reference_number,
                movements.reference_type,
                movements.reference_id,
                movements.movement_type,
                movements.requested_quantity,
                movements.completed_quantity,
                movements.status,
                movements.occurred_at,
                products.sku,
                products.name AS product_name,
                source_locations.name AS source_location_name,
                destination_locations.name AS destination_location_name,
                operation_types.name AS operation_type_name
             FROM inventory_stock_movements movements
             INNER JOIN sales_products products
                ON products.company_id = movements.company_id
               AND products.product_id = movements.product_id
             LEFT JOIN inventory_warehouse_locations source_locations
                ON source_locations.company_id = movements.company_id
               AND source_locations.warehouse_id = movements.source_warehouse_id
               AND source_locations.location_id = movements.source_location_id
             LEFT JOIN inventory_warehouse_locations destination_locations
                ON destination_locations.company_id = movements.company_id
               AND destination_locations.warehouse_id = movements.destination_warehouse_id
               AND destination_locations.location_id = movements.destination_location_id
             LEFT JOIN inventory_operation_types operation_types
                ON operation_types.company_id = movements.company_id
               AND operation_types.warehouse_id = movements.warehouse_id
               AND operation_types.operation_type_id = movements.operation_type_id
             WHERE movements.company_id = :company_id
             ORDER BY movements.movement_id DESC
             LIMIT 50"
        );
        $movements->execute(['company_id' => $companyId]);

        return [
            'inventorySummary' => [
                'warehouseCount' => (int) (
                    $summaryRow['warehouse_count'] ?? 0
                ),
                'stockItemCount' => (int) (
                    $summaryRow['stock_item_count'] ?? 0
                ),
                'totalQuantity' => (float) (
                    $summaryRow['total_quantity'] ?? 0
                ),
                'pendingReceiptCount' => (int) (
                    $summaryRow[
                        'pending_receipt_count'
                    ] ?? 0
                ),
            ],
            'stockBalances' => $stock->fetchAll(
                \PDO::FETCH_ASSOC
            ),
            'goodsReceipts' => $receipts->fetchAll(
                \PDO::FETCH_ASSOC
            ),
            'stockMovements' => $movements->fetchAll(\PDO::FETCH_ASSOC),
        ];
    }
}
