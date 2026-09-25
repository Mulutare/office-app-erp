<?php

declare(strict_types=1);

require __DIR__ . '/../app/helpers/bootstrap.php';

use App\Models\CompanyMembership;
use App\Services\SalesIncentiveService;

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
});
if (getenv('DB_DATABASE') !== 'office_app_cumulative_test') throw new RuntimeException('Dedicated office_app_cumulative_test database required');

$pdo = db();
$service = new SalesIncentiveService();
$company = 2;
$manager = 122;
$dsa = 123;
$suffix = bin2hex(random_bytes(6));
$savedSession = $_SESSION;
$checks = 0;
$created = [];
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    ++$checks;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
};
$reject = static function (callable $work): bool {
    try { $work(); return false; } catch (RuntimeException) { return true; }
};
$login = static function (int $actor) use ($company): void {
    $_SESSION['auth'] = ['user_id' => $actor, 'company' => (new App\Models\CompanyModule())->companyById($company),
        'permissions' => (new CompanyMembership())->permissionCodes($actor, $company), 'is_platform_admin' => false];
};
$insert = static function (string $table, string $key, array $row) use ($pdo, &$created): int {
    unset($row[$key]);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));
    $id = (int) $pdo->lastInsertId();
    $created[] = [$table, $key, $id];
    return $id;
};
$snapshot = static function () use ($pdo): array {
    $out = [];
    foreach (['inventory_stock_balances', 'inventory_stock_movements', 'finance_journal_entries', 'finance_invoice_lines'] as $table) {
        $rows = array_map('json_encode', $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows);
        $out[$table] = hash('sha256', implode("\n", $rows));
    }
    return $out;
};
$grants = $pdo->query("SELECT * FROM company_user_permission_overrides WHERE company_id=$company AND user_id IN ($manager,$dsa)")->fetchAll(PDO::FETCH_ASSOC);
$claimIds = [];
$history = $pdo->query('SELECT * FROM sales_dsa_float_issuances ORDER BY float_id')->fetchAll(PDO::FETCH_ASSOC);
$baseline = $snapshot();
$performance = new App\Services\SalesPerformanceReportService();
$position = static function (array $positions, string $currency): array {
    foreach ($positions as $row) if ($row['currency'] === $currency) return $row;
    return ['sales_amount'=>0,'report_count'=>0,'reports'=>[], 'approved_incentives'=>0,'unexplained_variance'=>0];
};
try {
    foreach ([$manager,$dsa] as $actor) {
        $pdo->prepare("INSERT INTO company_user_permission_overrides (company_id,user_id,permission_id,allowed,updated_by)
            SELECT ?,?,permission_id,1,? FROM permissions WHERE code IN ('sales.module.enabled','sales.incentive.view','sales.incentive.submit','sales.incentive.approve','sales.incentive.settle')
            ON DUPLICATE KEY UPDATE allowed=1")->execute([$company,$actor,$manager]);
    }
    $report = $pdo->query("SELECT r.* FROM sales_quick_sale_reports r WHERE r.company_id=$company AND r.status='confirmed'
        AND EXISTS(SELECT 1 FROM sales_quick_sale_report_lines l WHERE l.report_id=r.report_id) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$report) throw new RuntimeException('Confirmed report fixture required');
    $lines = $pdo->query('SELECT * FROM sales_quick_sale_report_lines WHERE report_id='.(int)$report['report_id'])->fetchAll(PDO::FETCH_ASSOC);
    $quick = $pdo->query('SELECT * FROM sales_quick_sales WHERE quick_sale_id='.(int)$report['quick_sale_id'])->fetch(PDO::FETCH_ASSOC);
    $quote = $pdo->query('SELECT * FROM sales_quotations WHERE quotation_id='.(int)$quick['quotation_id'])->fetch(PDO::FETCH_ASSOC);
    $currency = $pdo->query('SELECT currency FROM finance_invoices WHERE invoice_id='.(int)$report['finance_invoice_id'])->fetchColumn();
    $source = $position($performance->cumulativeConfirmedSales($company,$dsa),$currency);
    $unit = (float)$pdo->query('SELECT SUM(total_amount) FROM finance_invoice_lines WHERE invoice_id='.(int)$report['finance_invoice_id'].' AND product_id IN ('.implode(',',array_column($lines,'product_id')).')')->fetchColumn();
    $revision = static function (int $quickId, string $status='confirmed') use ($insert,$report,$lines,$dsa): int {
        $id = $insert('sales_quick_sale_reports','report_id',array_replace($report,['quick_sale_id'=>$quickId,'reported_by_user_id'=>$dsa,'status'=>$status]));
        foreach ($lines as $line) $insert('sales_quick_sale_report_lines','report_line_id',array_replace($line,['report_id'=>$id]));
        return $id;
    };
    $sale = static function () use ($insert,$quick,$quote,$suffix,$dsa,$revision): array {
        static $counter=0;
        $quoteId=$insert('sales_quotations','quotation_id',array_replace($quote,['quotation_number'=>'CUM-'.$suffix.'-'.++$counter,'sales_order_id'=>null]));
        $quickId=$insert('sales_quick_sales','quick_sale_id',array_replace($quick,['quotation_id'=>$quoteId,'user_id'=>$dsa,'status'=>'closed']));
        return [$quickId,$revision($quickId)];
    };
    [$quick1,$report1]=$sale(); [$quick2,$report2]=$sale();
    $newRevision=$revision($quick1);
    $current=$position($performance->cumulativeConfirmedSales($company,$dsa),$currency);
    $check(abs((float)$current['sales_amount']-((float)$source['sales_amount']+2*$unit))<0.001,'Multiple reports use the authoritative cumulative sales calculation');
    $check(!in_array($report1,array_column($current['reports'],'report_id'),true) && in_array($newRevision,array_column($current['reports'],'report_id'),true),'Only the latest confirmed revision counts');
    $pendingRevision=$revision($quick2,'submitted');
    $current=$position($performance->cumulativeConfirmedSales($company,$dsa),$currency);
    $check(!in_array($report2,array_column($current['reports'],'report_id'),true) && abs((float)$current['sales_amount']-((float)$source['sales_amount']+$unit))<0.001,'Pending replacement excludes superseded confirmed sales');
    $revision($quick2);

    $login($dsa);
    $before=$position($service->register($dsa)['positions'],$currency);
    $input=['proposed_amount'=>1000,'external_reference'=>'CUM-'.$suffix,'confirmed_sales_snapshot'=>'999999.99'];
    $check($reject(fn()=>$service->submitClaim(array_replace($input,['dsa_dsp_user_id'=>$manager]),$dsa)),'Cannot submit for another person');
    $check($reject(fn()=>$service->submitClaim(array_replace($input,['external_reference'=>'']),$dsa)),'Reference is required');
    $claimId=$service->submitClaim($input,$dsa);$claimIds[]=$claimId;
    $claim=$service->detail($claimId,$dsa)['claim'];
    $check($claim['originating_report_id']===null && $claim['float_id']===null && $claim['claim_basis']==='cumulative_sales','Submission requires neither report nor cash float');
    $check((float)$claim['confirmed_sales_snapshot']===(float)$before['sales_amount'] && count(json_decode($claim['confirmed_reports_snapshot'],true))===$before['report_count'],'Snapshot contains cumulative value and report audit context');
    $check((int)$claim['submitted_by']===$dsa && (int)$claim['responsible_manager_id']===$manager && $claim['submitted_at']!==null,'Submission preserves maker, direct manager, currency and date');
    $check($reject(fn()=>$service->submitClaim($input,$dsa)),'Duplicate reference cannot create a second claim');
    $after=$position($service->register($dsa)['positions'],$currency);
    $check((float)$after['unexplained_variance']===(float)$before['unexplained_variance'],'Pending incentive does not change cumulative variance');
    $sale();
    $check($service->detail($claimId,$dsa)['claim']['confirmed_sales_snapshot']===$claim['confirmed_sales_snapshot'] && $service->detail($claimId,$dsa)['claim']['confirmed_reports_snapshot']===$claim['confirmed_reports_snapshot'],'Later confirmed sales cannot change a submitted snapshot');
    $check($reject(fn()=>$service->decideClaim($claimId,true,80,'',$dsa)),'Maker cannot approve even with approval permission');
    $check($reject(fn()=>$service->decideClaim($claimId,true,80,'',121)),'Wrong manager cannot approve');
    $form=$service->register($dsa);
    ob_start();view('sales.incentives',['incentiveData'=>$form,'canSubmitIncentive'=>true]);$html=(string)ob_get_clean();
    $check(str_contains($html,'Submit to Manager') && str_contains($html,'Current unexplained variance') && !str_contains($html,'name="report_id"') && !str_contains($html,'name="float_id"') && !str_contains($html,'Issue cash float'),'DSA page shows cumulative summary and simple submission without old controls');
    $login($manager);
    $pending=$service->register($manager,['status'=>'submitted']);
    $check(in_array($claimId,array_map('intval',array_column($pending['claims'],'incentive_claim_id')),true),'Direct manager sees pending claim without report/float joins');
    $tasks=(new App\Services\ActionRequiredCountService())->itemsFor($company,$manager,(new CompanyMembership())->permissionCodes($manager,$company),'sales','incentives');
    $check(in_array($claimId,array_column($tasks,'id'),true),'Action Required links the new cumulative claim to manager review');
    ob_start();view('sales.incentive-detail',['incentiveDetail'=>$service->detail($claimId,$manager),'canApproveIncentive'=>true]);$html=(string)ob_get_clean();
    $check(str_contains($html,'Approve incentive') && str_contains($html,'Reject claim') && str_contains($html,'Cumulative confirmed-sales snapshot'),'Manager review displays snapshot and approval/rejection actions');
    $check($reject(fn()=>$service->settle($claimId,['amount'=>1,'settlement_date'=>'2026-09-25','currency'=>$currency,'external_payment_reference'=>'PENDING-'.$suffix],$manager)),'Pending claim cannot receive repayment');
    ob_start();view('sales.incentive-detail',['incentiveDetail'=>$service->detail($claimId,$manager),'canSettleIncentive'=>true]);$html=(string)ob_get_clean();
    $check(!str_contains($html,'id="record-safaricom-repayment"'),'Pending claim hides repayment form even for authorized users');
    $check($reject(fn()=>$service->decideClaim($claimId,true,1001,'',$manager)),'Approval cannot exceed the proposed amount');
    $service->decideClaim($claimId,true,1000,'Company funded',$manager);
    $login($dsa);$approved=$position($service->register($dsa)['positions'],$currency);
    $check((float)$approved['approved_incentives']===(float)$before['approved_incentives']+1000 && (float)$approved['unexplained_variance']===(float)$before['unexplained_variance']-1000,'Manager approval adds approved incentives and creates negative variance');
    $rejectedId=$service->submitClaim(array_replace($input,['external_reference'=>'REJECT-'.$suffix]),$dsa);$claimIds[]=$rejectedId;
    $login($manager);
    $check($reject(fn()=>$service->decideClaim($rejectedId,false,null,'',$manager)),'Rejection requires a reason');
    $service->decideClaim($rejectedId,false,null,'Unsupported reference',$manager);
    $rejected=$service->detail($rejectedId,$manager);
    $check($rejected['claim']['rejection_reason']==='Unsupported reference' && (int)$rejected['claim']['rejected_by']===$manager && count($rejected['events'])===2,'Rejection actor, date, reason and event history are retained');
    $payment=['amount'=>400,'settlement_date'=>'2026-09-25','currency'=>$currency,'external_payment_reference'=>'PAY1-'.$suffix];
    $assertRepaymentUi=static function(float $paid,float $outstanding,bool $showForm) use($service,$manager,$claimId,$check,$currency):void {
        $detail=$service->detail($claimId,$manager);
        $row=$detail['claim'];
        $check((float)$row['approved_amount']===1000.0 && (float)$row['settled_amount']===$paid && (float)$row['outstanding']===$outstanding && (float)$row['unexplained_variance']===-$outstanding,'Detail recalculates repayment balance: '.$paid.' paid');
        ob_start();view('sales.incentive-detail',['incentiveDetail'=>$detail,'canSettleIncentive'=>true]);$html=(string)ob_get_clean();
        $check(str_contains($html,$currency.' '.number_format(-$outstanding,2)) && str_contains($html,'Current variance') && str_contains($html,'id="record-safaricom-repayment"')===$showForm,'Detail shows prominent variance and repayment form only while outstanding');
        ob_start();view('sales.incentive-detail',['incentiveDetail'=>$detail,'canSettleIncentive'=>false]);$readonly=(string)ob_get_clean();
        $check(!str_contains($readonly,'/settlements"') && str_contains($readonly,'Current variance'),'User without settlement permission sees summary without repayment action');
        $register=$service->register($manager);
        $rows=array_values(array_filter($register['claims'],static fn(array $r):bool=>(int)$r['incentive_claim_id']===$claimId));
        $check(count($rows)===1 && (float)$rows[0]['settled_amount']===$paid && (float)$rows[0]['outstanding']===$outstanding && (float)$rows[0]['unexplained_variance']===-$outstanding,'Register refresh matches detail after '.$paid.' paid');
        ob_start();view('sales.incentives',['incentiveData'=>['claims'=>$rows]]);$html=(string)ob_get_clean();
        $check(str_contains($html,'<th>Safaricom settled</th>') && str_contains($html,'<th>Variance</th>') && str_contains($html,'<strong>'.number_format(-$outstanding,2).'</strong>'),'Register renders settlement and formatted variance columns');
    };
    $assertRepaymentUi(0.0,1000.0,true);
    $foreignCompany=(int)$pdo->query("SELECT company_id FROM companies WHERE company_id<>$company LIMIT 1")->fetchColumn();
    $_SESSION['auth']['company']=['company_id'=>$foreignCompany];
    $check($reject(fn()=>$service->settle($claimId,$payment,$manager)),'Cross-company repayment is rejected');
    $login($manager);
    $check($reject(fn()=>$service->settle($rejectedId,$payment,$manager)),'Rejected claim cannot receive repayment');
    foreach([0,-1] as $invalidAmount)$check($reject(fn()=>$service->settle($claimId,array_replace($payment,['amount'=>$invalidAmount]),$manager)),'Nonpositive repayment is rejected');
    $pdo->prepare("UPDATE company_user_permission_overrides o JOIN permissions p ON p.permission_id=o.permission_id SET o.allowed=0 WHERE o.company_id=? AND o.user_id=? AND p.code='sales.incentive.settle'")->execute([$company,$manager]);
    $check($reject(fn()=>$service->settle($claimId,$payment,$manager)),'Crafted repayment POST cannot bypass settlement permission');
    $pdo->prepare("UPDATE company_user_permission_overrides o JOIN permissions p ON p.permission_id=o.permission_id SET o.allowed=1 WHERE o.company_id=? AND o.user_id=? AND p.code='sales.incentive.settle'")->execute([$company,$manager]);
    $check($reject(fn()=>$service->settle($claimId,array_replace($payment,['currency'=>'XXX']),$manager)),'Settlement rejects currency mismatch');
    $service->settle($claimId,$payment,$manager);
    $assertRepaymentUi(400.0,600.0,true);
    $check($service->detail($claimId,$manager)['claim']['status']==='partially_settled' && (float)$service->detail($claimId,$manager)['claim']['unexplained_variance']===-600.0,'Partial repayment moves the negative claim variance toward zero');
    $check($reject(fn()=>$service->settle($claimId,$payment,$manager)),'Duplicate settlement cannot pay twice');
    $check($reject(fn()=>$service->settle($claimId,array_replace($payment,['amount'=>601,'external_payment_reference'=>'OVER-'.$suffix]),$manager)),'Overpayment is rejected');
    $service->settle($claimId,array_replace($payment,['amount'=>600,'external_payment_reference'=>'PAY2-'.$suffix]),$manager);
    $settled=$service->detail($claimId,$manager);
    $assertRepaymentUi(1000.0,0.0,false);
    $check($reject(fn()=>$service->settle($claimId,array_replace($payment,['amount'=>1,'external_payment_reference'=>'EXTRA-'.$suffix]),$manager)),'Additional 1 repayment after full settlement is rejected');
    $check($settled['claim']['status']==='settled' && (float)$settled['claim']['unexplained_variance']===0.0 && count($settled['settlements'])===2,'Full Safaricom repayment settles the negative variance');
    ob_start();view('sales.incentive-detail',['incentiveDetail'=>$settled]);$html=(string)ob_get_clean();
    $check(str_contains($html,'PAY1-'.$suffix) && str_contains($html,'PAY2-'.$suffix),'Settlement history displays both repayment references');
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO sales_incentive_settlements(company_id,incentive_claim_id,settlement_date,amount,currency,external_body,external_payment_reference,idempotency_key,entered_by,created_at,reversed_by,reversed_at,reversal_reason)
        VALUES(?,?, '2026-09-25',25,?,'Safaricom',?,?,?,NOW(),?,NOW(),'Test reversed repayment')")->execute([$company,$claimId,$currency,'REVERSED-'.$suffix,hash('sha256','REVERSED-'.$suffix),$manager,$manager]);
    $reversedDetail=$service->detail($claimId,$manager)['claim'];
    $reversedRegister=array_values(array_filter($service->register($manager)['claims'],static fn(array $r):bool=>(int)$r['incentive_claim_id']===$claimId))[0];
    $check((float)$reversedDetail['settled_amount']===1000.0 && (float)$reversedRegister['settled_amount']===1000.0 && (float)$reversedDetail['unexplained_variance']===0.0 && (float)$reversedRegister['unexplained_variance']===0.0,'Reversed repayments are excluded from detail and register balances');
    $pdo->rollBack();
    $check($settled['claim']['confirmed_sales_snapshot']===$claim['confirmed_sales_snapshot'] && $settled['claim']['confirmed_reports_snapshot']===$claim['confirmed_reports_snapshot'],'Approval and settlement preserve the original server-calculated snapshot');
    $login($dsa);$after=$position($service->register($dsa)['positions'],$currency);
    $check((float)$after['unexplained_variance']===(float)$before['unexplained_variance'],'Rejected claims have no effect and full repayment restores the cumulative balance');
    $check($snapshot()===$baseline,'No stock movement, Finance journal or invoice-line modification');
    $check($history===$pdo->query('SELECT * FROM sales_dsa_float_issuances ORDER BY float_id')->fetchAll(PDO::FETCH_ASSOC),'All historical float rows remain exactly intact');
    $foreignCompany=(int)$pdo->query("SELECT company_id FROM companies WHERE company_id<>$company LIMIT 1")->fetchColumn();
    $_SESSION['auth']['company']=['company_id'=>$foreignCompany];
    $check($reject(fn()=>$service->detail($claimId,$dsa)) && $reject(fn()=>$service->submitClaim($input,$dsa)) && $reject(fn()=>$service->decideClaim($claimId,true,80,'',$manager)),'Cross-company reads, submission and approval fail');
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($claimIds as $claimId){
        $pdo->prepare("DELETE FROM user_notifications WHERE company_id=? AND reference_type='sales_incentive_claim' AND reference_id=?")->execute([$company,$claimId]);
        foreach(['sales_incentive_events','sales_incentive_settlements','sales_incentive_claims'] as $table)$pdo->prepare("DELETE FROM $table WHERE company_id=? AND incentive_claim_id=?")->execute([$company,$claimId]);
    }
    foreach(array_reverse($created) as [$table,$key,$id])$pdo->prepare("DELETE FROM $table WHERE $key=?")->execute([$id]);
    $pdo->exec("DELETE FROM company_user_permission_overrides WHERE company_id=$company AND user_id IN ($manager,$dsa)");
    foreach($grants as $row)$pdo->prepare('INSERT INTO company_user_permission_overrides (company_id,user_id,permission_id,allowed,updated_by,updated_at) VALUES (?,?,?,?,?,?)')->execute(array_values($row));
    $_SESSION=$savedSession;
}
echo "$checks cumulative incentive checks passed\n";
