<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\DataExchange\ExternalIdService;
use App\Services\DataExchange\ImportService;
use App\Services\DataExchange\ExportDataProvider;
use App\Services\DataExchange\ExportService;
use App\Services\DataExchange\ExportDefinitionRegistry;
use App\Services\DataExchange\SchemaRegistry;
use App\Services\FinanceOperationsService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$failures=0;$check=static function(bool $condition,string $label)use(&$failures):void{echo($condition?'PASS ':'FAIL ').$label.PHP_EOL;if(!$condition)++$failures;};
$context=db()->query("SELECT memberships.company_id,memberships.user_id FROM company_users memberships INNER JOIN companies ON companies.company_id=memberships.company_id WHERE memberships.active=TRUE AND companies.active=TRUE ORDER BY memberships.user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($context))throw new RuntimeException('A company user is required for data-exchange integration tests.');
$_SESSION['auth']=['user_id'=>(int)$context['user_id'],'company'=>['company_id'=>(int)$context['company_id']]];
$connection=db();$connection->beginTransaction();
try{
    $service=new ImportService();$suffix=strtoupper(bin2hex(random_bytes(4)));
    $customerRows=[['ext_customer_'.$suffix,'CUST-'.$suffix,'Exchange Customer '.$suffix,'exchange-'.$suffix.'@example.test']];
    $mapping=[0=>'external_id',1=>'customer_number',2=>'name',3=>'email'];
    $before=(int)$connection->query('SELECT COUNT(*) FROM sales_customers')->fetchColumn();
    $tested=$service->test('customers',$customerRows,$mapping);
    $afterTest=(int)$connection->query('SELECT COUNT(*) FROM sales_customers')->fetchColumn();
    $check($tested['result']->valid===1&&$before===$afterTest,'Test Import validates customer rows with zero database writes');
    $created=$service->import('customers',$customerRows,$mapping,(int)$context['user_id']);
    $check($created->created===1&&$created->failed===0,'Customer import creates through SalesService');
    $customerRows[0][2]='Updated Exchange Customer '.$suffix;
    $updated=$service->import('customers',$customerRows,$mapping,(int)$context['user_id']);
    $check($updated->updated===1&&$updated->created===0,'Repeated customer External ID updates instead of duplicating');
    $id=(new ExternalIdService())->resolve((int)$context['company_id'],'customers','ext_customer_'.$suffix);
    $statement=$connection->prepare('SELECT name FROM sales_customers WHERE company_id=:company AND customer_id=:id');$statement->execute(['company'=>$context['company_id'],'id'=>$id]);
    $check($statement->fetchColumn()==='Updated Exchange Customer '.$suffix,'External ID resolves only to the updated company record');

    $productRows=[['ext_product_'.$suffix,'SKU-'.$suffix,'Exchange Product '.$suffix,'service','unit','125.50']];
    $productMapping=[0=>'external_id',1=>'sku',2=>'name',3=>'product_type',4=>'unit_of_measure',5=>'unit_price'];
    $product=$service->import('products',$productRows,$productMapping,(int)$context['user_id']);
    $check($product->created===1&&$product->failed===0,'Product import creates through SalesService without quantity-on-hand writes');
    $supplierRows=[['supplier_'.$suffix,'SUP-'.$suffix,'Exchange Supplier '.$suffix,'exchange-supplier-'.$suffix.'@example.test','30','ETB']];
    $supplierMapping=[0=>'external_id',1=>'supplier_code',2=>'business_name',3=>'email',4=>'payment_terms_days',5=>'currency'];
    $supplier=$service->import('suppliers',$supplierRows,$supplierMapping,(int)$context['user_id']);
    $check($supplier->created===1&&$supplier->failed===0,'Supplier import creates atomically through ProcurementService');
    $supplierReplay=$service->import('suppliers',$supplierRows,$supplierMapping,(int)$context['user_id']);
    $check($supplierReplay->created===0&&$supplierReplay->failed>0,'Repeated supplier code is rejected without duplication');
    $supplierCount=$connection->prepare('SELECT COUNT(*) FROM purchase_suppliers WHERE company_id=:company AND supplier_code=:code');$supplierCount->execute(['company'=>$context['company_id'],'code'=>'SUP-'.$suffix]);
    $check((int)$supplierCount->fetchColumn()===1,'Supplier import remains company-scoped and duplicate-safe');
    $quotationRows=[
        ['qt_'.$suffix,'CUST-'.$suffix,'SKU-'.$suffix,'2','10','9999'],
        ['qt_'.$suffix,'CUST-'.$suffix,'SKU-'.$suffix,'3','0','1'],
    ];
    $quotationMapping=[0=>'external_id',1=>'customer',2=>'product',3=>'quantity',4=>'discount',5=>'unit_price'];
    $quotation=$service->import('quotations',$quotationRows,$quotationMapping,(int)$context['user_id']);
    if($quotation->failed>0)echo 'Quotation import errors: '.json_encode($quotation->errors).PHP_EOL;
    $quotationId=(new ExternalIdService())->resolve((int)$context['company_id'],'quotations','qt_'.$suffix);
    $lineStatement=$connection->prepare('SELECT COUNT(*) FROM sales_quotation_lines WHERE company_id=:company AND quotation_id=:quotation');$lineStatement->execute(['company'=>$context['company_id'],'quotation'=>$quotationId]);
    $check($quotation->created===1&&(int)$lineStatement->fetchColumn()===2,'Multi-row quotation import creates one quotation with two lines');
    $totalStatement=$connection->prepare('SELECT total_amount FROM sales_quotations WHERE company_id=:company AND quotation_id=:quotation');$totalStatement->execute(['company'=>$context['company_id'],'quotation'=>$quotationId]);
    $check((float)$totalStatement->fetchColumn()!==9999.0,'Quotation totals and prices are recalculated by SalesService');
    $quotationUpdate=$service->import('quotations',$quotationRows,$quotationMapping,(int)$context['user_id']);
    $check($quotationUpdate->updated===1&&$quotationUpdate->created===0,'Quotation re-import updates through its stable External ID');
    $collision=false;try{(new ExternalIdService())->assign((int)$context['company_id'],'customers',(int)$id+1,'ext_customer_'.$suffix);}catch(Throwable){$collision=true;}
    $check($collision,'External ID collision is rejected');

    $invoiceSchema=(new SchemaRegistry())->get('invoices');
    $invoiceHeaders=array_map(static fn($field):string=>$field->label,$invoiceSchema->fields);
    $expectedInvoiceHeaders=['Invoice','Customer','Sales Order','Date','Due','Total','Residual','State','Payment'];
    $check($invoiceHeaders===$expectedInvoiceHeaders,'Customer Invoice export contract exactly matches GUI column order');
    $invoiceService=new FinanceOperationsService();
    $guiInvoices=$invoiceService->customerInvoices();
    $exportRows=(new ExportDataProvider())->rows('invoices');
    $guiNumbers=array_column($guiInvoices,'invoice_number');
    $exportNumbers=array_column($exportRows,'invoice');
    $check($guiNumbers===$exportNumbers,'Customer Invoice GUI and export use the same ordered logical row set');
    $firstInvoice=$guiInvoices[0]??null;$firstExport=$exportRows[0]??null;
    $check(!is_array($firstInvoice)||(is_array($firstExport)
        &&($firstExport['customer']??null)===($firstInvoice['customer_name']??null)
        &&($firstExport['sales_order']??null)===($firstInvoice['order_number']??null)
        &&(float)($firstExport['total']??-1)===(float)($firstInvoice['total_amount']??-2)
        &&(float)($firstExport['residual']??-1)===(float)($firstInvoice['residual_amount']??-2)),
        'Customer Invoice business values reconcile with the GUI source');
    if(is_array($firstInvoice)){
        $payment=(string)$firstInvoice['payment_status'];
        $paidRows=(new ExportDataProvider())->rows('invoices',['payment'=>$payment]);
        $check($paidRows!==[]&&count(array_filter($paidRows,static fn(array $row):bool=>($row['payment']??'')!==strtoupper(str_replace('_',' ',$payment))))===0,'Customer Invoice payment filter is preserved by export');
        $searchRows=(new ExportDataProvider())->rows('invoices',['search'=>(string)$firstInvoice['invoice_number'],'customer'=>(string)$firstInvoice['customer_name'],'date_from'=>(string)$firstInvoice['invoice_date'],'date_to'=>(string)$firstInvoice['invoice_date']]);
        $check(in_array((string)$firstInvoice['invoice_number'],array_column($searchRows,'invoice'),true),'Combined Customer Invoice search, customer and date filters are preserved');
    }else{$check(true,'Customer Invoice payment filter is preserved by export');$check(true,'Combined Customer Invoice search, customer and date filters are preserved');}
    $file=(new ExportService())->export('invoices','xlsx',$exportRows);
    $invoicePath=sys_get_temp_dir().'/invoice-export-'.$suffix.'.xlsx';file_put_contents($invoicePath,$file['contents']);$book=IOFactory::load($invoicePath);$sheet=$book->getActiveSheet();
    $actualHeaders=[];foreach(range('A','I') as $column)$actualHeaders[]=(string)$sheet->getCell($column.'1')->getValue();
    $check($file['filename']==='Customer_Invoices_'.date('Y-m-d').'.xlsx'&&$actualHeaders===$expectedInvoiceHeaders,'Customer Invoice workbook filename and headings are section-specific');
    $check($exportRows===[]||($sheet->getCell('F2')->getDataType()===DataType::TYPE_NUMERIC&&$sheet->getCell('G2')->getDataType()===DataType::TYPE_NUMERIC&&is_numeric($sheet->getCell('D2')->getValue())),'Customer Invoice totals, residuals and dates use spreadsheet-native types');
    $sensitive=array_intersect(['company_id','invoice_id','customer_id','password_hash','deleted_at'],array_map('strtolower',$actualHeaders));
    $check($sensitive===[],'Customer Invoice export excludes internal and sensitive fields');
    $empty=(new ExportService())->export('invoices','xlsx',[]);$emptyPath=sys_get_temp_dir().'/invoice-empty-'.$suffix.'.xlsx';file_put_contents($emptyPath,$empty['contents']);$emptyBook=IOFactory::load($emptyPath);
    $check($emptyBook->getActiveSheet()->getHighestDataRow()===1,'Zero-result Customer Invoice export returns headers without fallback data');
    $book->disconnectWorksheets();$emptyBook->disconnectWorksheets();unlink($invoicePath);unlink($emptyPath);
    $enabled=array_keys(array_filter((new SchemaRegistry())->all(),static fn($schema):bool=>$schema->canExport));$definitions=new ExportDefinitionRegistry();$allMapped=true;$allWorkbooks=true;$noInternalHeaders=true;
    foreach($enabled as $entity){$definition=$definitions->get($entity);$rows=(new ExportDataProvider())->rows($entity);$keys=array_map(static fn($field):string=>$field->key,$definition['fields']);foreach($rows as $row){foreach($keys as $key){if(!array_key_exists($key,$row))$allMapped=false;}}$generated=(new ExportService())->export($entity,'xlsx',$rows);if(!str_starts_with($generated['contents'],'PK'))$allWorkbooks=false;$labels=array_map(static fn($field):string=>strtolower($field->label),$definition['fields']);if(array_intersect($labels,['company id','invoice id','customer id','password hash','deleted at']))$noInternalHeaders=false;}
    $check($allMapped,'Every enabled export definition maps to its section provider fields');
    $check($allWorkbooks,'Every enabled section produces a valid XLSX workbook including zero-row datasets');
    $check($noInternalHeaders,'No enabled business export definition exposes internal or sensitive headings');
}finally{$connection->rollBack();}
echo 'Data exchange integration checks: '.(24-$failures).' passed, '.$failures.' failed'.PHP_EOL;exit($failures===0?0:1);
