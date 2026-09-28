<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Lists\{FinanceListService,InventoryListService,SalesListService,ProcurementListService,StockRequestLists,FilterOptions};
if(getenv('APP_ENV')!=='testing')throw new RuntimeException('Isolated test database required.');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
$db=db();$context=$db->query("SELECT cr.company_id,cr.user_id FROM company_user_roles cr JOIN roles r ON r.role_id=cr.role_id WHERE r.code='company_owner' ORDER BY cr.company_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$_SESSION['auth']=['user_id'=>(int)$context['user_id'],'company'=>['company_id'=>(int)$context['company_id']]];
$checks=0;$assert=static function(bool $value,string $message)use(&$checks):void{if(!$value)throw new RuntimeException($message);++$checks;};
foreach([
    [new FinanceListService(),['receivables','invoices','receipts','journals','expenses','accounts','ledger','ar-aging','payables','cash-bank','staff-loans','bank-mappings','bank-statements']],
    [new SalesListService(),['customers','products','quotations','sales-orders','deliveries','returns','pricing','variants','pricelists','sales-teams','serials','commissions','targets']],
    [new InventoryListService(),['stock','movements','receipts','transfers','warehouses','locations']],
    [new ProcurementListService(),['suppliers','requisitions','purchase-orders','bills','returns']],
] as [$factory,$entities])foreach($entities as $entity) {
    $controls=$factory->controls($entity);
    foreach($controls['filters'] as $name=>$filter) {
        if(in_array($name,['from','to','date','month'],true))continue;
        $assert(isset($filter['options']),get_class($factory)." $entity/$name must have categorical options");
        if(in_array($name,['status','active','type','loan_type','payment','method'],true))$assert($filter['options']!==[],get_class($factory)." $entity/$name must have a populated domain");
    }
    echo 'PASS '.get_class($factory).' '.$entity.PHP_EOL;
}
foreach(['requests','peers','authorities','reorder'] as $entity)foreach(StockRequestLists::controls($entity)['filters'] as $name=>$filter)if(!isset($filter['type']))$assert(!empty($filter['options']),"$entity/$name must have domain values");
$assert(isset(FilterOptions::domain('inventory_stock_requests','status')['rejected']),'Newest stock request domain must include rejected');
$assert(isset(FilterOptions::domain('sales_customers','customer_type')['government']),'Customer type uses the authoritative SQL domain');
echo "$checks filter-option contract checks passed".PHP_EOL;
