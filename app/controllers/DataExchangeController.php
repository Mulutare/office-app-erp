<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\DataExchange\ExportService;
use App\Services\DataExchange\ExportDataProvider;
use App\Services\DataExchange\ImportService;
use App\Services\DataExchange\SchemaRegistry;
use App\Services\DataExchange\ExportDefinitionRegistry;
use RuntimeException;
use App\Services\DataExchange\ImportConfirmation;
use Throwable;

final class DataExchangeController
{
    private AuthorizationService $authorization;
    private SchemaRegistry $schemas;
    private ImportService $imports;
    private ExportService $exports;
    private ExportDefinitionRegistry $exportDefinitions;

    public function __construct(){ $this->authorization=new AuthorizationService();$this->schemas=new SchemaRegistry();$this->imports=new ImportService();$this->exports=new ExportService();$this->exportDefinitions=new ExportDefinitionRegistry(); }

    public function show(string $entity): void
    {
        $schema=$this->schema($entity,'import');
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Import '.$schema->label,'pageDescription'=>'Map, preview and test spreadsheet rows before any data is written.','contentView'=>'data-exchange.import','schema'=>$schema,'user'=>$_SESSION['auth'],'preview'=>null,'result'=>null,'error'=>null,'moduleContext'=>$this->moduleContext($schema)]);
    }

