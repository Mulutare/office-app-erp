<?php
declare(strict_types=1);
namespace App\Services\DataExchange;

use App\Services\{ModuleRoleService,SalesHierarchyScope,SalesService,TenantContext};
use PDO;
use RuntimeException;

/** Validates every row and every document group without allocating numbers or writing records. */
final class MasterImportValidator
{
    private array $lengths=[];
    private array $references=[];

    public static function supportsUpdate(string $entity): bool
    {
        return in_array($entity,['customers','products','quotations'],true);
    }

    public static function assertPermission(string $entity,int $actor): void
    {
        $company=(new TenantContext())->companyId();$policy=new ModuleRoleService();
        $permissions=$entity==='suppliers'?['procurement.suppliers.manage']:['sales.view','sales.import',$entity==='quotations'?'sales.orders.create':'sales.catalogue.manage'];
        foreach($permissions as $permission)if(!$policy->permissionAllowed($company,$actor,$permission))throw new RuntimeException('Import requires '.$permission.' in the active company.');
    }

    public function validate(string $entity,array $rows,array $mapping,string $mode): array
    {
        $schema=(new SchemaRegistry())->get($entity);$result=new ImportResult(rowsRead:count($rows));
        if(!$schema->canImport||!in_array($entity,['suppliers','customers','products','quotations'],true))throw new RuntimeException('This object does not support master/draft imports.');
        if(!in_array($mode,['create','update'],true)||($mode==='update'&&!self::supportsUpdate($entity)))throw new RuntimeException('Choose an available import mode.');
        $company=(new TenantContext())->companyId();$actor=(int)($_SESSION['auth']['user_id']??0);
        self::assertPermission($entity,$actor);
        $fields=$schema->fieldMap();$mapped=array_values(array_filter($mapping,static fn($key):bool=>is_string($key)&&$key!==''));
        if(count($mapped)!==count(array_unique($mapped)))$result->addError(0,'Mapping','Map each field once.');
        foreach($mapped as $key)if(!isset($fields[$key]))$result->addError(0,'Mapping','Unsupported import field.',$key);
        if($result->errors)return ['rows'=>[],'result'=>$result];
        [$table,$primary,$code]=match($entity){'customers'=>['sales_customers','customer_id','customer_number'],'products'=>['sales_products','product_id','sku'],'suppliers'=>['purchase_suppliers','supplier_id','supplier_code'],'quotations'=>['sales_quotations','quotation_id','quotation_number']};
        $records=[];$groups=[];$seen=[];$invalid=[];$duplicates=[];$externalIds=new ExternalIdService();$sales=new SalesService();
        foreach($rows as $offset=>$source) {
            $number=$offset+2;$row=[];
            foreach($mapping as $column=>$key)if(isset($fields[$key??'']))$row[$key]=is_scalar($source[$column]??null)?trim((string)$source[$column]):'';
            $generic=(new ImportValidator())->validate($schema,[$source],$mapping);
            $before=count($result->errors);
            $error=function(string $field,string $message,bool $duplicate=false)use($result,$fields,$row,$number,&$invalid,&$duplicates):void{
                $result->addError($number,$fields[$field]->label??$field,$message,(string)($row[$field]??''));$invalid[$number]=true;if($duplicate)$duplicates[$number]=true;
            };
            foreach($generic['result']->errors as $item){$result->addError($number,$item['field'],$item['message']);$invalid[$number]=true;}
            $external=$row['external_id']??'';$existing=null;$stored=null;
            try {
                if($external!=='')$existing=$externalIds->resolve($company,$entity,$external);
                if($mode==='create'&&$existing!==null)$error('external_id','External ID already exists. Use Update existing records deliberately.',true);
                if($mode==='update'&&($external===''||$existing===null))$error('external_id','Update mode requires an existing External ID in this company.');
                if($existing!==null) {
                    $statement=\db()->prepare("SELECT * FROM $table WHERE company_id=? AND $primary=?".($entity==='quotations'?'':' AND deleted_at IS NULL'));
                    $statement->execute([$company,$existing]);$stored=$statement->fetch(PDO::FETCH_ASSOC);
                    if(!$stored)$error('external_id','The referenced record is unavailable in this company.');
                    if($entity==='quotations'&&$stored && ($stored['status']!=='draft'||!(new SalesHierarchyScope())->canReadSalesRow($company,$actor,$stored)))$error('external_id','Only an accessible draft quotation can be updated.');
                }
            }catch(\Throwable $exception){$error('external_id',$exception->getMessage());}
            foreach(['active','serial_tracking'] as $key)if(isset($row[$key])&&$row[$key]!=='') {
                $value=strtolower($row[$key]);
                if(!in_array($value,['1','0','true','false','yes','no'],true))$error($key,'Use 1/0, true/false or yes/no.');
                else $row[$key]=in_array($value,['1','true','yes'],true)?1:0;
            }
            foreach(['credit_limit','quantity','payment_terms_days','commission_rate'] as $key)if(isset($row[$key])&&$row[$key]!=='') {
                $value=(float)$row[$key];
                if(!is_finite($value)||$value<0||$value>999999999999||($key==='quantity'&&$value<=0)||($key==='payment_terms_days'&&$value>65535))$error($key,'Enter a positive, finite value within the supported range.');
            }
            foreach(['currency','preferred_currency'] as $key)if(!empty($row[$key])){$row[$key]=strtoupper($row[$key]);if(!preg_match('/^[A-Z]{3}$/',$row[$key]))$error($key,'Use a three-letter currency code.');}
            foreach($row as $key=>$value)if($value!==''&&$value!==null) {
                $maximum=$key==='external_id'||$key==='line_external_id'?190:($this->columnLengths($table)[$key]??null);
                if($maximum!==null&&mb_strlen((string)$value)>$maximum)$error($key,'Maximum length is '.$maximum.' characters.');
            }
            if($entity==='quotations') {
                if($external==='')$error('external_id','A quotation External ID is required on every line.');
                $groupKey=mb_strtolower($external);$groups[$groupKey]??=['external'=>$external,'existing'=>$existing,'stored'=>$stored,'lines'=>[],'numbers'=>[]];
                $groups[$groupKey]['lines'][]=$row;$groups[$groupKey]['numbers'][]=$number;
            } else {
                $row[$code]=strtoupper(trim((string)($row[$code]??'')));
                foreach([$code=>$row[$code],'external_id'=>$external] as $key=>$value)if($value!=='') {
                    $identity=$key.':'.mb_strtolower($value);
                    if(isset($seen[$identity]))$error($key,'Duplicate in this file; first appears on row '.$seen[$identity].'.',true);
                    else $seen[$identity]=$number;
                }
                $query=\db()->prepare("SELECT $primary FROM $table WHERE company_id=? AND $code=? AND (? IS NULL OR $primary<>?) LIMIT 1");
                $query->execute([$company,$row[$code],$mode==='update'?$existing:null,$mode==='update'?$existing:null]);
                if($query->fetchColumn()!==false)$error($code,'This business identifier already exists in the company.',true);
                // Merge existing values so an explicit update cannot erase fields omitted from the spreadsheet.
                $input=array_replace(is_array($stored)?$stored:[],$row);
                foreach(['preferred_currency'=>'ETB','unit_of_measure'=>'unit','product_type'=>'service'] as $key=>$default)if(($input[$key]??'')==='')$input[$key]=$default;
                if($entity==='suppliers') {
                    if(($input['currency']??'')==='')$error('currency','A currency is required.');
                    if(isset($row['active'])&&$row['active']===0)$error('active','Supplier creation uses active status. Change status through supplier management after creation.');
                } else foreach($sales->validateImportInput($entity,$input) as $key=>$message)$error($key,$message);
                $records[]=['number'=>$number,'external'=>$external,'existing'=>$existing,'input'=>$input];
            }
            if(count($result->errors)>$before)$invalid[$number]=true;
        }
        foreach($groups as $group) {
            $numbers=$group['numbers'];$header=$group['lines'][0];$domainLines=[];$headerInput=[];
            foreach($group['lines'] as $index=>$line) {
                $number=$numbers[$index];
                try {
                    foreach(['customer','salesperson','sales_team','pricelist','quotation_date','expiration_date','payment_terms_days','currency','notes'] as $key)
                        if(($line[$key]??'')!==($header[$key]??''))throw new RuntimeException('Quotation header '.$key.' differs from the first line in this group.');
                    $product=$this->reference('sales_products','product_id','sku','name',(string)($line['product']??''),$company);
                    $lineKey=mb_strtolower((string)($line['line_external_id']??''));
                    if($lineKey!==''&&isset($lineKeys[$group['external']][$lineKey]))throw new RuntimeException('Duplicate Line External ID in this quotation.');
                    if($lineKey!=='')$lineKeys[$group['external']][$lineKey]=true;
                    $domainLines[]=['product_id'=>$product,'quantity'=>$line['quantity']??''];
                }catch(\Throwable $exception){$result->addError($number,'Quotation',$exception->getMessage());$invalid[$number]=true;}
            }
            try {
                $headerInput=is_array($group['stored'])?$group['stored']:[];
                $headerInput['customer_id']=$this->reference('sales_customers','customer_id','customer_number','name',(string)($header['customer']??''),$company);
                $headerInput['lines']=$domainLines;
                foreach(['quotation_date'=>date('Y-m-d'),'expiration_date'=>null,'currency'=>'ETB','payment_terms_days'=>0,'notes'=>null] as $key=>$default)
                    $headerInput[$key]=array_key_exists($key,$header)?$header[$key]:($headerInput[$key]??$default);
                foreach(['salesperson'=>['sales_agents','agent_id','agent_code'],'sales_team'=>['sales_teams','team_id','name'],'pricelist'=>['sales_pricelists','pricelist_id','name']] as $key=>[$table,$id,$code])
                    if(array_key_exists($key,$header))$headerInput[$id]=$header[$key]===''?null:$this->reference($table,$id,$code,'name',$header[$key],$company);
                foreach($sales->validateImportInput('quotations',$headerInput) as $key=>$message){$result->addError($numbers[0],$key,$message);foreach($numbers as $n)$invalid[$n]=true;}
            }catch(\Throwable $exception){$result->addError($numbers[0],'Quotation',$exception->getMessage());foreach($numbers as $n)$invalid[$n]=true;}
            $records[]=['number'=>$numbers[0],'external'=>$group['external'],'existing'=>$group['existing'],'input'=>$headerInput];
        }
        $result->invalidRows=count($invalid);$result->duplicateRows=count($duplicates);$result->valid=count($rows)-count($invalid);
        return ['rows'=>$records,'result'=>$result];
    }

