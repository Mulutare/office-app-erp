<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use App\Services\SalesService;
use App\Services\ProcurementService;
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
        private ?ProcurementService $procurement = null,
    ) {
        $this->schemas ??= new SchemaRegistry();
        $this->guard ??= new FileGuard();
        $this->validator ??= new ImportValidator();
        $this->externalIds ??= new ExternalIdService();
        $this->tenant ??= new TenantContext();
        $this->sales ??= new SalesService();
        $this->procurement ??= new ProcurementService();
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
    public function test(string $entity, array $rows, array $mapping, string $mode = 'create'): array
    {
        if (in_array($entity, ['employees', 'attendance'], true)) {
            if($mode!=='create')throw new RuntimeException('HR imports support create mode only.');
            return (new HrImportService())->validate($entity, $rows, $mapping);
        }
        return (new MasterImportValidator())->validate($entity,$rows,$mapping,$mode);
    }

    /** @param list<list<mixed>> $rows @param array<int,string|null> $mapping */
    public function import(string $entity,array $rows,array $mapping,int $actorId,string $mode='create'): ImportResult
    {
        if(in_array($entity,['employees','attendance'],true)) {
            if($mode!=='create')throw new RuntimeException('HR imports support create mode only.');
            return (new HrImportService())->import($entity,$rows,$mapping,$actorId);
        }
        if($actorId<1||$actorId!==(int)($_SESSION['auth']['user_id']??0))throw new RuntimeException('Import actor must match the active user.');
        $validated=$this->test($entity,$rows,$mapping,$mode);$result=$validated['result'];
        if($result->errors)return $result;
        $connection=\db();$owns=!$connection->inTransaction();$savepoint='import_'.bin2hex(random_bytes(8));$rowNumber=0;
        try {
            if($owns)$connection->beginTransaction();else $connection->exec('SAVEPOINT '.$savepoint);
            // Recheck current identifiers and workflow state inside the write transaction.
            $validated=$this->test($entity,$rows,$mapping,$mode);$result=$validated['result'];
            if($result->errors) {
                if($owns)$connection->rollBack();else {$connection->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$connection->exec('RELEASE SAVEPOINT '.$savepoint);}
                return $result;
            }
            foreach($validated['rows'] as $record) {
                $rowNumber=$record['number'];$input=$record['input'];$existing=$record['existing'];
                if($entity==='suppliers')$id=$this->procurement->createSupplier($input,$actorId);
                else {
                    $operation=match($entity) {
                        'customers'=>$mode==='create'?$this->sales->createCustomer($input,$actorId):$this->sales->updateCustomer($existing,$input,$actorId),
                        'products'=>$mode==='create'?$this->sales->createProduct($input,$actorId):$this->sales->updateProduct($existing,$input,$actorId),
                        'quotations'=>$mode==='create'?$this->sales->createQuotation($input,$actorId):$this->sales->updateQuotation($existing,$input,$actorId),
                        default=>throw new RuntimeException('Unsupported import object.'),
                    };
                    if(empty($operation['successful']))throw new RuntimeException(implode(' ',(array)($operation['errors']??['Import failed.'])));
                    $id=(int)($operation['id']??$existing);
                    if(in_array($entity,['customers','products'],true)&&array_key_exists('active',$input)) {
                        $active=$entity==='customers'?$this->sales->setCustomerActive($id,(bool)$input['active'],$actorId):$this->sales->setProductActive($id,(bool)$input['active'],$actorId);
                        if(empty($active['successful']))throw new RuntimeException(implode(' ',(array)($active['errors']??['Could not set active status.'])));
                    }
                }
                $external=$record['external']?:sprintf('%s_%d_%d',rtrim($entity,'s'),$this->tenant->companyId(),$id);
                $this->externalIds->assign($this->tenant->companyId(),$entity,$id,$external);
                $mode==='create'?++$result->created:++$result->updated;
            }
            if($owns)$connection->commit();else $connection->exec('RELEASE SAVEPOINT '.$savepoint);
        }catch(Throwable $exception) {
            if($connection->inTransaction()) {
                if($owns)$connection->rollBack();else {$connection->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$connection->exec('RELEASE SAVEPOINT '.$savepoint);}
            }
            $result->created=$result->updated=0;$result->addError($rowNumber,'Import',$exception->getMessage());
        }
        return $result;
    }
}