    public function preview(string $entity): void
    {
        $schema=$this->schema($entity,'import');
        if(!\verifyCsrfToken(\postString('_token'))){http_response_code(419);echo 'Invalid request token.';return;}
        try{
            $mode=$this->importMode($entity);
            $upload=$_FILES['file']??null;if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Choose a CSV or XLSX file.');
            $directory=dirname(__DIR__,2).'/storage/uploads/data-exchange';if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Secure upload storage is unavailable.');
            $token=bin2hex(random_bytes(24));$extension=strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION));$path=$directory.'/'.$token.'.'.$extension;
            if(!move_uploaded_file((string)$upload['tmp_name'],$path))throw new RuntimeException('The uploaded file could not be stored securely.');
            chmod($path,0600);$inspection=$this->imports->inspect($entity,$path,(string)$upload['name']);
            $_SESSION['data_exchange'][$token]=['entity'=>$entity,'path'=>$path,'name'=>(string)$upload['name'],'expires'=>time()+1800,'mode'=>$mode]+ImportConfirmation::context();
            $tested=$this->imports->test($entity,$inspection['rows'],$inspection['mapping'],$mode);
            if ($tested['result']->errors === []) $_SESSION['data_exchange'][$token]['validated'] = ImportConfirmation::fingerprint($path, $inspection['mapping'],$mode);
            $preview=['token'=>$token,'mode'=>$mode,'headers'=>$inspection['headers'],'rows'=>array_slice($inspection['rows'],0,20),'mapping'=>$inspection['mapping'],'result'=>$tested['result']];
            $this->render($schema,$preview,null,null);
        }catch(Throwable $exception){$this->render($schema,null,null,$exception->getMessage());}
    }

    public function execute(string $entity): void
    {
        $schema=$this->schema($entity,'import');if(!\verifyCsrfToken(\postString('_token'))){http_response_code(419);echo 'Invalid request token.';return;}
        try{
            $mode=$this->importMode($entity);
            $token=\postString('upload_token');$stored=$_SESSION['data_exchange'][$token]??null;
            if(!is_array($stored)||($stored['entity']??'')!==$entity||($stored['expires']??0)<time())throw new RuntimeException('Upload session expired. Upload the file again.');
            ImportConfirmation::assertContext($stored);
            $inspection=$this->imports->inspect($entity,(string)$stored['path'],(string)$stored['name']);
            $mapping=[];foreach($inspection['headers'] as $index=>$header){$value=$_POST['mapping'][$index]??'';$mapping[$index]=is_string($value)&&$value!==''?$value:null;}
            if(\postString('action')==='test'){$tested=$this->imports->test($entity,$inspection['rows'],$mapping,$mode);unset($_SESSION['data_exchange'][$token]['validated']);if($tested['result']->errors===[])$_SESSION['data_exchange'][$token]['validated']=ImportConfirmation::fingerprint($stored['path'],$mapping,$mode);$preview=['token'=>$token,'mode'=>$mode,'headers'=>$inspection['headers'],'rows'=>array_slice($inspection['rows'],0,20),'mapping'=>$mapping,'result'=>$tested['result']];$this->render($schema,$preview,null,null);return;}
            if (\postString('action') !== 'import') throw new RuntimeException('Explicit import confirmation is required.');
            ImportConfirmation::assertConfirmed($stored, $mapping,$mode);
            $result=$this->imports->import($entity,$inspection['rows'],$mapping,(int)($_SESSION['auth']['user_id']??0),$mode);
            if(is_file((string)$stored['path']))unlink((string)$stored['path']);unset($_SESSION['data_exchange'][$token]);$this->render($schema,null,$result,null);
        }catch(Throwable $exception){$this->render($schema,null,null,$exception->getMessage());}
    }

    public function template(string $entity): void
    {
        $this->schema($entity,'import');try{$this->download($this->exports->template($entity,strtolower((string)($_GET['format']??'xlsx'))));}catch(Throwable $e){http_response_code(400);echo htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');}
    }

    public function exportForm(string $entity): void
    {
        $schema=$this->schema($entity,'export');
        $definition=$this->exportDefinitions->get($entity);
        $schema=new \App\Services\DataExchange\ExchangeSchema($schema->entity,$schema->label,$schema->module,$definition['fields'],false,$schema->canExport,false);
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Export '.$schema->label,'pageDescription'=>'Choose the file type and arrange the exported fields.','contentView'=>'data-exchange.export','exportFilters'=>$this->exportFilters($entity),'schema'=>$schema,'user'=>$_SESSION['auth'],'moduleContext'=>$this->moduleContext($schema)]);
    }

    public function export(string $entity): void
    {
        $this->schema($entity,'export');
        try{
            $format=strtolower((string)($_GET['format']??'xlsx'));
            $fields=isset($_GET['fields'])&&is_string($_GET['fields'])?array_values(array_filter(explode(',',$_GET['fields']))):null;
            $compatible=isset($_GET['import_compatible'])&&$_GET['import_compatible']==='1';
            if($compatible){$fields=$fields??[];$fields=array_values(array_diff($fields,['external_id']));array_unshift($fields,'external_id');}
            $filters=$this->exportFilters($entity);
            $rows=(new ExportDataProvider())->rows($entity,$filters);
            $limit=max(1,(int)\config('data_exchange.export_max_rows',10000));
            if(count($rows)>$limit)throw new RuntimeException('This export exceeds the '.$limit.' row limit. Narrow the current filters and try again.');
            $selected=isset($_GET['selected'])&&is_string($_GET['selected'])?array_values(array_filter(array_map('intval',explode(',',$_GET['selected'])))):[];
            if($selected!==[]){$idKey=['suppliers'=>'supplier_id','purchase-orders'=>'purchase_order_id','customers'=>'customer_id','products'=>'product_id','pricelists'=>'pricelist_id','sales-teams'=>'team_id','quotations'=>'quotation_id','sales-orders'=>'order_id','warehouses'=>'warehouse_id','locations'=>'location_id','stock'=>'stock_balance_id','receipts'=>'goods_receipt_id','deliveries'=>'picking_id','returns'=>'picking_id','invoices'=>'invoice_id','credit-notes'=>'invoice_id'][$entity]??null;if($idKey!==null)$rows=array_values(array_filter($rows,static fn(array $r):bool=>in_array((int)($r[$idKey]??0),$selected,true)));}
            $this->download($this->exports->export($entity,$format,$rows,$fields));
        }catch(Throwable $e){http_response_code(400);echo htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');}
    }

    private function schema(string $entity,string $operation): \App\Services\DataExchange\ExchangeSchema
    {
        $schema=$this->schemas->get($entity);$this->authorization->requireModule($schema->module);
        if (in_array($entity, ['employees','attendance'], true)) {
            $prefix = $entity === 'employees' ? 'hr' : 'attendance';
            $permissions = $operation === 'import' ? [$prefix.'.records.manage'] : [$prefix.'.records.view', $prefix.'.records.manage'];
            $this->authorization->requireAnyTenantPermission($permissions);
            return $schema;
        }
        $permission=$schema->module.'.'.$operation;
        if($schema->module==='procurement')$permission=$operation==='import'?'procurement.suppliers.manage':'procurement.view';
        $this->authorization->requireTenantPermission($permission);
        if ($schema->module==='sales') {
            $this->authorization->requireTenantPermission('sales.view');
            if($operation==='import')$this->authorization->requireTenantPermission($entity==='quotations'?'sales.orders.create':'sales.catalogue.manage');
            if ($entity==='pricelists') $this->authorization->requireTenantPermission('sales.pricing.view');
            if ($entity==='sales-teams') $this->authorization->requireTenantPermission('sales.catalogue.manage');
        }

        if($schema->module==='inventory') {
            $viewPermission=match($entity) {'warehouses','locations'=>'inventory.warehouses.view','receipts'=>'inventory.receipts.view',default=>'inventory.stock.view'};
            $this->authorization->requireTenantPermission($viewPermission);
        }

        if($operation==='export'&&$schema->entity==='invoices')$this->authorization->requireTenantPermission('finance.records.view');
        if($operation==='import'&&!$schema->canImport)throw new RuntimeException('This object is export-only.');
        if($operation==='export'&&!$schema->canExport)throw new RuntimeException('Export is not connected for this object.');
        return $schema;
    }

    private function importMode(string $entity): string
    {
        $mode=\postString('mode')?:'create';
        if(!in_array($mode,['create','update'],true)||($mode==='update'&&!\App\Services\DataExchange\MasterImportValidator::supportsUpdate($entity)))throw new RuntimeException('Choose an available import mode.');
        return $mode;
    }

    /** @return array<string,string> */
    private function exportFilters(string $entity): array
    {
        $allowed = ['q','search','status','active','department','branch','date','sort','direction','type','category','currency','from','to',
            'payment','date_from','date_to','customer','period','warehouse','location','source','destination'];
        $filters = [];
        foreach ($allowed as $key) {
            $value = $_GET[$key] ?? '';
            $filters[$key] = is_string($value) ? trim($value) : '';
        }
        return $filters;
    }
    /** @param array{contents:string,mime:string,filename:string} $file */
    private function download(array $file):never{header('Content-Type: '.$file['mime']);header('Content-Disposition: attachment; filename="'.$file['filename'].'"');header('X-Content-Type-Options: nosniff');header('Content-Length: '.strlen($file['contents']));echo $file['contents'];exit;}
    private function render(object $schema,?array $preview,?object $result,?string $error):void{\view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Import '.$schema->label,'pageDescription'=>'Map, preview and test spreadsheet rows before any data is written.','contentView'=>'data-exchange.import','schema'=>$schema,'user'=>$_SESSION['auth'],'preview'=>$preview,'result'=>$result,'error'=>$error,'moduleContext'=>$this->moduleContext($schema)]);}

    /** @return array{module:string,section:string} */
    private function moduleContext(object $schema): array
    {
        $sections = [
            'employees'=>'overview','attendance'=>'overview','suppliers'=>'suppliers','customers'=>'customers','products'=>'products','pricelists'=>'pricelists','sales-teams'=>'teams',
            'quotations'=>'quotations','sales-orders'=>'orders','deliveries'=>'deliveries',
            'warehouses'=>'warehouses','locations'=>'locations','stock'=>'stock','receipts'=>'receipts','transfers'=>'movements',
            'invoices'=>'invoices','credit-notes'=>'invoices','journals'=>'journals','journal-entries'=>'journals','finance-journals'=>'journals','expenses'=>'expenses','purchase-orders'=>'orders',
            'payments'=>'receipts','bank-transactions'=>'receipts',
        ];
        return ['module'=>(string)$schema->module,'section'=>$sections[$schema->entity]??($schema->module==='finance'?'receivables':'overview')];
    }
}
