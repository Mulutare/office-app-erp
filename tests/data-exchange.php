<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\DataExchange\CsvCodec;
use App\Services\DataExchange\ExchangeField;
use App\Services\DataExchange\FileGuard;
use App\Services\DataExchange\ImportValidator;
use App\Services\DataExchange\SchemaRegistry;
use App\Services\DataExchange\SpreadsheetCodec;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$failures=0;$check=static function(bool $condition,string $label)use(&$failures):void{echo($condition?'PASS ':'FAIL ').$label.PHP_EOL;if(!$condition)++$failures;};
$directory=sys_get_temp_dir().'/officeapp-data-exchange-'.bin2hex(random_bytes(5));mkdir($directory,0700,true);
try{
    $schemas=new SchemaRegistry();$customers=$schemas->get('customers');
    $mapping=$customers->autoMap(['External ID','Customer Code','NAME','E-mail']);
    $check($mapping[0]==='external_id'&&$mapping[1]==='customer_number'&&$mapping[2]==='name','Schema aliases automatically map normalized spreadsheet headers');
    $manual=[0=>'external_id',1=>'name'];$validated=(new ImportValidator())->validate($customers,[['c1','Acme']],$manual);
    $check($validated['result']->valid===0&&count($validated['result']->errors)===1,'Required-field validation reports row-level errors');

    $csv=(new CsvCodec())->write(['Name','Value'],[['name'=>'=2+2','value'=>'safe']]);
    $check(str_contains($csv,"'=2+2"),'CSV export neutralizes formula injection');
    $csvPath=$directory.'/safe.csv';file_put_contents($csvPath,"Name,Email\nAcme,info@example.test\n");
    $read=(new CsvCodec())->read($csvPath);$check($read['headers']===['Name','Email']&&count($read['rows'])===1,'CSV reader preserves headers and rows');
    file_put_contents($csvPath,"Name\n=CMD()\n");
    try{(new CsvCodec())->read($csvPath);$check(false,'CSV reader rejects formulas');}catch(Throwable){$check(true,'CSV reader rejects formulas');}

    $xlsx=(new SpreadsheetCodec())->write(['External ID','Name'],[['external_id'=>'c1','name'=>'Acme']],$customers);
    $xlsxPath=$directory.'/customers.xlsx';file_put_contents($xlsxPath,$xlsx);
    $check(str_starts_with($xlsx,'PK'),'XLSX writer creates a real ZIP-based workbook');
    $check((new FileGuard())->validate($xlsxPath,'customers.xlsx')==='xlsx','File guard validates XLSX structure and MIME');
    $xlsxRead=(new SpreadsheetCodec())->read($xlsxPath);$check($xlsxRead['headers']===['External ID','Name'],'XLSX output can be reopened by the spreadsheet library');
    $products=$schemas->get('products');$typed=(new SpreadsheetCodec())->write(['SKU','Sale Price'],[['sku'=>'=unsafe','unit_price'=>125.5]],$products);file_put_contents($directory.'/typed.xlsx',$typed);$typedBook=IOFactory::load($directory.'/typed.xlsx');
    $check($typedBook->getActiveSheet()->getCell('B2')->getDataType()===DataType::TYPE_NUMERIC,'XLSX export preserves numeric business cells');
    $check(str_starts_with((string)$typedBook->getActiveSheet()->getCell('A2')->getValue(),"'="),'XLSX export neutralizes formula-like user text');$typedBook->disconnectWorksheets();
    $formulaBook=new Spreadsheet();$formulaBook->getActiveSheet()->setCellValue('A1','Name')->setCellValue('A2','=2+2');(new Xlsx($formulaBook))->save($directory.'/formula.xlsx');
    try{(new SpreadsheetCodec())->read($directory.'/formula.xlsx');$check(false,'XLSX reader rejects formulas');}catch(Throwable){$check(true,'XLSX reader rejects formulas');}

    $check(count($schemas->all())>=30,'Registry covers Sales, Inventory, Finance and report objects');
    $check(!$schemas->get('stock')->canImport&&$schemas->get('stock')->canExport,'Stock is export-only and cannot bypass movement services');
    $check(!$schemas->get('general-ledger')->canImport&&!$schemas->get('general-ledger')->canExport,'Unconnected finance reports are not advertised as working exchange objects');
    $check(!$schemas->get('warehouses')->canImport&&$schemas->get('warehouses')->canExport,'Warehouse import is hidden until a domain-safe update path exists');
    $check($schemas->get('suppliers')->canImport&&$schemas->get('suppliers')->canExport,'Suppliers expose safe create-only import, template and export capability');
    $exportView=file_get_contents(__DIR__.'/../resources/views/data-exchange/export.php');
    $check(is_string($exportView)&&str_contains($exportView,'Available fields')&&str_contains($exportView,'Selected fields')&&str_contains($exportView,'Import-compatible export'),'Visible export form supports selection, ordering and import-compatible mode');
    $salesView=file_get_contents(__DIR__.'/../resources/views/sales/index.php');
    $tablePosition=strpos((string)$salesView,"section==='orders'?'Sales Orders'");
    $formReplayPosition=strpos((string)$salesView,'echo $ordersCreateForm');
    $check($tablePosition!==false&&$formReplayPosition!==false&&$tablePosition<$formReplayPosition,'Sales Orders table renders before its create form');
}finally{
    foreach(glob($directory.'/*')?:[] as $file)unlink($file);rmdir($directory);
}
echo 'Data exchange checks: '.(18-$failures).' passed, '.$failures.' failed'.PHP_EOL;exit($failures===0?0:1);