    private function reference(string $table,string $id,string $code,string $name,string $value,int $company): int
    {
        $cache=$table.':'.$company.':'.mb_strtolower(trim($value));if(isset($this->references[$cache]))return $this->references[$cache];
        $columns=\db()->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$columns->execute([$table]);$fields=$columns->fetchAll(PDO::FETCH_COLUMN);
        $where=(in_array('active',$fields,true)?' AND active=1':'').(in_array('deleted_at',$fields,true)?' AND deleted_at IS NULL':'');
        foreach(array_unique([$code,$name]) as $column) {
            $query=\db()->prepare("SELECT $id FROM $table WHERE company_id=? AND $column=? $where LIMIT 2");$query->execute([$company,trim($value)]);$ids=$query->fetchAll(PDO::FETCH_COLUMN);
            if(count($ids)>1)throw new RuntimeException('Ambiguous reference "'.$value.'". Use its unique business code.');
            if(count($ids)===1)return $this->references[$cache]=(int)$ids[0];
        }
        throw new RuntimeException('Active reference "'.$value.'" was not found in this company.');
    }

    private function columnLengths(string $table): array
    {
        if(!isset($this->lengths[$table])) {
            $query=\db()->prepare('SELECT COLUMN_NAME,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CHARACTER_MAXIMUM_LENGTH IS NOT NULL');
            $query->execute([$table]);$this->lengths[$table]=$query->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        return $this->lengths[$table];
    }
}
