<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Operational cash-float and Safaricom claims; deliberately never posts Finance GL. */
final class SalesIncentiveService
{
    private function company(): int { return (new TenantContext())->companyId(); }
    private function permit(int $company,int $actor,string $permission): void
    {
        if(!(new ModuleRoleService())->permissionAllowed($company,$actor,$permission))throw new RuntimeException('Sales incentive permission is required.');
    }
    private function money(mixed $raw): float
    {
        if(!is_scalar($raw)||!is_numeric($raw))throw new RuntimeException('Enter a valid amount.');
        $value=(float)$raw;
        if(!is_finite($value)||round($value,2)!==$value||$value<=0||$value>9999999999999999.99)throw new RuntimeException('Enter a positive amount with at most two decimals.');
        return $value;
    }
    private function directManager(int $company,int $dsa,int $manager): void
    {
        $scope=new SalesHierarchyScope();
        if(!$scope->isAgent($company,$dsa)||$scope->parentId($company,$dsa)!==$manager||!(new ModuleRoleService())->permissionAllowed($company,$manager,'sales.incentive.approve'))throw new RuntimeException('Only the DSA/DSP direct authorized manager may perform this action.');
    }

    public function issueFloat(array $input,int $actor): int
    {
        $company=$this->company();$this->permit($company,$actor,'sales.incentive.approve');
        $dsa=(int)($input['dsa_dsp_user_id']??0);$this->directManager($company,$dsa,$actor);
        $amount=$this->money($input['amount']??null);$reference=trim((string)($input['reference']??''));$date=trim((string)($input['issued_date']??''));
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date||$reference===''||strlen($reference)>120)throw new RuntimeException('Enter an issuance date and unique reference.');
        $currency=\db()->prepare('SELECT default_currency FROM companies WHERE company_id=?');$currency->execute([$company]);$code=strtoupper((string)$currency->fetchColumn());
        if(preg_match('/^[A-Z]{3}$/',$code)!==1)throw new RuntimeException('Company currency is not configured.');
        $insert=\db()->prepare("INSERT INTO sales_dsa_float_issuances(company_id,dsa_dsp_user_id,manager_user_id,amount,currency,issued_date,reference,status,created_by,created_at) VALUES(?,?,?,?,?,?,?,'issued',?,NOW())");
        $insert->execute([$company,$dsa,$actor,$amount,$code,$date,$reference,$actor]);return (int)\db()->lastInsertId();
    }

    public function submitClaim(array $input,int $actor): int
    {
        $company=$this->company();$this->permit($company,$actor,'sales.incentive.submit');
        if(!(new SalesHierarchyScope())->isAgent($company,$actor))throw new RuntimeException('Only a DSA/DSP may submit their incentive claim.');
        $reportId=(int)($input['report_id']??0);$floatId=(int)($input['float_id']??0);$proposed=$this->money($input['proposed_amount']??null);
        $externalReference=trim((string)($input['external_reference']??''))?:null;
        if($externalReference!==null&&strlen($externalReference)>190)throw new RuntimeException('Safaricom reference is too long.');
        $connection=\db();$connection->beginTransaction();
        try{
            $report=$this->report($connection,$company,$reportId);
            if((int)$report['dsa_dsp_user_id']!==$actor)throw new RuntimeException('Only the reporting DSA/DSP may claim incentive.');
            $manager=(new SalesHierarchyScope())->parentId($company,$actor);
            if($manager===null)$this->directManager($company,$actor,0);
            $this->directManager($company,$actor,(int)$manager);
            $floatQuery=$connection->prepare('SELECT * FROM sales_dsa_float_issuances WHERE company_id=? AND float_id=? FOR UPDATE');$floatQuery->execute([$company,$floatId]);$float=$floatQuery->fetch(PDO::FETCH_ASSOC);
            if(!$float||$float['status']!=='issued'||(int)$float['dsa_dsp_user_id']!==$actor||(int)$float['manager_user_id']!==(int)$manager||$float['currency']!==$report['currency'])throw new RuntimeException('Choose your issued cash float in the same currency and manager scope.');
            if($proposed>round((float)$float['amount']-(float)$report['sold_amount'],2))throw new RuntimeException('Proposed incentive exceeds the float less confirmed sold amount.');
            $duplicate=$connection->prepare('SELECT COUNT(*) FROM sales_incentive_claims WHERE company_id=? AND (originating_report_id=? OR float_id=?)');$duplicate->execute([$company,$reportId,$floatId]);
            if((int)$duplicate->fetchColumn()>0)throw new RuntimeException('The report or float is already linked to an active incentive claim.');
            $insert=$connection->prepare("INSERT INTO sales_incentive_claims(company_id,originating_report_id,float_id,dsa_dsp_user_id,responsible_manager_id,currency,proposed_amount,external_body,external_reference,status,submitted_by,submitted_at) VALUES(?,?,?,?,?,?,?,'Safaricom',?,'submitted',?,NOW())");
            $insert->execute([$company,$reportId,$floatId,$actor,$manager,$report['currency'],$proposed,$externalReference,$actor]);
            $id=(int)$connection->lastInsertId();$this->event($connection,$company,$id,'submitted',null,'submitted',$actor,$externalReference);
            (new UserNotificationService($connection))->notify($company,(int)$manager,'sales.incentive.review','Incentive approval required','Review the Safaricom incentive proposed by your DSA/DSP.','sales_incentive_claim',$id,'/sales/incentives/'.$id,'sales-incentive:'.$id.':review');
            $connection->commit();return $id;
        }catch(\Throwable $e){if($connection->inTransaction())$connection->rollBack();throw $e;}
    }

    public function decideClaim(int $id,bool $approve,mixed $amountRaw,string $reason,int $actor): void
    {
        $company=$this->company();$this->permit($company,$actor,'sales.incentive.approve');$reason=trim($reason);
        if(!$approve&&$reason==='')throw new RuntimeException('A rejection reason is required.');
        $connection=\db();$connection->beginTransaction();
        try{
            $query=$connection->prepare('SELECT * FROM sales_incentive_claims WHERE company_id=? AND incentive_claim_id=? FOR UPDATE');$query->execute([$company,$id]);$claim=$query->fetch(PDO::FETCH_ASSOC);
            if(!$claim||$claim['status']!=='submitted')throw new RuntimeException('The claim is not awaiting review.');
            if((int)$claim['submitted_by']===$actor)throw new RuntimeException('Maker and checker must differ.');
            $this->directManager($company,(int)$claim['dsa_dsp_user_id'],$actor);
            if((int)$claim['responsible_manager_id']!==$actor)throw new RuntimeException('Only the responsible manager may decide this claim.');
            if($approve){
                $amount=$this->money($amountRaw);
                if($amount>(float)$claim['proposed_amount'])throw new RuntimeException('Approved incentive cannot exceed the proposed amount.');
                $connection->prepare("UPDATE sales_incentive_claims SET status='approved',approved_amount=?,approved_by=?,approved_at=NOW() WHERE company_id=? AND incentive_claim_id=? AND status='submitted'")->execute([$amount,$actor,$company,$id]);
                $this->event($connection,$company,$id,'approved','submitted','approved',$actor,$reason?:null);
            }else{
                $connection->prepare("UPDATE sales_incentive_claims SET status='rejected',rejected_by=?,rejected_at=NOW(),rejection_reason=? WHERE company_id=? AND incentive_claim_id=? AND status='submitted'")->execute([$actor,$reason,$company,$id]);
                $this->event($connection,$company,$id,'rejected','submitted','rejected',$actor,$reason);
            }
            (new UserNotificationService($connection))->notify($company,(int)$claim['dsa_dsp_user_id'],$approve?'sales.incentive.approved':'sales.incentive.rejected',$approve?'Safaricom incentive approved':'Safaricom incentive rejected',$approve?'The approved amount now reduces your unexplained float variance.':$reason,'sales_incentive_claim',$id,'/sales/incentives/'.$id,'sales-incentive:'.$id.':'.($approve?'approved':'rejected'));
            $connection->commit();
        }catch(\Throwable $e){if($connection->inTransaction())$connection->rollBack();throw $e;}
    }

    public function settle(int $id,array $input,int $actor): void
    {
        $company=$this->company();$this->permit($company,$actor,'sales.incentive.settle');
        $amount=$this->money($input['amount']??null);$date=trim((string)($input['settlement_date']??''));$reference=trim((string)($input['external_payment_reference']??''));$evidence=trim((string)($input['evidence_reference']??''))?:null;
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date||$reference===''||strlen($reference)>190||($evidence!==null&&strlen($evidence)>500))throw new RuntimeException('Enter a valid Safaricom settlement date and unique payment reference.');
        $connection=\db();$connection->beginTransaction();
        try{
            $query=$connection->prepare('SELECT * FROM sales_incentive_claims WHERE company_id=? AND incentive_claim_id=? FOR UPDATE');$query->execute([$company,$id]);$claim=$query->fetch(PDO::FETCH_ASSOC);
            if(!$claim||!in_array($claim['status'],['approved','partially_settled'],true)||$claim['external_body']!=='Safaricom')throw new RuntimeException('Only an approved outstanding Safaricom claim may be settled.');
            $currency=strtoupper(trim((string)($input['currency']??'')));
            if($currency!==$claim['currency'])throw new RuntimeException('Settlement currency must match the approved claim.');
            $paid=$connection->prepare('SELECT COALESCE(SUM(amount),0) FROM sales_incentive_settlements WHERE company_id=? AND incentive_claim_id=? AND reversed_at IS NULL');$paid->execute([$company,$id]);
            $remaining=round((float)$claim['approved_amount']-(float)$paid->fetchColumn(),2);
            if($amount>$remaining)throw new RuntimeException('Safaricom settlement exceeds the derived outstanding amount.');
            $idempotency=hash('sha256',$company.'|Safaricom|'.$reference);
            $insert=$connection->prepare("INSERT INTO sales_incentive_settlements(company_id,incentive_claim_id,settlement_date,amount,currency,external_body,external_payment_reference,evidence_reference,idempotency_key,entered_by,created_at) VALUES(?,?,?,?,?,'Safaricom',?,?,?,?,NOW())");
            $insert->execute([$company,$id,$date,$amount,$currency,$reference,$evidence,$idempotency,$actor]);
            $newStatus=round($remaining-$amount,2)<=0?'settled':'partially_settled';
            $connection->prepare('UPDATE sales_incentive_claims SET status=? WHERE company_id=? AND incentive_claim_id=?')->execute([$newStatus,$company,$id]);
            $this->event($connection,$company,$id,'settlement_recorded',$claim['status'],$newStatus,$actor,$reference);
            $connection->commit();
        }catch(\Throwable $e){if($connection->inTransaction())$connection->rollBack();throw $e;}
    }

    public function register(int $actor,array $filters=[]): array
    {
        $company=$this->company();$this->permit($company,$actor,'sales.incentive.view');
        $scope=new SalesHierarchyScope();$visible=$scope->hasCompanyWideAccess($company,$actor)?null:$scope->userIds($company,$actor);
        if($visible===[])return ['claims'=>[],'floats'=>[],'reports'=>[],'users'=>[]];
        $params=[$company];$where=['c.company_id=?'];
        if($visible!==null){$where[]='c.dsa_dsp_user_id IN ('.implode(',',array_fill(0,count($visible),'?')).')';$params=array_merge($params,$visible);}
        $status=(string)($filters['status']??'');
        if(in_array($status,['outstanding','partially_settled','settled'],true)){
            $where[]=$status==='outstanding'?"c.status='approved'":"c.status=?";
            if($status!=='outstanding')$params[]=$status;
        }
        foreach(['dsa_dsp_user_id','responsible_manager_id'] as $key){if((int)($filters[$key]??0)>0){$where[]='c.'.$key.'=?';$params[]=(int)$filters[$key];}}
        $date=(string)($filters['date']??'');if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){ $where[]='DATE(c.submitted_at)=?';$params[]=$date; }
        $reference=trim((string)($filters['safaricom_reference']??''));if($reference!==''){ $where[]='(c.external_reference LIKE ? OR EXISTS(SELECT 1 FROM sales_incentive_settlements sx WHERE sx.company_id=c.company_id AND sx.incentive_claim_id=c.incentive_claim_id AND sx.external_payment_reference LIKE ?))';$params[]='%'.$reference.'%';$params[]='%'.$reference.'%'; }
        $sql='SELECT c.*,f.amount float_amount,f.reference float_reference,f.issued_date,u.display_name dsa_name,m.display_name manager_name,COALESCE((SELECT SUM(l.total_amount) FROM finance_invoice_lines l WHERE l.company_id=r.company_id AND l.invoice_id=r.finance_invoice_id),0) sold_amount,COALESCE((SELECT SUM(s.amount) FROM sales_incentive_settlements s WHERE s.company_id=c.company_id AND s.incentive_claim_id=c.incentive_claim_id AND s.reversed_at IS NULL),0) settled_amount FROM sales_incentive_claims c JOIN sales_dsa_float_issuances f ON f.company_id=c.company_id AND f.float_id=c.float_id JOIN sales_quick_sale_reports r ON r.company_id=c.company_id AND r.report_id=c.originating_report_id JOIN users u ON u.user_id=c.dsa_dsp_user_id JOIN users m ON m.user_id=c.responsible_manager_id WHERE '.implode(' AND ',$where).' ORDER BY c.submitted_at DESC,c.incentive_claim_id DESC LIMIT 300';
        $query=\db()->prepare($sql);$query->execute($params);$claims=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach($claims as &$claim){$claim['outstanding']=max(0,round((float)($claim['approved_amount']??0)-(float)$claim['settled_amount'],2));$claim['unexplained_variance']=round((float)$claim['float_amount']-(float)$claim['sold_amount']-(float)($claim['approved_amount']??0),2);}unset($claim);
        $users=\db()->prepare('SELECT cu.user_id,u.display_name FROM company_users cu JOIN users u ON u.user_id=cu.user_id WHERE cu.company_id=? AND cu.active=TRUE ORDER BY u.display_name');$users->execute([$company]);
        $floats=\db()->prepare('SELECT f.* FROM sales_dsa_float_issuances f WHERE f.company_id=? ORDER BY f.issued_date DESC,f.float_id DESC LIMIT 300');$floats->execute([$company]);
        $floatRows=array_values(array_filter($floats->fetchAll(PDO::FETCH_ASSOC),static fn(array $f):bool=>$visible===null||in_array((int)$f['dsa_dsp_user_id'],$visible,true)));
        $reports=\db()->prepare("SELECT r.report_id,qs.user_id dsa_dsp_user_id,r.created_at FROM sales_quick_sale_reports r JOIN sales_quick_sales qs ON qs.company_id=r.company_id AND qs.quick_sale_id=r.quick_sale_id WHERE r.company_id=? AND r.status='confirmed' AND qs.status='closed' AND r.finance_invoice_id IS NOT NULL AND r.report_id=(SELECT MAX(latest.report_id) FROM sales_quick_sale_reports latest WHERE latest.company_id=r.company_id AND latest.quick_sale_id=r.quick_sale_id) ORDER BY r.report_id DESC LIMIT 300");$reports->execute([$company]);
        $reportRows=array_values(array_filter($reports->fetchAll(PDO::FETCH_ASSOC),static fn(array $r):bool=>$visible===null||in_array((int)$r['dsa_dsp_user_id'],$visible,true)));
        return ['claims'=>$claims,'floats'=>$floatRows,'reports'=>$reportRows,'users'=>$users->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function detail(int $id,int $actor): array
    {
        $company=$this->company();$this->permit($company,$actor,'sales.incentive.view');
        $query=\db()->prepare('SELECT c.*,f.amount float_amount,f.reference float_reference,f.issued_date,f.status float_status,r.quick_sale_id,r.finance_invoice_id,qs.user_id report_dsa_user_id,u.display_name dsa_name,m.display_name manager_name,COALESCE((SELECT SUM(l.total_amount) FROM finance_invoice_lines l WHERE l.company_id=r.company_id AND l.invoice_id=r.finance_invoice_id),0) sold_amount FROM sales_incentive_claims c JOIN sales_dsa_float_issuances f ON f.company_id=c.company_id AND f.float_id=c.float_id JOIN sales_quick_sale_reports r ON r.company_id=c.company_id AND r.report_id=c.originating_report_id JOIN sales_quick_sales qs ON qs.company_id=r.company_id AND qs.quick_sale_id=r.quick_sale_id JOIN users u ON u.user_id=c.dsa_dsp_user_id JOIN users m ON m.user_id=c.responsible_manager_id WHERE c.company_id=? AND c.incentive_claim_id=?');$query->execute([$company,$id]);$claim=$query->fetch(PDO::FETCH_ASSOC);
        if(!$claim)throw new RuntimeException('Incentive claim not found.');
        $scope=new SalesHierarchyScope();
        if(!$scope->hasCompanyWideAccess($company,$actor)&&!in_array((int)$claim['dsa_dsp_user_id'],$scope->userIds($company,$actor),true))throw new RuntimeException('Incentive claim is outside your reporting scope.');
        $settlements=\db()->prepare('SELECT * FROM sales_incentive_settlements WHERE company_id=? AND incentive_claim_id=? ORDER BY settlement_date,incentive_settlement_id');$settlements->execute([$company,$id]);$settlementRows=$settlements->fetchAll(PDO::FETCH_ASSOC);
        $events=\db()->prepare('SELECT * FROM sales_incentive_events WHERE company_id=? AND incentive_claim_id=? ORDER BY occurred_at,incentive_event_id');$events->execute([$company,$id]);
        $paid=0.0;foreach($settlementRows as $row)if($row['reversed_at']===null)$paid+=(float)$row['amount'];
        $claim['settled_amount']=round($paid,2);$claim['outstanding']=max(0,round((float)($claim['approved_amount']??0)-$paid,2));$claim['unexplained_variance']=round((float)$claim['float_amount']-(float)$claim['sold_amount']-(float)($claim['approved_amount']??0),2);
        return ['claim'=>$claim,'settlements'=>$settlementRows,'events'=>$events->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function report(PDO $connection,int $company,int $reportId): array
    {
        $query=$connection->prepare("SELECT r.report_id,r.status,r.reported_by_user_id,qs.user_id dsa_dsp_user_id,qs.status quick_sale_status,COALESCE(fi.currency,q.currency) currency,r.finance_invoice_id,COALESCE((SELECT SUM(l.total_amount) FROM finance_invoice_lines l WHERE l.company_id=r.company_id AND l.invoice_id=r.finance_invoice_id),0) sold_amount FROM sales_quick_sale_reports r JOIN sales_quick_sales qs ON qs.company_id=r.company_id AND qs.quick_sale_id=r.quick_sale_id JOIN sales_quotations q ON q.company_id=qs.company_id AND q.quotation_id=qs.quotation_id LEFT JOIN finance_invoices fi ON fi.company_id=r.company_id AND fi.invoice_id=r.finance_invoice_id WHERE r.company_id=? AND r.report_id=? AND r.report_id=(SELECT MAX(latest.report_id) FROM sales_quick_sale_reports latest WHERE latest.company_id=r.company_id AND latest.quick_sale_id=r.quick_sale_id)");
        $query->execute([$company,$reportId]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['status']!=='confirmed'||$row['quick_sale_status']!=='closed'||empty($row['finance_invoice_id']))throw new RuntimeException('A confirmed closed DSA/DSP report with Finance handoff is required.');
        return $row;
    }

    private function event(PDO $connection,int $company,int $claim,string $type,?string $from,string $to,int $actor,?string $reason=null): void
    {
        $connection->prepare('INSERT INTO sales_incentive_events(company_id,incentive_claim_id,event_type,from_status,to_status,actor_id,reason_reference,occurred_at) VALUES(?,?,?,?,?,?,?,NOW())')->execute([$company,$claim,$type,$from,$to,$actor,$reason]);
    }
}
