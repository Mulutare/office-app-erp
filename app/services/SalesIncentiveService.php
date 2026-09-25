<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Cumulative Safaricom incentives and historical cash floats; never posts Finance GL. */
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
        if($dsa===$manager||$scope->isAgent($company,$manager)||!$scope->isAgent($company,$dsa)||$scope->parentId($company,$dsa)!==$manager||!(new ModuleRoleService())->permissionAllowed($company,$manager,'sales.incentive.approve'))throw new RuntimeException('Only the DSA/DSP direct authorized manager may perform this action.');
    }

    private function floatProduct(int $company): ?array
    {
        $query=\db()->prepare("SELECT product_id,sku,name FROM sales_products WHERE company_id=? AND sku='FLOAT' AND active=TRUE AND deleted_at IS NULL");
        $query->execute([$company]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function issueFloat(array $input,int $actor): int
    {
        $company=$this->company();$this->permit($company,$actor,'sales.incentive.approve');
        $dsa=(int)($input['dsa_dsp_user_id']??0);$this->directManager($company,$dsa,$actor);
        if((int)($input['report_id']??0)>0){
            $report=$this->report(\db(),$company,(int)$input['report_id']);
            if((int)$report['dsa_dsp_user_id']!==$dsa)throw new RuntimeException('Select the DSA/DSP of the confirmed report, or clear the report selection before issuing to another DSA/DSP.');
        }
        $product=$this->floatProduct($company);
        if($product===null)throw new RuntimeException('Active FLOAT product is not configured in Sales Products.');
        if((int)($input['product_id']??0)!==(int)$product['product_id'])throw new RuntimeException('Select the active FLOAT product from this company’s Sales Products.');
        $amount=$this->money($input['amount']??null);$reference=trim((string)($input['reference']??''));$date=trim((string)($input['issued_date']??''));
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date||$reference===''||strlen($reference)>120)throw new RuntimeException('Enter an issuance date and unique reference.');
        $currency=\db()->prepare('SELECT default_currency FROM companies WHERE company_id=?');$currency->execute([$company]);$code=strtoupper((string)$currency->fetchColumn());
        if(preg_match('/^[A-Z]{3}$/',$code)!==1)throw new RuntimeException('Company currency is not configured.');
        $insert=\db()->prepare("INSERT INTO sales_dsa_float_issuances(company_id,dsa_dsp_user_id,manager_user_id,product_id,amount,currency,issued_date,reference,status,created_by,created_at) SELECT ?,?,?,?,?,?,?,?,'issued',?,NOW() FROM sales_products WHERE company_id=? AND product_id=? AND sku='FLOAT' AND active=TRUE AND deleted_at IS NULL");
        $insert->execute([$company,$dsa,$actor,$product['product_id'],$amount,$code,$date,$reference,$actor,$company,$product['product_id']]);
        if($insert->rowCount()!==1)throw new RuntimeException('Active FLOAT product is not configured in Sales Products.');
        return (int)\db()->lastInsertId();
    }

    public function submitClaim(array $input,int $actor): int
    {
        $company=$this->company();$this->permit($company,$actor,'sales.incentive.submit');
        $scope=new SalesHierarchyScope();
        if(!$scope->isAgent($company,$actor))throw new RuntimeException('Only a DSA/DSP may submit their own incentive.');
        if(isset($input['dsa_dsp_user_id'])&&(int)$input['dsa_dsp_user_id']!==$actor)throw new RuntimeException('You may only submit your own incentive.');
        $proposed=$this->money($input['proposed_amount']??null);
        $reference=trim((string)($input['external_reference']??''));
        if($reference===''||strlen($reference)>190)throw new RuntimeException('Enter a Safaricom reference of at most 190 characters.');
        $connection=\db();$connection->beginTransaction();
        try{
            $lock=$connection->prepare('SELECT user_id FROM company_users WHERE company_id=? AND user_id=? AND active=TRUE FOR UPDATE');
            $lock->execute([$company,$actor]);
            if(!$lock->fetchColumn())throw new RuntimeException('Active company membership is required.');
            $manager=$scope->parentId($company,$actor);
            $this->directManager($company,$actor,(int)$manager);
            $positions=(new SalesPerformanceReportService())->cumulativeConfirmedSales($company,$actor);
            if($positions===[])throw new RuntimeException('A confirmed DSA/DSP sales position is required.');
            $currency=count($positions)===1?$positions[0]['currency']:strtoupper(trim((string)($input['currency']??'')));
            $position=null;
            foreach($positions as $candidate)if($candidate['currency']===$currency)$position=$candidate;
            if($position===null)throw new RuntimeException('Select a currency from your confirmed sales position.');
            $key=hash('sha256',$company.'|'.$actor.'|'.mb_strtolower($reference));
            $duplicate=$connection->prepare('SELECT 1 FROM sales_incentive_claims WHERE company_id=? AND submission_key=?');
            $duplicate->execute([$company,$key]);
            if($duplicate->fetchColumn())throw new RuntimeException('This Safaricom reference has already been submitted.');
            $insert=$connection->prepare("INSERT INTO sales_incentive_claims(company_id,dsa_dsp_user_id,responsible_manager_id,currency,proposed_amount,external_body,external_reference,status,submitted_by,submitted_at,claim_basis,confirmed_sales_snapshot,confirmed_reports_snapshot,submission_key) VALUES(?,?,?,?,?,'Safaricom',?,'submitted',?,NOW(),'cumulative_sales',?,?,?)");
            $insert->execute([$company,$actor,$manager,$currency,$proposed,$reference,$actor,$position['sales_amount'],json_encode($position['reports'],JSON_THROW_ON_ERROR),$key]);
            $id=(int)$connection->lastInsertId();$this->event($connection,$company,$id,'submitted',null,'submitted',$actor,$reference);
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
            (new UserNotificationService($connection))->notify($company,(int)$claim['dsa_dsp_user_id'],$approve?'sales.incentive.approved':'sales.incentive.rejected',$approve?'Safaricom incentive approved':'Safaricom incentive rejected',$approve?'The approved company-funded amount remains a negative variance until Safaricom settles it.':$reason,'sales_incentive_claim',$id,'/sales/incentives/'.$id,'sales-incentive:'.$id.':'.($approve?'approved':'rejected'));
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
            if (!(new SalesHierarchyScope())->hasCompanyWideAccess($company,$actor)) {
                $this->directManager($company,(int)$claim['dsa_dsp_user_id'],$actor);
                if ((int)$claim['responsible_manager_id']!==$actor) throw new RuntimeException('Only the responsible manager may settle this claim.');
            }
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
        $options=['positions'=>[],'dsaName'=>null,'managerName'=>null];
        if($scope->isAgent($company,$actor)){
            $options['positions']=$this->cumulativePosition($company,$actor);
            $manager=$scope->parentId($company,$actor);
            $names=\db()->prepare('SELECT user_id,display_name FROM users WHERE user_id IN (?,?)');$names->execute([$actor,$manager]);
            $names=array_column($names->fetchAll(PDO::FETCH_ASSOC),'display_name','user_id');
            $options['dsaName']=$names[$actor]??'';$options['managerName']=$names[$manager]??'Not assigned';
        }
        if($visible===[])return ['claims'=>[],'users'=>[],'managers'=>[]]+$options;
        $params=[$company];$where=['c.company_id=?'];
        if($visible!==null){$where[]='c.dsa_dsp_user_id IN ('.implode(',',array_fill(0,count($visible),'?')).')';$params=array_merge($params,$visible);}
        $status=(string)($filters['status']??'');
        if(in_array($status,['outstanding','submitted','approved','rejected','partially_settled','settled'],true)){
            $where[]=$status==='outstanding'?"c.status='approved'":"c.status=?";
            if($status!=='outstanding')$params[]=$status;
        }
        foreach(['dsa_dsp_user_id','responsible_manager_id'] as $key){if((int)($filters[$key]??0)>0){$where[]='c.'.$key.'=?';$params[]=(int)$filters[$key];}}
        $date=(string)($filters['date']??'');if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){ $where[]='DATE(c.submitted_at)=?';$params[]=$date; }
        $reference=trim((string)($filters['safaricom_reference']??''));if($reference!==''){ $where[]='(c.external_reference LIKE ? OR EXISTS(SELECT 1 FROM sales_incentive_settlements sx WHERE sx.company_id=c.company_id AND sx.incentive_claim_id=c.incentive_claim_id AND sx.external_payment_reference LIKE ?))';$params[]='%'.$reference.'%';$params[]='%'.$reference.'%'; }
        $sql='SELECT c.*,f.amount float_amount,f.reference float_reference,f.issued_date,u.display_name dsa_name,m.display_name manager_name,COALESCE(c.confirmed_sales_snapshot,(SELECT SUM(l.total_amount) FROM finance_invoice_lines l WHERE l.company_id=r.company_id AND l.invoice_id=r.finance_invoice_id),0) sold_amount,COALESCE((SELECT SUM(s.amount) FROM sales_incentive_settlements s WHERE s.company_id=c.company_id AND s.incentive_claim_id=c.incentive_claim_id AND s.reversed_at IS NULL),0) settled_amount FROM sales_incentive_claims c LEFT JOIN sales_dsa_float_issuances f ON f.company_id=c.company_id AND f.float_id=c.float_id LEFT JOIN sales_quick_sale_reports r ON r.company_id=c.company_id AND r.report_id=c.originating_report_id JOIN users u ON u.user_id=c.dsa_dsp_user_id JOIN users m ON m.user_id=c.responsible_manager_id WHERE '.implode(' AND ',$where).' ORDER BY c.submitted_at DESC,c.incentive_claim_id DESC LIMIT 300';
        $query=\db()->prepare($sql);$query->execute($params);$claims=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach($claims as &$claim){$claim['outstanding']=max(0,round((float)($claim['approved_amount']??0)-(float)$claim['settled_amount'],2));$claim['unexplained_variance']=$claim['claim_basis']==='legacy_float'?round((float)$claim['float_amount']-(float)$claim['sold_amount']-(float)($claim['approved_amount']??0),2):-$claim['outstanding'];}unset($claim);
        $userSql='SELECT cu.user_id,u.display_name FROM company_users cu JOIN users u ON u.user_id=cu.user_id WHERE cu.company_id=? AND cu.active=TRUE';
        $userParams=[$company];
        if($visible!==null){$userSql.=' AND cu.user_id IN ('.implode(',',array_fill(0,count($visible),'?')).')';$userParams=array_merge($userParams,$visible);}
        $users=\db()->prepare($userSql.' ORDER BY u.display_name');$users->execute($userParams);
        $managerSql='SELECT DISTINCT m.user_id,m.display_name FROM company_users cu JOIN users m ON m.user_id=cu.manager_user_id AND m.active=TRUE AND m.deleted_at IS NULL WHERE cu.company_id=? AND cu.active=TRUE AND cu.manager_user_id IS NOT NULL';
        $managerParams=[$company];
        if($visible!==null){$managerSql.=' AND cu.user_id IN ('.implode(',',array_fill(0,count($visible),'?')).')';$managerParams=array_merge($managerParams,$visible);}
        $managers=\db()->prepare($managerSql.' ORDER BY m.display_name');$managers->execute($managerParams);
        return ['claims'=>$claims,'users'=>$users->fetchAll(PDO::FETCH_ASSOC),'managers'=>$managers->fetchAll(PDO::FETCH_ASSOC)]+$options;
    }

    /** Current amounts are separate from the immutable claim snapshots. */
    private function cumulativePosition(int $company,int $dsa): array
    {
        $positions=(new SalesPerformanceReportService())->cumulativeConfirmedSales($company,$dsa);
        $approved=\db()->prepare("SELECT currency,SUM(approved_amount) amount FROM sales_incentive_claims WHERE company_id=? AND dsa_dsp_user_id=? AND status IN('approved','partially_settled','settled') GROUP BY currency");
        $approved->execute([$company,$dsa]);
        $totals=array_column($approved->fetchAll(PDO::FETCH_ASSOC),'amount','currency');
        $settled=\db()->prepare("SELECT c.currency,SUM(s.amount) amount FROM sales_incentive_settlements s
            JOIN sales_incentive_claims c ON c.company_id=s.company_id AND c.incentive_claim_id=s.incentive_claim_id
            WHERE c.company_id=? AND c.dsa_dsp_user_id=? AND c.status IN('approved','partially_settled','settled')
              AND s.reversed_at IS NULL GROUP BY c.currency");
        $settled->execute([$company,$dsa]);
        $payments=array_column($settled->fetchAll(PDO::FETCH_ASSOC),'amount','currency');
        foreach(array_diff(array_keys($totals),array_column($positions,'currency')) as $currency){
            $positions[]=['currency'=>$currency,'sales_amount'=>'0.00','report_count'=>0,'reports'=>[]];
        }
        foreach($positions as &$position){
            $position['approved_incentives']=$totals[$position['currency']]??'0.00';
            $position['settled_incentives']=$payments[$position['currency']]??'0.00';
            // Company-funded approved incentives remain negative until Safaricom reimburses them.
            $position['unexplained_variance']=round((float)$position['settled_incentives']-(float)$position['approved_incentives'],2);
        }
        unset($position);
        return $positions;
    }

    public function detail(int $id,int $actor): array
    {
        $company=$this->company();$this->permit($company,$actor,'sales.incentive.view');
        $query=\db()->prepare('SELECT c.*,f.amount float_amount,f.reference float_reference,f.issued_date,f.status float_status,r.quick_sale_id,r.finance_invoice_id,qs.user_id report_dsa_user_id,u.display_name dsa_name,m.display_name manager_name,COALESCE(c.confirmed_sales_snapshot,(SELECT SUM(l.total_amount) FROM finance_invoice_lines l WHERE l.company_id=r.company_id AND l.invoice_id=r.finance_invoice_id),0) sold_amount FROM sales_incentive_claims c LEFT JOIN sales_dsa_float_issuances f ON f.company_id=c.company_id AND f.float_id=c.float_id LEFT JOIN sales_quick_sale_reports r ON r.company_id=c.company_id AND r.report_id=c.originating_report_id LEFT JOIN sales_quick_sales qs ON qs.company_id=r.company_id AND qs.quick_sale_id=r.quick_sale_id JOIN users u ON u.user_id=c.dsa_dsp_user_id JOIN users m ON m.user_id=c.responsible_manager_id WHERE c.company_id=? AND c.incentive_claim_id=?');$query->execute([$company,$id]);$claim=$query->fetch(PDO::FETCH_ASSOC);
        if(!$claim)throw new RuntimeException('Incentive claim not found.');
        $scope=new SalesHierarchyScope();
        if(!$scope->hasCompanyWideAccess($company,$actor)&&!in_array((int)$claim['dsa_dsp_user_id'],$scope->userIds($company,$actor),true))throw new RuntimeException('Incentive claim is outside your reporting scope.');
        $settlements=\db()->prepare('SELECT * FROM sales_incentive_settlements WHERE company_id=? AND incentive_claim_id=? ORDER BY settlement_date,incentive_settlement_id');$settlements->execute([$company,$id]);$settlementRows=$settlements->fetchAll(PDO::FETCH_ASSOC);
        $events=\db()->prepare('SELECT * FROM sales_incentive_events WHERE company_id=? AND incentive_claim_id=? ORDER BY occurred_at,incentive_event_id');$events->execute([$company,$id]);
        $paid=0.0;foreach($settlementRows as $row)if($row['reversed_at']===null)$paid+=(float)$row['amount'];
        $claim['settled_amount']=round($paid,2);$claim['outstanding']=max(0,round((float)($claim['approved_amount']??0)-$paid,2));$claim['unexplained_variance']=$claim['claim_basis']==='legacy_float'?round((float)$claim['float_amount']-(float)$claim['sold_amount']-(float)($claim['approved_amount']??0),2):-$claim['outstanding'];
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
