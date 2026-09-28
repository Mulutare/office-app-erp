<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Lists\InventoryListService;
use App\Services\DataExchange\ExportDataProvider;
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
if(getenv('APP_ENV')!=='testing')throw new RuntimeException('Isolated test database required.');
$db=db();$passed=0;$check=static function(bool $ok,string $message)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$message);++$passed;echo 'PASS '.$message.PHP_EOL;};
$context=$db->query("SELECT cr.company_id,cr.user_id FROM company_user_roles cr JOIN roles r ON r.role_id=cr.role_id WHERE r.code='company_owner' ORDER BY cr.company_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$company=(int)$context['company_id'];$owner=(int)$context['user_id'];$_SESSION['auth']=['user_id'=>$owner,'company'=>['company_id'=>$company]];
$prefix='INV'.strtoupper(bin2hex(random_bytes(3)));$db->beginTransaction();
try {
    $db->prepare("UPDATE companies SET active=1,approval_status='approved',subscription_status='active',subscription_expires_at=NULL WHERE company_id=?")->execute([$company]);
    $db->prepare("INSERT INTO company_modules(company_id,module_id,enabled,license_status) SELECT ?,module_id,1,'active' FROM erp_modules WHERE active=1 AND available=1 AND release_status='released' ON DUPLICATE KEY UPDATE enabled=1,license_status='active',expires_at=NULL")->execute([$company]);
    $db->prepare('INSERT INTO users(username,email,password_hash,display_name,must_change_password) VALUES(?,?,?,?,0)')->execute([$prefix,$prefix.'@example.test',password_hash('Unused-test-only-password',PASSWORD_DEFAULT),$prefix.' Manager']);$manager=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO company_users(company_id,user_id,active) VALUES(?,?,1)')->execute([$company,$manager]);
    $wh=$db->prepare('INSERT INTO inventory_warehouses(company_id,code,name,manager_user_id) VALUES(?,?,?,?)');
    $loc=$db->prepare('INSERT INTO inventory_warehouse_locations(company_id,warehouse_id,code,name,active) VALUES(?,?,?,?,?)');
    for($i=1;$i<=305;++$i){$code=$prefix.sprintf('%03d',$i);$wh->execute([$company,$code,$code,$manager]);$warehouses[$i]=(int)$db->lastInsertId();}
    $warehouse=$warehouses[1];$otherWarehouse=$warehouses[2];
    for($i=1;$i<=305;++$i){$code=$prefix.sprintf('%03d',$i);$loc->execute([$company,$warehouse,$code,$code,$i===305?0:1]);$locations[$i]=(int)$db->lastInsertId();}
    $location=$locations[1];$loc->execute([$company,$otherWarehouse,$prefix.'OTHER',$prefix.'Other',1]);$otherLocation=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_stock_authorities(company_id,user_id,authority_level,warehouse_id,location_id) VALUES(?,?,'shop',?,?)")->execute([$company,$manager,$warehouse,$location]);
    $operation=$db->prepare("INSERT INTO inventory_operation_types(company_id,warehouse_id,code,name,operation_kind,default_source_location_id,default_destination_location_id) VALUES(?,?,?,'Receipt','receipt',?,?)");
    $operation->execute([$company,$warehouse,$prefix,$location,$location]);$operationId=(int)$db->lastInsertId();
    $operation->execute([$company,$otherWarehouse,$prefix,$otherLocation,$otherLocation]);$otherOperation=(int)$db->lastInsertId();
    $product=$db->prepare('INSERT INTO sales_products(company_id,sku,name,unit_price) VALUES(?,?,?,0)');
    $stock=$db->prepare("INSERT INTO inventory_stock_balances(company_id,warehouse_id,location_id,product_id,quantity_on_hand,last_movement_at) VALUES(?,?,?,?,5,'2024-01-01')");
    $receipt=$db->prepare("INSERT INTO inventory_goods_receipts(company_id,warehouse_id,destination_location_id,operation_type_id,receipt_number,supplier_name,receipt_date,currency,status) VALUES(?,?,?,?,?,?,'2024-01-01','ETB',?)");
    $movement=$db->prepare("INSERT INTO inventory_stock_movements(company_id,warehouse_id,location_id,product_id,destination_warehouse_id,destination_location_id,movement_type,requested_quantity,completed_quantity,quantity_delta,reference_type,reference_number,idempotency_key,occurred_at) VALUES(?,?,?,?,?,?,'receipt',5,5,5,'goods_receipt',?,?,'2024-01-01')");
    $transfer=$db->prepare("INSERT INTO inventory_transfers(company_id,source_warehouse_id,destination_warehouse_id,operation_type_id,transfer_number,transfer_date,status) VALUES(?,?,?,?,?,'2024-01-01','draft')");
    for($i=1;$i<=106;++$i){
        $code=$prefix.($i===106?'OTHER':sprintf('%03d',$i));$product->execute([$company,$code,$code]);$productId=(int)$db->lastInsertId();
        [$w,$l,$op]=$i===106?[$otherWarehouse,$otherLocation,$otherOperation]:[$warehouse,$location,$operationId];
        $stock->execute([$company,$w,$l,$productId]);$receipt->execute([$company,$w,$l,$op,$code,$prefix.' Vendor',$i===105?'approved':'draft']);
        $movement->execute([$company,$w,$l,$productId,$w,$l,$code,$code]);
        $transfer->execute([$company,$w,$warehouses[3],$op,$code]);
    }
    $factory=new InventoryListService();
    $historyService=new App\Services\SalesStockHistoryService();
    $historyInput=['warehouse_id'=>$warehouse,'date'=>'2024-01-01'];
    $legacyHistory=$historyService->report($owner,$historyInput);
    $history=$historyService->report($owner,$historyInput,true);
    $check($history['lists']['stock']['pagination']['total']===105&&count($history['rows'])===25,'Daily stock balances count all authorized products before paging');
    $check(count($history['exportLists']['stock']->export())===105&&count($history['exportLists']['legs']->export())===105,'Daily history exports all products and movement legs');
    $check(array_sum(array_column($history['exportLists']['stock']->export(),'ending'))===array_sum(array_column($legacyHistory['rows'],'ending')),'SQL stock history preserves legacy ending balance arithmetic');
    $check($historyService->report($owner,$historyInput+['stock'=>['q'=>$prefix.'105']],true)['lists']['stock']['pagination']['total']===1,'Stock history finds a SKU beyond page one');
    $check($historyService->report($owner,$historyInput+['legs'=>['category'=>'received']],true)['lists']['legs']['pagination']['total']===105,'Movement leg categories filter in SQL');
    $check($historyService->report($owner,$historyInput+['legs'=>['q'=>'NO-MATCH']],true)['lists']['legs']['pagination']['total']===0,'Empty movement search stays empty');
    ob_start();view('inventory.stock-daily-history',['stockHistory'=>$history,'user'=>['permissions'=>['inventory.export']]]);$html=(string)ob_get_clean();
    $check(str_contains($html,'register=stock')&&str_contains($html,'register=legs')&&str_contains($html,'register=issues'),'Stock history renders scoped independent exports from the supplied view data');
    foreach(['warehouses'=>305,'locations'=>306,'stock'=>106,'movements'=>106,'receipts'=>106,'transfers'=>106] as $entity=>$total){
        $page=$factory->listing($entity,['q'=>$prefix])->page();
        $check($page['pagination']['total']===$total && count($page['rows'])===25,"$entity counts and pages the complete company dataset");
        $check(count($factory->listing($entity,['q'=>$prefix.'105'])->page()['rows'])===1,"$entity searches beyond page one");
        $check(count($factory->listing($entity,['q'=>$prefix])->export())===$total,"$entity export traverses the complete matching dataset");
    }
    $check($factory->listing('locations',['q'=>$prefix,'active'=>'0'])->page()['pagination']['total']===1,'Inactive location filter precedes count and page');
    $check($factory->listing('receipts',['q'=>$prefix,'status'=>'approved'])->page()['pagination']['total']===1,'Receipt workflow status filters before pagination');
    foreach(['stock','warehouses','locations','receipts'] as $entity)$check(count((new ExportDataProvider())->rows($entity,['q'=>$prefix.'105']))===1,"$entity configured export reuses the register filters");
    $workspace=(new App\Services\InventoryService())->workspace(['stock'=>['q'=>$prefix]]);
    $check($workspace['lists']['stock']['pagination']['total']===106 && $workspace['inventorySummary']['totalQuantity']===530.0,'Stock workspace summary covers matching balances beyond the page');
    $whView=(new App\Services\WarehouseManagementService())->listing(['q'=>$prefix]);
    $locView=(new App\Services\WarehouseLocationManagementService())->listing(['q'=>$prefix]);
    $check($whView['summary']['total']===305 && $locView['summary']['total']===306,'Warehouse and location summaries use complete matching results');
    foreach(['warehouses'=>$whView,'locations'=>$locView] as $entity=>$viewData){
        ob_start();view('inventory.'.$entity.'.index',$viewData+['canManage'=>true,'canExport'=>true]);$html=(string)ob_get_clean();
        $check(str_contains($html,'name="q"') && str_contains($html,'Export filtered') && str_contains($html,'aria-current="page"'),"$entity renders shared controls and filtered export");
    }
    $_GET=['section'=>'stock'];ob_start();view('inventory.index',$workspace+['canExport'=>true]);$html=(string)ob_get_clean();
    $check(str_contains($html,'name="stock[q]"') && str_contains($html,'register=stock'),'Current stock displays scoped search and export controls');
    $_GET=['section'=>'movements'];ob_start();view('inventory.index',$workspace+['canExport'=>true]);$html=(string)ob_get_clean();
    $check(str_contains($html,'name="movements[q]"') && str_contains($html,'register=movements'),'Movement history displays its own scoped search and export controls');
    $_SESSION['auth']['user_id']=$manager;
    foreach(['stock','movements','receipts','transfers'] as $entity){
        $list=$factory->listing($entity,['q'=>$prefix]);
        $check($list->page()['pagination']['total']===105 && count($list->export())===105,"$entity retains assigned stock hierarchy before page and export");
        $check($factory->listing($entity,['q'=>$prefix.'OTHER'])->export()===[],"$entity cannot search or export a sibling shop record");
    }
    $check($factory->listing('warehouses',['q'=>$prefix])->page()['pagination']['total']===1,'Warehouse list retains stock authority scope');
    $check($factory->listing('locations',['q'=>$prefix.'OTHER'])->export()===[],'Location search cannot reach a sibling warehouse');

    // The stock request query must retain requester/handler/allocation scope before filtering.
    $_SESSION['auth']['user_id']=$owner;
    $department=$db->query('SELECT department_id FROM hr_departments WHERE company_id='.(int)$company.' LIMIT 1')->fetchColumn();
    $department=$department===false?null:(int)$department;
    $db->prepare("INSERT INTO hr_employees(company_id,user_id,employee_number,first_name,last_name,work_email,department_id,job_title,employment_type,employment_status,hire_date) VALUES(?,?,?,'Stock','Manager',?,?,'Shop Manager','full_time','active','2024-01-01')")->execute([$company,$manager,$prefix.'EMP',$prefix.'emp@example.test',$department]);$employee=(int)$db->lastInsertId();
    $authority=(int)$db->query('SELECT authority_id FROM inventory_stock_authorities WHERE company_id='.(int)$company.' AND user_id='.(int)$manager)->fetchColumn();
    $request=$db->prepare("INSERT INTO inventory_stock_requests(company_id,request_number,requester_user_id,requester_employee_id,requester_role_snapshot,serving_authority_id,current_handler_user_id,requested_at) VALUES(?,?,?,?,'Manager',?,?,'2024-01-01')");
    $requestLine=$db->prepare('INSERT INTO inventory_stock_request_lines(company_id,request_id,product_id,requested_quantity) VALUES(?,?,?,5)');
    for($i=1;$i<=106;++$i){
        $code=$prefix.($i===106?'OTHER':sprintf('%03d',$i));$who=$i===106?$owner:$manager;
        $request->execute([$company,$code,$who,$employee,$authority,$who]);$requestId=(int)$db->lastInsertId();$requestIds[$i]=$requestId;
        $productId=(int)$db->query("SELECT product_id FROM sales_products WHERE company_id=".(int)$company." AND sku=".$db->quote($code))->fetchColumn();
        $requestLine->execute([$company,$requestId,$productId]);
    }
    $service=new App\Services\StockRequestService();
    $workspace=$service->workspace($owner,null,['requests'=>['q'=>$prefix]]);
    $check($workspace['lists']['requests']['pagination']['total']===106 && count($workspace['stockRequests'])===25,'Stock requests page the full company-authorized dataset');
    $check(count($workspace['exportLists']['requests']->export())===106,'Stock request export includes all matching authorized pages');
    $workspace=$service->workspace($owner,null,['requests'=>['q'=>$prefix.'105']]);
    $check(count($workspace['stockRequests'])===1 && (int)$workspace['stockRequests'][0]['line_count']===1,'Stock request business reference searches beyond page one');
    $db->prepare('UPDATE sales_products SET name=? WHERE product_id=(SELECT product_id FROM inventory_stock_request_lines WHERE request_id=? AND company_id=?)')->execute([$prefix.' Special product',$requestIds[105],$company]);
    $check($service->workspace($owner,null,['requests'=>['q'=>'Special product']])['lists']['requests']['pagination']['total']===1,'Stock request line product search runs in SQL before pagination');
    $check(isset($workspace['lists']['authorities']),'Authority configuration uses the same paginated list foundation');
    $check(isset($workspace['exportLists']['authorities'])&&count($workspace['exportLists']['authorities']->export())>0,'Authority export uses the existing configuration permission scope');
    $parent=$requestIds[105];$line=(int)$db->query('SELECT request_line_id FROM inventory_stock_request_lines WHERE request_id='.$parent)->fetchColumn();
    $event=$db->prepare("INSERT INTO inventory_stock_request_events(company_id,request_id,event_type,to_status,reason,actor_id,occurred_at) VALUES(?,?,'submitted','pending_review',?,?,'2026-01-01')");
    $allocate=$db->prepare("INSERT INTO inventory_stock_request_allocations(company_id,request_id,request_line_id,authority_id,source_warehouse_id,source_location_id,destination_warehouse_id,destination_location_id,quantity,status,reserved_at) VALUES(?,?,?,?,?,?,?,?,1,'released','2026-01-01')");
    $req=$db->prepare("INSERT INTO purchase_requisitions(company_id,requisition_number,requester_user_id,requested_date,justification) VALUES(?,?,?,'2026-01-01','Fixture')");
    $link=$db->prepare('INSERT INTO inventory_stock_request_procurements(company_id,request_id,requisition_id,receiving_warehouse_id,receiving_location_id) VALUES(?,?,?,?,?)');
    for($i=1;$i<=105;++$i){$ref=$prefix.'CHILD'.sprintf('%03d',$i);$event->execute([$company,$parent,$ref,$owner]);$allocate->execute([$company,$parent,$line,$authority,$warehouse,$location,$warehouse,$location]);$req->execute([$company,$ref,$owner]);$link->execute([$company,$parent,(int)$db->lastInsertId(),$warehouse,$location]);}
    foreach(['events','allocations','procurements'] as $entity){
        $child=$service->workspace($owner,$parent,[$entity=>['per_page'=>50]]);
        $check($child['lists'][$entity]['pagination']['total']===105&&count($child['stockRequest'][$entity])===50,"Stock $entity scope and count precede paging");
        foreach(['csv','xlsx'] as $format){$file=(new App\Services\DataExchange\ExportService())->register($entity,$format,App\Services\Lists\StockRequestLists::columns($entity),$child['exportLists'][$entity]->export());$check(count($child['exportLists'][$entity]->export())===105&&strlen($file['contents'])>1000,"Stock $entity full $format export");}
        $check(count($service->workspace($owner,$parent,[$entity=>['per_page'=>100,'page'=>2]])['stockRequest'][$entity])===5,"Stock $entity reaches page two");
        $check($service->workspace($owner,$parent,[$entity=>['q'=>'missing-child-fixture']])['stockRequest'][$entity]===[],"Stock $entity empty search stays empty");
    }
    $child=$service->workspace($owner,$parent,['events'=>['q'=>$prefix.'CHILD105']]);
    $check(count($child['stockRequest']['events'])===1&&isset(App\Services\Lists\StockRequestLists::controls('events',$child['exportLists']['events'])['filters']['event']['options']['submitted']),'Stock event search and options use all authorized history');
    $_SESSION['auth']['user_id']=$manager;
    $workspace=$service->workspace($manager,null,['requests'=>['q'=>$prefix]]);
    $check($workspace['lists']['requests']['pagination']['total']===105,'Stock request hierarchy filters before count and page');
    $workspace=$service->workspace($manager,null,['requests'=>['q'=>$prefix.'OTHER']]);
    $check($workspace['stockRequests']===[] && $workspace['exportLists']['requests']->export()===[],'Stock request search and export cannot reveal another requester');
    $_GET=['requests'=>['q'=>'definitely-no-matches']];$workspace=$service->workspace($manager,null,$_GET);
    ob_start();view('inventory.stock-requests',$workspace+['permissions'=>[],'canExport'=>true]);$html=(string)ob_get_clean();
    $check(str_contains($html,'name="requests[q]"') && !str_contains($html,$prefix.'001'),'Empty stock-request view does not refill unfiltered rows');
    $detail=$service->workspace($manager,$requestIds[1],['requests'=>['q'=>'no-match']]);
    $check((int)$detail['stockRequest']['request_id']===$requestIds[1] && $detail['stockRequests']===[],'Detail selection is independent of the filtered request register');
    $query=new App\Services\Lists\ListQuery(['q'=>'x','peers'=>['q'=>'y'],'section'=>'requests','download'=>'csv'],['date'=>'requested_at'],'date');
    $check(str_contains($query->url('/inventory/stock-requests',['page'=>2]),'peers%5Bq%5D=y') && !str_contains($query->resetUrl('/inventory/stock-requests'),'download='),'Primary pagination and clear preserve secondary filters without replaying downloads');
    $_SESSION['auth']['company']['company_id']=$company+100000;
    $denied=false;try{$denied=$factory->listing('stock',['q'=>$prefix])->export()===[];}catch(RuntimeException $e){$denied=true;}
    $check($denied,'Forged company context cannot export Inventory rows');
    $_SESSION['auth']=['user_id'=>$owner,'company'=>['company_id'=>$company]];
    $productQuery=$db->prepare('SELECT product_id FROM sales_products WHERE company_id=? AND sku=?');$productQuery->execute([$company,$prefix.'001']);$historyProduct=(int)$productQuery->fetchColumn();
    $extraMovement=$db->prepare("INSERT INTO inventory_stock_movements(company_id,warehouse_id,location_id,product_id,source_warehouse_id,source_location_id,destination_warehouse_id,destination_location_id,movement_type,requested_quantity,completed_quantity,quantity_delta,reference_type,reference_number,idempotency_key,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $cases=[
        ['receipt',null,null,$warehouse,$location,10,10,'2023-12-31 12:00:00'],
        ['issue',$warehouse,$location,null,null,2,-2,'2024-01-01 10:00:00'],
        ['return_in',null,null,$warehouse,$location,1,1,'2024-01-01 11:00:00'],
        ['transfer_out',$warehouse,$location,$warehouse,$locations[2],3,0,'2024-01-01 12:00:00'],
        ['adjustment_out',$warehouse,$location,null,null,0.5,-0.5,'2024-01-01 13:00:00'],
        ['receipt',$warehouse,$location,null,null,1,-1,'2024-01-01 14:00:00'],
        ['receipt',null,null,$warehouse,$location,null,1,'2024-01-01 15:00:00'],
    ];
    foreach($cases as $index=>[$type,$sw,$sl,$dw,$dl,$quantity,$delta,$at])$extraMovement->execute([$company,$warehouse,$location,$historyProduct,$sw,$sl,$dw,$dl,$type,max(1,$quantity),$quantity,$delta,'test_fixture',$prefix.'CASE'.$index,$prefix.'CASE'.$index,$at]);
    $db->prepare("INSERT INTO inventory_reservation_cutovers(company_id,cutover_at,cutover_event_id,label) VALUES(?,'2023-01-01',0,'Test reservation baseline') ON DUPLICATE KEY UPDATE cutover_at=VALUES(cutover_at),cutover_event_id=0,label=VALUES(label)")->execute([$company]);
    $balanceQuery=$db->prepare('SELECT stock_balance_id FROM inventory_stock_balances WHERE company_id=? AND warehouse_id=? AND location_id=? AND product_id=?');$balanceQuery->execute([$company,$warehouse,$location,$historyProduct]);$balance=(int)$balanceQuery->fetchColumn();
    $db->prepare("INSERT INTO inventory_reservation_events(company_id,stock_balance_id,warehouse_id,location_id,product_id,quantity_delta,quantity_after,event_type,reference_type,occurred_at) VALUES(?,?,?,?,?,2,2,'088_cutover_baseline','test_fixture','2024-01-01 16:00:00')")->execute([$company,$balance,$warehouse,$location,$historyProduct]);
    foreach(['2024-01-01',date('Y-m-d')] as $date){
        $input=['warehouse_id'=>$warehouse,'date'=>$date,'product_id'=>$historyProduct];
        $old=$historyService->report($owner,$input);$new=$historyService->report($owner,$input,true);
        foreach(['beginning','received','transfers_in','returns_in','sold_issued','transfers_out','returns_out','adjustments','unclassified','ending','reserved','available','balance_difference','reservation_difference'] as $field)
            $check(($old['rows'][0][$field]??null)===null?($new['rows'][0][$field]??null)===null:abs((float)$old['rows'][0][$field]-(float)$new['rows'][0][$field])<0.0001,"$date stock $field matches the authoritative legacy calculation");
        $check(count($old['details'])===count($new['exportLists']['legs']->export()),"$date stock history retains both endpoint legs");
        $check(count($old['unclassified'])===$new['issueTotal'],"$date stock history preserves movement exceptions");
    }
    $historical=$historyService->report($owner,['warehouse_id'=>$warehouse,'date'=>'2024-01-01','product_id'=>$historyProduct],true);
    $check((float)$historical['rows'][0]['ending']===12.5&&(float)$historical['rows'][0]['reserved']===2.0,'Endpoint history includes zero-delta transfers and exact reservations');
    $db->prepare("UPDATE inventory_reservation_cutovers SET cutover_at='2025-01-01' WHERE company_id=?")->execute([$company]);
    $before=$historyService->report($owner,['warehouse_id'=>$warehouse,'date'=>'2024-01-01','product_id'=>$historyProduct],true);
    $check(!$before['reservationExact']&&$before['rows'][0]['reserved']===null&&$before['rows'][0]['available']===null,'Pre-cutover reservation and available quantities remain unknown, never zero');
    $denied=false;try{$historyService->report($manager,['warehouse_id'=>$otherWarehouse,'date'=>'2024-01-01'],true);}catch(RuntimeException $error){$denied=true;}
    $check($denied,'Daily history rejects a warehouse outside the actor reporting scope');
} finally {if($db->inTransaction())$db->rollBack();}
echo "$passed Inventory smart-list checks passed".PHP_EOL;
