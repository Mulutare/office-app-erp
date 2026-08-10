<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\DataExchange\ExternalIdService;
use App\Services\DataExchange\ImportService;

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
}finally{$connection->rollBack();}
echo 'Data exchange integration checks: '.(9-$failures).' passed, '.$failures.' failed'.PHP_EOL;exit($failures===0?0:1);
