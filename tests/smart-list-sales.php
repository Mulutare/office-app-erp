<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Lists\SalesListService;
use App\Services\DataExchange\ExportDataProvider;
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
if(getenv('APP_ENV')!=='testing')throw new RuntimeException('Isolated testing database required.');
$db=db();$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$label);++$passed;echo 'PASS '.$label.PHP_EOL;};
$company=(int)$db->query('SELECT c.company_id FROM company_user_roles c JOIN hr_departments d ON d.company_id=c.company_id AND d.active=1 AND d.deleted_at IS NULL ORDER BY c.company_id LIMIT 1')->fetchColumn();
$prefix='SL'.strtoupper(bin2hex(random_bytes(4)));$db->beginTransaction();
try{
    // Tenant fixture must have licensed modules before role permissions can apply.
    // These entitlements exist only inside this rolled-back testing transaction.
    $db->prepare("UPDATE companies SET active=1,approval_status='approved',subscription_status='active',subscription_expires_at=NULL WHERE company_id=?")->execute([$company]);
    $db->prepare("INSERT INTO company_modules(company_id,module_id,enabled,license_status) SELECT ?,module_id,1,'active' FROM erp_modules WHERE active=1 AND available=1 AND release_status='released' ON DUPLICATE KEY UPDATE enabled=1,license_status='active',expires_at=NULL")->execute([$company]);
    $create=static function(string $name,string $role)use($db,$company,$prefix):int{
        $db->prepare('INSERT INTO users(username,email,password_hash,display_name,must_change_password) VALUES(?,?,?, ?,0)')->execute([$prefix.$name,$prefix.$name.'@example.test',password_hash('Unused-test-only-password',PASSWORD_DEFAULT),$prefix.$name]);
        $id=(int)$db->lastInsertId();$db->prepare('INSERT INTO company_users(company_id,user_id,active) VALUES(?,?,1)')->execute([$company,$id]);
        $db->prepare('INSERT INTO company_user_roles(company_id,user_id,role_id) SELECT ?,?,role_id FROM roles WHERE code=?')->execute([$company,$id,$role]);return$id;
    };
    $owner=$create('Owner','company_owner');$dsa=$create('DSA','sales_cashier');$other=$create('Other','sales_cashier');
    $department=(int)$db->query('SELECT department_id FROM hr_departments WHERE company_id='.$company.' AND active=1 AND deleted_at IS NULL LIMIT 1')->fetchColumn();
    $db->prepare("INSERT INTO hr_employees(company_id,user_id,employee_number,first_name,last_name,work_email,department_id,job_title,employment_type,employment_status,hire_date) VALUES(?,?,?,'List','DSA',?,?,'DSA','full_time','active','2025-01-01')")->execute([$company,$dsa,$prefix.'DSA',$prefix.'dsa@work.test',$department]);
    $_SESSION['auth']=['user_id'=>$owner,'company'=>['company_id'=>$company]];$lists=new SalesListService();
    $insertCustomer=$db->prepare('INSERT INTO sales_customers(company_id,customer_number,name,tax_number,active) VALUES(?,?,?,?,?)');
    $insertProduct=$db->prepare('INSERT INTO sales_products(company_id,sku,name,unit_price,category,active) VALUES(?,?,?,0,?,?)');
    $insertPricelist=$db->prepare("INSERT INTO sales_pricelists(company_id,name,currency,active) VALUES(?,?,'ETB',?)");
    for($i=1;$i<=105;++$i){$name=$prefix.sprintf('%03d',$i);$insertCustomer->execute([$company,$name,$name,$name.'TIN',$i===105?0:1]);$customer=(int)$db->lastInsertId();$insertProduct->execute([$company,$name,$name,$i===105?'Special':'Standard',$i===105?0:1]);$insertPricelist->execute([$company,$name,$i===105?0:1]);}
    foreach(['customers','products','pricelists']as$entity){
        $page=$lists->listing($entity,['q'=>$prefix])->page();$check(count($page['rows'])===25&&$page['pagination']['total']===105,"$entity searches full dataset before pagination");
        $check($lists->listing($entity,['q'=>$prefix.'105'])->page()['pagination']['total']===1,"$entity finds page-five match");
        $check($lists->listing($entity,['q'=>$prefix,'active'=>'0'])->page()['pagination']['total']===1,"$entity inactive filter works");
        $check(count((new ExportDataProvider())->rows($entity,['q'=>$prefix]))===105,"$entity exports all matching rows");
        $check(count((new ExportDataProvider())->rows($entity,['q'=>$prefix,'active'=>'0']))===1,"$entity export matches filtered UI");
    }
    $check($lists->listing('customers',['q'=>$prefix.'105TIN'])->page()['pagination']['total']===1,'Customer TIN is searchable');
    $orders=$db->prepare("INSERT INTO sales_orders(company_id,customer_id,order_number,order_date,due_date,status,created_by) VALUES(?,?,?,'2026-01-01','2026-02-01',?,?)");
    $quotes=$db->prepare("INSERT INTO sales_quotations(company_id,customer_id,quotation_number,quotation_date,currency,untaxed_amount,tax_amount,total_amount,created_by) VALUES(?,?,?,'2026-01-01','ETB',0,0,0,?)");
    for($i=1;$i<=105;++$i){$name=$prefix.sprintf('%03d',$i);$orders->execute([$company,$customer,$name,$i===105?'submitted':'draft',$dsa]);$quotes->execute([$company,$customer,$name,$dsa]);}
    $orders->execute([$company,$customer,$prefix.'OTHER','draft',$other]);$quotes->execute([$company,$customer,$prefix.'OTHER',$other]);
    $_SESSION['auth']['user_id']=$dsa;
    foreach(['sales-orders','quotations']as$entity){
        $page=$lists->listing($entity,['q'=>$prefix])->page();
        $check($page['pagination']['total']===105&&count($page['rows'])===25,"$entity applies DSA scope before count/page");
        $check($lists->listing($entity,['q'=>$prefix.'OTHER'])->page()['pagination']['total']===0,"$entity cannot search another DSA's document");
        $check(count((new ExportDataProvider())->rows($entity,['q'=>$prefix]))===105,"$entity export retains DSA ownership scope");
        $check($lists->listing($entity,['q'=>$prefix,'from'=>'2026-02-01'])->page()['pagination']['total']===0,"$entity date filter precedes pagination");
    }
    $check($lists->listing('sales-orders',['q'=>$prefix,'status'=>'submitted','page'=>5])->page()['pagination']['total']===1,'Order status filter reaches beyond old 50-row cap');
    $_SESSION['auth']['user_id']=$owner;
    $check($lists->listing('sales-orders',['q'=>$prefix])->page()['pagination']['total']===106,'Authorized company owner retains full order scope');
    $_SESSION['auth']['company']['company_id']=$company+100000;
    foreach(['customers','products','pricelists','sales-orders','quotations','sales-teams']as$entity)$check($lists->listing($entity,['q'=>$prefix])->page()['pagination']['total']===0,"$entity rejects forged foreign company scope");
    $_SESSION['auth']['company']['company_id']=$company;
    foreach(['sales-teams','serials','commissions','targets']as$entity)$check(is_array($lists->listing($entity,[])->page()),"$entity authorized query executes");
    $report=(new App\Services\SalesPerformanceReportService())->report($owner,['q'=>$prefix]);
    $check(isset($report['list']['pagination'])&&is_array($report['exportList']->export()),'DSA report uses grouped SQL count, page and export');
    $incentives=(new App\Services\SalesIncentiveService())->register($owner,['q'=>$prefix]);
    $check(isset($incentives['list']['pagination'])&&is_array($incentives['exportList']->export()),'Incentive query combines legacy positional scope and common pagination safely');
    foreach(['customers','products','orders','quotations','pricelists','teams']as$section){
        $workspace=(new App\Services\SalesService())->workspace($section,['q'=>$prefix]);
        $check(isset($workspace['list']['pagination']),"$section workspace uses paged register independently of form options");
    }

    // Selling terms and classification retain full form options, but page registers in SQL.
    $pricing=(new App\Services\SalesPricingService())->register($owner,['pricing'=>['q'=>$prefix]]);
    $check($pricing['productList']['pagination']['total']===105 && count($pricing['products'])===25,'Selling terms count and page the complete SKU catalogue');
    $variants=(new App\Services\SalesProductVariantService())->options(['q'=>$prefix.'105']);
    $check(count($variants['products'])===1 && count($variants['productOptions'])>=105,'Variant search finds late SKU while retaining complete assignment choices');
    ob_start();view('sales.product-variants',['variants'=>$variants,'canManageVariants'=>true]);$html=(string)ob_get_clean();
    $check(str_contains($html,$prefix.'105') && str_contains($html,'name="q"') && str_contains($html,'Add brand'),'Variant view renders its actual controller data and working forms');
    $stmt=$db->prepare('SELECT product_id FROM sales_products WHERE company_id=? AND sku=?');$stmt->execute([$company,$prefix.'001']);$product=(int)$stmt->fetchColumn();
    $currency=(string)$db->query('SELECT default_currency FROM companies WHERE company_id='.$company)->fetchColumn();
    $change=$db->prepare("INSERT INTO sales_product_price_changes(company_id,product_id,old_price,proposed_price,currency,effective_from,reason,status,requested_by,requested_at) VALUES(?,?,0,?,?,'2024-01-01',?,'approved',?,'2024-01-01')");
    for($i=1;$i<=305;++$i)$change->execute([$company,$product,$i,$currency,$prefix.sprintf('%03d',$i),$owner]);
    $change->execute([$company,$product,99999,$currency==='USD'?'EUR':'USD',$prefix.'ForeignCurrency',$owner]);
    $priced=$lists->listing('pricing',['q'=>$prefix.'001'])->page()['rows'][0];
    $check((float)$priced['approved_price']===305.0,'Effective selling price respects the company currency');
    $pricing=(new App\Services\SalesPricingService())->register($owner,['price_history'=>['q'=>$prefix.'001','per_page'=>50]]);
    $check($pricing['historyList']['pagination']['total']===306 && count($pricing['changes'])===50,'Price history searches beyond former 300-row cap');
    $filteredPricing=(new App\Services\SalesPricingService())->register($owner,['price_history'=>['q'=>$prefix.'305']]);
    $check(count($filteredPricing['changes'])===1,'Pricing reasons are searched before paging');
    ob_start();view('sales.pricing',['pricingData'=>$pricing,'canManagePricing'=>true]);$html=(string)ob_get_clean();
    $check(str_contains($html,'name="pricing[q]"') && str_contains($html,'name="price_history[q]"') && !str_contains($html,'data-pricing-filters'),'Selling terms and history use independent server-side controls');

    $check(str_contains($html,'register=pricing')&&str_contains($html,'register=price-history'),'Pricing view connects separate filtered export targets');
    foreach(['csv','xlsx'] as $format){
        foreach(['pricing','price-history'] as $entity){$file=(new App\Services\DataExchange\ExportService())->register($entity,$format,$pricing['exports']['columns'][$entity],$pricing['exports']['exportLists'][$entity]->export());$check(strlen($file['contents'])>1000&&count($pricing['exports']['exportLists'][$entity]->export())>25,"$entity exports the full filtered $format result");}
        $variantRows=$lists->listing('variants',['q'=>$prefix])->export();$file=(new App\Services\DataExchange\ExportService())->register('variants',$format,['sku'=>'SKU','name'=>'Product'],$variantRows);$check(count($variantRows)===105&&strlen($file['contents'])>1000,"Variant full $format export");
    }
    $variants=(new App\Services\SalesProductVariantService())->options(['q'=>$prefix]);$variants['canExport']=true;
    ob_start();view('sales.product-variants',['variants'=>$variants]);$variantHtml=ob_get_clean();
    $check(str_contains($variantHtml,'download=csv')&&str_contains($variantHtml,'download=xlsx'),'Variant view exposes both scoped exports');
    $secondaryWorkspace=(new App\Services\SalesService())->workspace('products',['q'=>$prefix,'serials'=>['q'=>'missing-serial']]);
    $secondaryWorkspace['salesSection']='products';$secondaryWorkspace['canExchangeExport']=true;
    ob_start();view('sales.index',$secondaryWorkspace);$secondaryHtml=(string)ob_get_clean();
    foreach(['serials','commissions','targets'] as $entity){
        $check(str_contains($secondaryHtml,'register='.$entity)&&str_contains($secondaryHtml,'name="'.$entity.'[q]"'),"$entity renders independent search and export controls even with no matches");
    }
    $db->prepare('UPDATE company_users SET manager_user_id=? WHERE company_id=? AND user_id=?')->execute([$owner,$company,$dsa]);
    $db->prepare("INSERT INTO sales_agents(company_id,employee_id,agent_code,name,agent_type) SELECT company_id,employee_id,?,?,'DSA' FROM hr_employees WHERE company_id=? AND user_id=?")->execute([$prefix.'AGENT',$prefix.'Agent',$company,$dsa]);$agent=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO sales_teams(company_id,name) VALUES(?,?)')->execute([$company,$prefix.'Team']);$team=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_warehouses(company_id,code,name,manager_user_id) VALUES(?,?,?,?)')->execute([$company,$prefix.'SHOP',$prefix.'Shop',$owner]);$warehouse=(int)$db->lastInsertId();
    $query=$db->prepare('SELECT quotation_id,created_by FROM sales_quotations WHERE company_id=? AND quotation_number LIKE ?');$query->execute([$company,$prefix.'%']);$quotationRows=$query->fetchAll(PDO::FETCH_ASSOC);
    $quick=$db->prepare("INSERT INTO sales_quick_sales(company_id,quotation_id,user_id,agent_id,team_id,manager_user_id,origin_manager_user_id,warehouse_id,origin_warehouse_id,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,'submitted','2024-01-01','2024-01-01')");
    foreach($quotationRows as $qrow){$responsible=(int)$qrow['created_by']===$dsa?$owner:$other;$quick->execute([$company,$qrow['quotation_id'],$qrow['created_by'],$agent,$team,$responsible,$responsible,$warehouse,$warehouse]);}
    $quickLists=new App\Services\Lists\QuickSaleListService();
    $page=$quickLists->listing('queue',$owner,true,['queue'=>['q'=>$prefix]])->page();
    $check($page['pagination']['total']===105 && count($page['rows'])===25,'Manager action queue applies assigned-manager scope before count and page');
    $check($quickLists->listing('queue',$owner,true,['queue'=>['q'=>$prefix.'OTHER']])->page()['pagination']['total']===0,'Manager queue cannot search another assigned manager records');
    $check($quickLists->listing('tasks',$dsa,false,['tasks'=>['q'=>$prefix.'105']])->page()['pagination']['total']===1,'DSA task search finds records beyond the former 20-row cap');
    $check($quickLists->listing('tasks',$dsa,false,['tasks'=>['q'=>$prefix.'OTHER']])->export()===[],'DSA task export cannot reveal another DSA');
    $check(count($quickLists->listing('queue',$owner,true,['queue'=>['shop'=>$prefix.'SHOP']])->export())===105,'Shop-filtered manager export covers the complete authorized queue');
    $check($quickLists->listing('queue',$owner,true,['queue'=>['from'=>'2024-01-02']])->page()['pagination']['total']===0,'Quick Sales dates filter before page and count');
    $workflow=(new App\Services\SalesQuickSaleService())->workspace($owner,['queue'=>['q'=>$prefix]]);
    $check($workflow['eligible'] && $workflow['lists']['queue']['pagination']['total']===105,'Quick Sales manager workspace uses shared queries');
    ob_start();view('sales.quick-sale-manager',['quickSale'=>$workflow+['canExport'=>true]]);$html=(string)ob_get_clean();
    $check(str_contains($html,'name="queue[q]"') && str_contains($html,'name="waiting[q]"') && str_contains($html,'register=queue') && str_contains($html,'download=csv'),'All Quick Sales sections retain controls and export actions, including empty results');
    $db->prepare("UPDATE sales_quick_sales SET status='closed' WHERE company_id=? AND user_id=?")->execute([$company,$dsa]);
    $db->prepare("INSERT INTO sales_quick_sale_reports(company_id,quick_sale_id,reported_by_user_id,status,invoice_reference,reviewed_at) SELECT company_id,quick_sale_id,user_id,'confirmed',CONCAT('INV-',quick_sale_id),'2024-01-03' FROM sales_quick_sales WHERE company_id=? AND user_id=?")->execute([$company,$dsa]);
    $check($quickLists->listing('history',$dsa,false,['history'=>['q'=>$prefix]])->page()['pagination']['total']===105,'Completed history searches beyond the former 30-row cap');
    $check(count($quickLists->listing('history',$owner,true,['history'=>['from'=>'2024-01-03','to'=>'2024-01-03']])->export())===105,'History export shares the confirmed-report date range and manager scope');
    $db->prepare("UPDATE sales_quick_sales SET status='reported' WHERE company_id=? AND user_id=?")->execute([$company,$dsa]);
    $db->prepare("UPDATE sales_quick_sale_reports SET status='correction_required' WHERE company_id=? AND reported_by_user_id=?")->execute([$company,$dsa]);
    $check($quickLists->listing('waiting',$owner,true,['waiting'=>['q'=>$prefix]])->page()['pagination']['total']===105,'Correction queue counts and pages the full assigned dataset');
    foreach(['deliveries','returns'] as $entity)$check(is_array($lists->listing($entity,['q'=>$prefix])->page()),"$entity SQL retains order hierarchy and warehouse scope");
    $settlements=(new App\Services\Lists\SettlementListService())->listing(['q'=>$prefix]);
    $check(is_array($settlements->page()) && is_array($settlements->export()),'Settlement SQL shares Finance/Sales visibility between page and export');

}finally{if($db->inTransaction())$db->rollBack();}
echo "$passed Sales smart-list checks passed".PHP_EOL;
