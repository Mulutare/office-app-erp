<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class FinanceStatementService
{
    private function rows(string $sql,array $params): array{$query=\db()->prepare($sql);$query->execute($params);return $query->fetchAll(PDO::FETCH_ASSOC);}
    public function statement(string $kind,array $input): array
    {
        if(!in_array($kind,['customer','supplier'],true))throw new RuntimeException('Unknown statement type.');
        $company=(new TenantContext())->companyId();$party=(int)($input['party_id']??0);$currency=strtoupper(trim((string)($input['currency']??'')));$from=$this->date($input['from']??'');$to=$this->date($input['to']??'');
        if($currency!==''&&preg_match('/^[A-Z]{3}$/',$currency)!==1)throw new RuntimeException('Invalid currency.');if($from!==''&&$to!==''&&$from>$to)throw new RuntimeException('Invalid date range.');
        $parties=$kind==='customer'?$this->rows('SELECT customer_id id,name label FROM sales_customers WHERE company_id=:company ORDER BY name',['company'=>$company]):$this->rows('SELECT supplier_id id,business_name label FROM purchase_suppliers WHERE company_id=:company ORDER BY business_name',['company'=>$company]);
        $result=['kind'=>$kind,'parties'=>$parties,'party_id'=>$party,'currency'=>$currency,'from'=>$from,'to'=>$to,'lines'=>[],'totals'=>[]];if($party<1)return $result;
        $lookup=$kind==='customer'?'SELECT name label FROM sales_customers WHERE company_id=:company AND customer_id=:party':'SELECT business_name label FROM purchase_suppliers WHERE company_id=:company AND supplier_id=:party';
        $selected=$this->rows($lookup,['company'=>$company,'party'=>$party]);if(!$selected)throw new RuntimeException('Party was not found in this company.');$result['party_name']=$selected[0]['label'];
        $factory=new \App\Services\Lists\FinanceStatementListService();
        $list=$factory->listing($kind,$party,array_replace($input,['party_id'=>$party,'currency'=>$currency,'from'=>$from,'to'=>$to]));
        $result['list']=$list->page();$result['exportList']=$list;
        $result['lines']=$result['list']['rows'];$result['controls']=$factory->controls($kind,$party);
        $result=array_replace($result,$factory->balances($kind,$party,$from,$to,$currency));
        return $result;
    }
    private function date(mixed $value): string{$value=trim((string)$value);if($value==='')return '';$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new RuntimeException('Invalid date.');return $value;}
}
