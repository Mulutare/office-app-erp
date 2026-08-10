<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use App\Services\SalesService;
use App\Services\TenantContext;
use RuntimeException;
use Throwable;

final class ImportService
{
    public function __construct(
        private ?SchemaRegistry $schemas = null,
        private ?FileGuard $guard = null,
        private ?ImportValidator $validator = null,
        private ?ExternalIdService $externalIds = null,
        private ?TenantContext $tenant = null,
        private ?SalesService $sales = null,
    ) {
        $this->schemas ??= new SchemaRegistry();
        $this->guard ??= new FileGuard();
        $this->validator ??= new ImportValidator();
        $this->externalIds ??= new ExternalIdService();
        $this->tenant ??= new TenantContext();
        $this->sales ??= new SalesService();
    }

    /** @return array{headers:list<string>,rows:list<list<mixed>>,mapping:array<int,string|null>,schema:ExchangeSchema} */
    public function inspect(string $entity, string $path, string $originalName): array
    {
        $schema = $this->schemas->get($entity);
        if (!$schema->canImport) throw new RuntimeException('This object is export-only.');
        $extension = $this->guard->validate($path, $originalName);
        $data = $extension === 'csv' ? (new CsvCodec())->read($path) : (new SpreadsheetCodec())->read($path);
        return $data + ['mapping'=>$schema->autoMap($data['headers']),'schema'=>$schema];
    }

    /** @param list<list<mixed>> $rows @param array<int,string|null> $mapping @return array{rows:list<array<string,mixed>>,result:ImportResult} */
    public function test(string $entity, array $rows, array $mapping): array
    {
        return $this->validator->validate($this->schemas->get($entity), $rows, $mapping);
    }

    /** @param list<list<mixed>> $rows @param array<int,string|null> $mapping */
    public function import(string $entity, array $rows, array $mapping, int $actorId): ImportResult
    {
        $validated = $this->test($entity, $rows, $mapping);
        /** @var ImportResult $result */
        $result = $validated['result'];
        if ($result->errors !== []) return $result;
        if (!in_array($entity, ['customers','products','quotations'], true)) {
            $result->addError(0, 'Object', 'Final import for this object is not yet connected to its domain service.');
            return $result;
        }
        $connection = \db();
        $ownsTransaction = !$connection->inTransaction();
        try {
            if ($ownsTransaction) $connection->beginTransaction();
            if ($entity === 'quotations') {
                $this->importQuotations($validated['rows'], $actorId, $result);
                if ($ownsTransaction) $connection->commit();
                return $result;
            }
            foreach ($validated['rows'] as $offset => $row) {
                $external = trim((string) ($row['external_id'] ?? ''));
                $existing = $external === '' ? null : $this->externalIds->resolve($this->tenant->companyId(), $entity, $external);
                $domain = $this->domainInput($entity, $row);
                $operation = $existing === null
                    ? ($entity === 'customers' ? $this->sales->createCustomer($domain, $actorId) : $this->sales->createProduct($domain, $actorId))
                    : ($entity === 'customers' ? $this->sales->updateCustomer($existing, $domain, $actorId) : $this->sales->updateProduct($existing, $domain, $actorId));
                if (empty($operation['successful'])) {
                    throw new RuntimeException(implode(' ', (array) ($operation['errors'] ?? ['Import failed.'])));
                }
                $entityId = (int) ($operation['id'] ?? $existing);
                if ($external === '') $external = sprintf('%s_%d_%d', rtrim($entity, 's'), $this->tenant->companyId(), $entityId);
                $this->externalIds->assign($this->tenant->companyId(), $entity, $entityId, $external);
                $existing === null ? ++$result->created : ++$result->updated;
            }
            if ($ownsTransaction) $connection->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $connection->inTransaction()) $connection->rollBack();
            $result->created = $result->updated = 0;
            $result->addError(0, 'Import', $exception->getMessage());
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $rows */
    private function importQuotations(array $rows, int $actorId, ImportResult $result): void
    {
        $workspace=$this->sales->workspace();$customers=[];$products=[];
        foreach((array)$workspace['customers'] as $customer){foreach([(string)$customer['customer_number'],(string)$customer['name']] as $key)$customers[strtolower(trim($key))]=(int)$customer['customer_id'];}
        foreach((array)$workspace['products'] as $product){foreach([(string)$product['sku'],(string)$product['name']] as $key)$products[strtolower(trim($key))]=$product;}
        $groups=[];foreach($rows as $row){$external=trim((string)($row['external_id']??''));if($external==='')throw new RuntimeException('Quotation External ID is required on every line.');$groups[$external][]=$row;}
        foreach($groups as $external=>$lines){$header=$lines[0];$customerKey=strtolower(trim((string)($header['customer']??'')));$customerId=$customers[$customerKey]??null;if($customerId===null)throw new RuntimeException('Quotation '.$external.' references an unknown customer.');
            $domainLines=[];foreach($lines as $line){$productKey=strtolower(trim((string)($line['product']??'')));$product=$products[$productKey]??null;if(!is_array($product))throw new RuntimeException('Quotation '.$external.' references an unknown product.');$quantity=(float)$line['quantity'];$percent=(float)($line['discount']??0);if($percent<0||$percent>100)throw new RuntimeException('Quotation '.$external.' has an invalid discount.');$domainLines[]=['product_id'=>(int)$product['product_id'],'quantity'=>$quantity,'discount_amount'=>round($quantity*(float)$product['unit_price']*$percent/100,2),'tax_rate'=>is_numeric($line['tax']??null)?(float)$line['tax']:0];}
            $input=['customer_id'=>$customerId,'quotation_date'=>$header['quotation_date']??date('Y-m-d'),'expiration_date'=>$header['expiration_date']??null,'payment_terms_days'=>$header['payment_terms_days']??0,'currency'=>$header['currency']??'ETB','notes'=>$header['notes']??null,'lines'=>$domainLines];
            $existing=$this->externalIds->resolve($this->tenant->companyId(),'quotations',$external);$operation=$existing===null?$this->sales->createQuotation($input,$actorId):$this->sales->updateQuotation($existing,$input,$actorId);if(empty($operation['successful']))throw new RuntimeException(implode(' ',(array)($operation['errors']??['Quotation import failed.'])));$id=(int)($operation['id']??$existing);$this->externalIds->assign($this->tenant->companyId(),'quotations',$id,$external);$existing===null?++$result->created:++$result->updated;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function domainInput(string $entity, array $row): array
    {
        if ($entity === 'customers') {
            return $row + ['customer_type'=>'business','credit_status'=>'active','credit_mode'=>'unlimited'];
        }
        return $row + ['category'=>$row['category']??null,'product_type'=>$row['product_type']??'service','unit_of_measure'=>$row['unit_of_measure']??'unit','commission_rate'=>0,'serial_tracking'=>in_array(strtolower((string)($row['serial_tracking']??'')),['1','yes','true'],true)];
    }
}
