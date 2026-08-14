<?php

declare(strict_types=1);

use App\Repositories\RepositoryFactory;
use App\Services\InventoryService;
use App\Services\ProcurementService;
use App\Services\SalesService;
use App\Services\WarehouseManagementService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$pdo=db();$failures=[];$passed=0;
$check=static function(bool $condition,string $description)use(&$failures,&$passed):void{fwrite($condition?STDOUT:STDERR,($condition?'PASS ':'FAIL ').$description.PHP_EOL);if($condition)$passed++;else$failures[]=$description;};
$throws=static function(callable $work,string $description)use($check):void{try{$work();$check(false,$description);}catch(Throwable){$check(true,$description);}};
$company=(int)$pdo->query("SELECT company_id FROM companies WHERE code='default' LIMIT 1")->fetchColumn();
$otherCompany=(int)$pdo->query("SELECT company_id FROM companies WHERE company_id<>$company ORDER BY company_id LIMIT 1")->fetchColumn();
$users=$pdo->query('SELECT user_id FROM users ORDER BY user_id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$actor=(int)($users[0]??0);$approver=(int)($users[1]??0);
$department=(int)$pdo->query("SELECT department_id FROM hr_departments WHERE company_id=$company AND active=TRUE AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$product=(int)$pdo->query("SELECT product_id FROM sales_products WHERE company_id=$company AND active=TRUE AND deleted_at IS NULL AND product_type<>'service' LIMIT 1")->fetchColumn();
$_SESSION['auth']=['user_id'=>$actor,'company'=>['company_id'=>$company]];
$warehouse=(int)$pdo->query("SELECT warehouse_id FROM inventory_warehouses WHERE company_id=$company AND active=TRUE AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$suffix=strtoupper(bin2hex(random_bytes(4)));
if($department<1){$s=$pdo->prepare("INSERT INTO hr_departments(company_id,code,name,description,active,created_by,updated_by) VALUES(:company,:code,:name,'Disposable Procurement integration fixture',TRUE,:created_by,:updated_by)");$s->execute(['company'=>$company,'code'=>'E2E-DEPT-'.$suffix,'name'=>'E2E Procurement Department','created_by'=>$actor,'updated_by'=>$actor]);$department=(int)$pdo->lastInsertId();}
$productFixture=[];$warehouseFixture=[];
if($product<1){$productFixture=(new SalesService())->createProduct(['sku'=>'E2E-PROD-'.$suffix,'name'=>'E2E Procurement Product','category'=>'Release Test','product_type'=>'stockable','unit_of_measure'=>'unit','unit_price'=>'100','commission_rate'=>'0','serial_tracking'=>false],$actor);$product=(int)($productFixture['id']??0);}
if($warehouse<1){$warehouseFixture=(new WarehouseManagementService())->create(['code'=>'E2E-WH-'.$suffix,'name'=>'E2E Procurement Warehouse','warehouse_type'=>'standard','branch_id'=>null,'manager_user_id'=>null,'address'=>null,'phone'=>null,'email'=>null,'allow_negative_stock'=>false,'is_default'=>true,'active'=>true],$actor);$warehouse=(int)($warehouseFixture['warehouseId']??0);}
if(min($company,$actor,$approver,$department,$product,$warehouse)<1){fwrite(STDERR,'FAIL Procurement fixtures are unavailable: '.json_encode(['company'=>$company,'actor'=>$actor,'approver'=>$approver,'department'=>$department,'product'=>$product,'warehouse'=>$warehouse,'product_fixture'=>$productFixture,'warehouse_fixture'=>$warehouseFixture]).PHP_EOL);exit(1);}
$service=new ProcurementService();$inventory=new InventoryService();
$balance=static function()use($pdo,$company,$warehouse,$product):float{$s=$pdo->prepare("SELECT COALESCE(SUM(b.quantity_on_hand),0) FROM inventory_stock_balances b JOIN inventory_warehouse_locations l ON l.company_id=b.company_id AND l.warehouse_id=b.warehouse_id AND l.location_id=b.location_id WHERE b.company_id=:company AND b.warehouse_id=:warehouse AND b.product_id=:product AND l.location_usage='internal'");$s->execute(['company'=>$company,'warehouse'=>$warehouse,'product'=>$product]);return(float)$s->fetchColumn();};
try{
    $supplier=$service->createSupplier(['supplier_code'=>'E2E-RC-'.$suffix,'business_name'=>'E2E Release Supplier '.$suffix,'currency'=>'KES','payment_terms_days'=>30],$actor);
    $check($supplier>0,'Supplier is created in the active company');
    $throws(fn()=>$service->createSupplier(['supplier_code'=>'E2E-RC-'.$suffix,'business_name'=>'Duplicate','currency'=>'KES'],$actor),'Duplicate supplier code is rejected');
    $service->updateSupplier($supplier,['supplier_code'=>'E2E-RC-'.$suffix,'business_name'=>'E2E Release Supplier Updated '.$suffix,'currency'=>'KES','payment_terms_days'=>14],$actor);$check(true,'Supplier is edited');
    $service->setSupplierActive($supplier,false,$actor);$service->setSupplierActive($supplier,true,$actor);$check(true,'Supplier deactivation and activation are audited mutations');
    $requisition=$service->createRequisition(['department_id'=>$department,'warehouse_id'=>$warehouse,'required_by_date'=>date('Y-m-d',strtotime('+7 days')),'justification'=>'E2E-RC controlled replenishment','product_id'=>[$product],'description'=>['Release candidate inventory'],'quantity'=>[10],'unit_price'=>[100],'tax_rate'=>[0]],$actor);
    $check($requisition>0&&$pdo->query("SELECT department_id FROM purchase_requisitions WHERE requisition_id=$requisition")->fetchColumn()==$department,'Requisition persists its tenant department');
    $service->transitionRequisition($requisition,'submit',$actor);
    $throws(fn()=>$service->transitionRequisition($requisition,'approve',$actor),'Requester cannot approve their own requisition');
    $service->transitionRequisition($requisition,'approve',$approver);$check(true,'Independent approver approves the submitted requisition');
    $order=$service->createOrder(['supplier_id'=>$supplier,'warehouse_id'=>$warehouse,'requisition_id'=>$requisition,'expected_date'=>date('Y-m-d',strtotime('+5 days'))],$actor);
    $check($order>0,'Approved requisition converts to a purchase order');
    $throws(fn()=>$service->createOrder(['supplier_id'=>$supplier,'warehouse_id'=>$warehouse,'requisition_id'=>$requisition],$actor),'Duplicate requisition conversion is rejected');
    $po=$pdo->query("SELECT subtotal,tax_amount,total_amount FROM purchase_orders WHERE purchase_order_id=$order")->fetch(PDO::FETCH_ASSOC);$check((float)$po['subtotal']===1000.0&&(float)$po['total_amount']===1000.0,'Purchase order totals are calculated server-side');
    $service->transitionOrder($order,'submit',$actor);$service->transitionOrder($order,'approve',$approver);$service->transitionOrder($order,'confirm',$actor);$check(true,'PO submit, independent approval and confirmation succeed');
    $throws(fn()=>$service->transitionOrder($order,'confirm',$actor),'Invalid repeated PO confirmation is rejected');
    $line=(int)$pdo->query("SELECT purchase_order_line_id FROM purchase_order_lines WHERE purchase_order_id=$order")->fetchColumn();$stock0=$balance();
    $receipt1=$service->createReceipt($order,[$line=>4],$actor);$inventory->approveGoodsReceipt($receipt1,$approver);$post1=$inventory->postGoodsReceipt($receipt1,$actor);$check(!empty($post1['successful'])&&abs($balance()-$stock0-4)<0.0005,'Partial receipt increases stock by exactly four');
    $replay=$inventory->postGoodsReceipt($receipt1,$actor);$check(!empty($replay['result']['replayed'])&&abs($balance()-$stock0-4)<0.0005,'Duplicate receipt posting is idempotent');
    $throws(fn()=>$service->createReceipt($order,[$line=>7],$actor),'Over-receipt is rejected atomically');
    $receipt2=$service->createReceipt($order,[$line=>6],$actor);$inventory->approveGoodsReceipt($receipt2,$approver);$inventory->postGoodsReceipt($receipt2,$actor);$check(abs($balance()-$stock0-10)<0.0005,'Final receipt reconciles total stock to ten');
    $returnKey='E2E-RETURN-'.$suffix;$return=$service->postVendorReturn($order,['reason'=>'Damaged on inspection','idempotency_key'=>$returnKey,'quantity'=>[$line=>2]],$actor);$check(empty($return['replayed'])&&abs($balance()-$stock0-8)<0.0005,'Vendor return moves two units from Stock to Vendor');
    $returnReplay=$service->postVendorReturn($order,['reason'=>'Damaged on inspection','idempotency_key'=>$returnKey,'quantity'=>[$line=>2]],$actor);$check(!empty($returnReplay['replayed'])&&abs($balance()-$stock0-8)<0.0005,'Duplicate vendor return key does not move stock twice');
    $throws(fn()=>$service->postVendorReturn($order,['reason'=>'Too many','idempotency_key'=>'E2E-OVER-'.$suffix,'quantity'=>[$line=>9]],$actor),'Excessive vendor return is rejected');
    $bill=$service->createBill($order,'SUP-'.$suffix,$actor);$billQty=(float)$pdo->query("SELECT quantity FROM finance_invoice_lines WHERE invoice_id=$bill")->fetchColumn();$check(abs($billQty-8)<0.0005,'Supplier bill uses net received quantity after returns');
    $service->postBill($bill,$actor);$batch=(int)$pdo->query("SELECT journal_batch_id FROM finance_invoices WHERE invoice_id=$bill")->fetchColumn();$journal=$pdo->query("SELECT total_debit,total_credit FROM finance_journal_batches WHERE journal_batch_id=$batch")->fetch(PDO::FETCH_ASSOC);$check(abs((float)$journal['total_debit']-(float)$journal['total_credit'])<0.005,'Supplier bill journal is balanced');
    $reversal=$service->reverseBill($bill,$approver);$check(empty($reversal['replayed'])&&(float)$pdo->query("SELECT billed_quantity FROM purchase_order_lines WHERE purchase_order_line_id=$line")->fetchColumn()===0.0,'Bill reversal restores billable quantity');
    $reversalReplay=$service->reverseBill($bill,$approver);$check(!empty($reversalReplay['replayed'])&&!$pdo->inTransaction(),'Duplicate bill reversal closes its transaction and replays safely');
    $bill2=$service->createBill($order,'SUP-REBILL-'.$suffix,$actor);$service->postBill($bill2,$actor);$billTotal=(float)$pdo->query("SELECT total_amount FROM finance_invoices WHERE invoice_id=$bill2")->fetchColumn();
    RepositoryFactory::finance()->ensureSystemJournals($company,'KES',$actor);$cashJournal=(int)$pdo->query("SELECT journal_id FROM finance_journals WHERE company_id=$company AND journal_type IN('cash','bank') AND active=TRUE LIMIT 1")->fetchColumn();
    $paymentKey='E2E-PAY-1-'.$suffix;$first=$service->payBill($bill2,['amount'=>$billTotal/2,'journal_id'=>$cashJournal,'payment_date'=>date('Y-m-d'),'method'=>'bank_transfer','idempotency_key'=>$paymentKey],$actor);$firstReplay=$service->payBill($bill2,['amount'=>$billTotal/2,'journal_id'=>$cashJournal,'payment_date'=>date('Y-m-d'),'method'=>'bank_transfer','idempotency_key'=>$paymentKey],$actor);$check(empty($first['replayed'])&&!empty($firstReplay['replayed']),'Partial supplier payment is idempotent');
    $throws(fn()=>$service->payBill($bill2,['amount'=>$billTotal,'journal_id'=>$cashJournal,'payment_date'=>date('Y-m-d'),'method'=>'bank_transfer','idempotency_key'=>'E2E-OVERPAY-'.$suffix],$actor),'Payment above residual is rejected');
    $service->payBill($bill2,['amount'=>$billTotal/2,'journal_id'=>$cashJournal,'payment_date'=>date('Y-m-d'),'method'=>'bank_transfer','idempotency_key'=>'E2E-PAY-2-'.$suffix],$actor);$final=$pdo->query("SELECT residual_amount,payment_status FROM finance_invoices WHERE invoice_id=$bill2")->fetch(PDO::FETCH_ASSOC);$check((float)$final['residual_amount']===0.0&&$final['payment_status']==='paid','Final payment clears accounts payable');
    $auditCount=(int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE company_id=$company AND module='procurement' AND record_id IN ('$supplier','$requisition','$order','$bill','$bill2')")->fetchColumn();$eventCount=(int)$pdo->query("SELECT COUNT(*) FROM integration_outbox WHERE company_id=$company AND aggregate_id='$order' AND event_type IN ('VendorReturnPosted','SupplierBillCreated','SupplierBillReversed')")->fetchColumn();$check($auditCount>=10&&$eventCount>=3,'Workflow writes company-scoped audit and outbox evidence');
    if($otherCompany>0){$_SESSION['auth']['company']['company_id']=$otherCompany;$other=new ProcurementService();$check(empty(array_filter($other->workspace()['orders'],static fn($row)=>(int)$row['purchase_order_id']===$order)),'Company B workspace cannot see Company A purchase order');$throws(fn()=>$other->postVendorReturn($order,['reason'=>'Tamper','idempotency_key'=>'TENANT-'.$suffix,'quantity'=>[$line=>1]],$actor),'Company B cannot return Company A purchase order');$_SESSION['auth']['company']['company_id']=$company;}
}catch(Throwable $e){$check(false,'Unexpected Procurement E2E exception: '.$e->getMessage());}
fwrite(STDOUT,sprintf('Procurement integration: %d passed, %d failed.%s',$passed,count($failures),PHP_EOL));exit($failures===[]?0:1);
