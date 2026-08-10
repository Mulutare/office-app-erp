<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

final class ExportService
{
    public function __construct(private ?SchemaRegistry $schemas = null){$this->schemas??=new SchemaRegistry();}

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
        $map=$schema->fieldMap();$keys=$selected===null?array_keys($map):array_values(array_filter($selected,static fn(string $k):bool=>isset($map[$k])));
        $headers=[];foreach($keys as $key)$headers[]=$map[$key]->label;
        $output=[];foreach($rows as $source){$row=[];foreach($keys as $key)$row[$key]=$source[$key]??'';$output[]=$row;}
        return $this->file($entity.'-export',$format,$headers,$output,null);
    }

    /** @param list<string> $headers @param list<array<string,mixed>> $rows @return array{contents:string,mime:string,filename:string} */
    private function file(string $base,string $format,array $headers,array $rows,?ExchangeSchema $schema):array
    {
        if($format==='csv')return['contents'=>(new CsvCodec())->write($headers,$rows),'mime'=>'text/csv; charset=UTF-8','filename'=>$base.'.csv'];
        if($format!=='xlsx')throw new \RuntimeException('Choose XLSX or CSV.');
        return['contents'=>(new SpreadsheetCodec())->write($headers,$rows,$schema),'mime'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','filename'=>$base.'.xlsx'];
    }
}
