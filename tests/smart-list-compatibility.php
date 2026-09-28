<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';
require __DIR__.'/support/licensed-import-fixture.php';
if(getenv('APP_ENV')!=='testing')throw new RuntimeException('Testing only');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString());exit(1);});
$db=db();$passed=0;$oldAuth=$_SESSION['auth']??null;$oldGet=$_GET;$oldServer=$_SERVER;
$company=(int)$db->query('SELECT MIN(company_id) FROM companies WHERE deleted_at IS NULL')->fetchColumn();
$db->beginTransaction();
ob_start();
try{
    $actor=licensedImportFixture($db,$company,'COMP'.bin2hex(random_bytes(5)));
    $_SESSION['auth']['permissions']=$db->query('SELECT code FROM permissions WHERE active=1')->fetchAll(PDO::FETCH_COLUMN);
    $_SESSION['auth']['roles']=['company_owner'];
    $companyQuery=$db->prepare('SELECT * FROM companies WHERE company_id=?');$companyQuery->execute([$company]);
    $_SESSION['auth']['company']=$companyQuery->fetch(PDO::FETCH_ASSOC);
    $_SESSION['auth']['display_name']='Compatibility fixture';
    set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
        if(!(error_reporting()&$severity))return false;
        throw new ErrorException($message,0,$severity,$file,$line);
    });
    // Rendering the real navigation catches missing conditional links on both tiers.
    foreach((int)($argv[1]??0)===0?['dashboard','expenses','legacy-expenses','invoices','payables','accounts','reports']:[] as $section){
        $_SERVER['REQUEST_URI']='/office_app/public/finance';$_GET=['section'=>$section];
        ob_start();
        try{view('layouts.module-navigation',['moduleContext'=>['module'=>'finance','section'=>$section],'user'=>$_SESSION['auth']]);$html=ob_get_contents();}finally{ob_end_clean();}
        if(!str_contains($html,'Finance workspace')||str_contains($html,'Expense History'))throw new RuntimeException('Finance navigation failed for '.$section);
        echo 'PASS Finance navigation '.$section."\n";++$passed;
    }
    $pages=[
        ['/finance','FinanceController','index',[]],
        ['/finance?section=receivables','FinanceController','index',['section'=>'receivables']],
        ['/finance/customer-invoices','FinanceController','customerInvoices',[]],
        ['/finance/expenses','FinanceExpenseController','index',[]],
        ['/finance/staff-loans','FinanceStaffLoanController','index',[]],
        ['/finance/accounting-periods','FinanceController','accountingPeriods',[]],
        ['/finance/bank-reconciliation','FinanceBankReconciliationController','index',[]],
        ['/hr','HrController','index',[]],
        ['/hr/leave','LeaveController','index',[]],
        ['/attendance','AttendanceController','index',[]],
        ['/attendance/calendars','WorkforceCalendarController','index',[]],
        ['/sales/orders','SalesController','orders',[]],
        ['/sales/products','SalesController','products',[]],
        ['/inventory','InventoryController','index',[]],
        ['/inventory/stock-requests','StockRequestController','index',[]],
        ['/procurement','ProcurementController','index',[]],
        ['/assets-management','AssetController','index',[]],
        ['/administration/users','UserAdministrationController','index',[]],
    ];
    foreach($pages as $pageIndex=>[$path,$class,$method,$query]){
        if($pageIndex!==(int)($argv[1]??0))continue;
        $_SERVER['REQUEST_URI']='/office_app/public'.$path;$_SERVER['REQUEST_METHOD']='GET';$_GET=$query;http_response_code(200);
        $controller='App\\Controllers\\'.$class;
        ob_start();
        try{(new $controller())->$method();$html=ob_get_contents();}finally{ob_end_clean();}
        if(http_response_code()!==200||!str_contains($html,'<!DOCTYPE html>')||str_contains($html,'SYS-UNEXPECTED-001'))throw new RuntimeException('Compatibility page failed: '.$path);
        echo 'PASS Full page '.$path."\n";++$passed;
    }
}finally{restore_error_handler();if($db->inTransaction())$db->rollBack();$_SESSION['auth']=$oldAuth;$_GET=$oldGet;$_SERVER=$oldServer;}
echo "$passed basic compatibility checks passed\n";
ob_end_flush();
