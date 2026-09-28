<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

final class ExportService
{
    public function __construct(private ?SchemaRegistry $schemas = null,private ?ExportDefinitionRegistry $definitions=null){$this->schemas??=new SchemaRegistry();$this->definitions??=new ExportDefinitionRegistry();}

    /** @return array{contents:string,mime:string,filename:string} */
    public function template(string $entity,string $format): array
    {
        $schema=$this->schemas->get($entity);
        if(!$schema->canImport)throw new \RuntimeException('This object does not support import templates.');
        $fields=array_values(array_filter($schema->fields,static fn(ExchangeField $f):bool=>$f->importable));
        $headers=array_map(static fn(ExchangeField $f):string=>$f->label,$fields);
        $example=[];foreach($fields as $field)$example[$field->key]=$field->example??'';
        return $this->file($entity.'-import-template',$format,$headers,[$example],$schema);
    }

    /** @param list<array<string,mixed>> $rows @param list<string>|null $selected */
    public function export(string $entity,string $format,array $rows,?array $selected=null): array
    {
        $schema=$this->schemas->get($entity);if(!$schema->canExport)throw new \RuntimeException('This object does not support export.');
        $definition=$this->definitions->get($entity);$exportSchema=new ExchangeSchema($entity,$schema->label,$schema->module,$definition['fields'],false,true,false);
        $map=$exportSchema->fieldMap();$keys=$selected===null?array_keys($map):array_values(array_filter($selected,static fn(string $k):bool=>isset($map[$k])));
        $headers=[];foreach($keys as $key)$headers[]=$map[$key]->label;
        $output=[];foreach($rows as $source){$row=[];foreach($keys as $key)$row[$key]=$source[$key]??'';$output[]=$row;}
        return $this->file($definition['filename'].'_'.date('Y-m-d'),$format,$headers,$output,$exportSchema);
    }

    /** Export-only registers use the same codecs and formula protection as data exchange.
     * Columns must be a controller-owned whitelist, never spreadsheet/request keys.
     */
    public function register(string $name,string $format,array $columns,array $rows): array
    {
        $fields=[];$output=[];
        foreach($columns as $key=>$label) {
            $type=$this->registerType($key);
            if($type==='date')foreach($rows as $row)if(preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/',(string)($row[$key]??''))){$type='datetime';break;}
            $fields[]=new ExchangeField($key,$label,false,$type);
        }
        foreach($rows as $row) $output[]=array_map(static fn(string $key):mixed=>$row[$key]??'',array_keys($columns));
        $schema=new ExchangeSchema($name,$name,'', $fields,false,true,false);
        return $this->file($name.'_'.date('Y-m-d'),$format,array_values($columns),$output,$schema);
    }

    /** Only controller-owned business field names determine types; codes remain text. */
    private function registerType(string $key): string
    {
        if(preg_match('/(?:_amount|_quantity|_price|_days|_rate|_percent|_cost|_value|_due|_paid)$/',$key)
            ||in_array($key,['amount','quantity','debit','credit','balance','total','residual','outstanding','on_hand','reserved','available','beginning','ending','annual_entitlement','minimum_quantity','percentage_adjustment','quantity_on_hand','quantity_reserved','quantity_available','cost','book_value_after','balance_difference','reservation_difference'],true))return 'decimal';
        if(preg_match('/(?:_minutes|_count|_months)$/',$key)||in_array($key,['attempts','priority','sequence','revision_number','version','period_number','installment_number'],true))return 'integer';
        if(preg_match('/(?:_date)$/',$key)||in_array($key,['date','date_from','date_to','effective_from','effective_to','valid_from','valid_to'],true))return 'date';
        if(str_ends_with($key,'_at'))return 'datetime';
        return 'string';
    }

    /** @param list<string> $headers @param list<array<string,mixed>> $rows @return array{contents:string,mime:string,filename:string} */
    private function file(string $base,string $format,array $headers,array $rows,?ExchangeSchema $schema):array
    {
        if($format==='csv')return['contents'=>(new CsvCodec())->write($headers,$rows),'mime'=>'text/csv; charset=UTF-8','filename'=>$base.'.csv'];
        if($format!=='xlsx')throw new \RuntimeException('Choose XLSX or CSV.');
        return['contents'=>(new SpreadsheetCodec())->write($headers,$rows,$schema),'mime'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','filename'=>$base.'.xlsx'];
    }
}
