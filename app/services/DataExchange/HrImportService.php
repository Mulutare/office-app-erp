<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use App\Models\AuditLog;
use App\Services\AttendanceManagementService;
use App\Services\EmployeeCreationService;
use App\Services\EmployeeRecordValidator;
use App\Services\TenantContext;
use PDO;
use RuntimeException;
use Throwable;

/** Create-only HR adapters for the existing preview/confirm exchange pipeline. */
final class HrImportService
{
    public function validate(string $entity, array $rows, array $mapping): array
    {
        $schema = (new SchemaRegistry())->get($entity);
        $company = (new TenantContext())->companyId();
        $result = new ImportResult(rowsRead: count($rows));
        $prepared = [];
        $seen = [];
        $fields = $schema->fieldMap();
        $mapped = array_values(array_filter($mapping, static fn($key): bool => is_string($key) && $key !== ''));
        if (count($mapped) !== count(array_unique($mapped))) {
            $result->addError(0, 'Mapping', 'Map each field once.');
            return ['rows' => [], 'result' => $result];
        }
        foreach ($mapped as $key) {
            if (!isset($fields[$key])) $result->addError(0, 'Mapping', 'Unsupported field.', $key);
        }
        if ($result->errors !== []) return ['rows' => [], 'result' => $result];
        foreach ($rows as $offset => $source) {
            $rowNumber = $offset + 2;
            $generic = (new ImportValidator())->validate($schema, [$source], $mapping);
            $before = count($result->errors);
            $row = [];
            foreach ($mapping as $column => $key) {
                if (isset($fields[$key ?? ''])) $row[$key] = is_scalar($source[$column] ?? null) ? trim((string)$source[$column]) : '';
            }
            foreach ($generic['result']->errors as $error) {
                $key = array_search($error['field'], array_map(static fn($f) => $f->label, $fields), true);
                $result->addError($rowNumber, $error['field'], $error['message'], (string)($row[$key] ?? ''));
            }
            $duplicate = false;
            if ($entity === 'employees') {
                $domain = $row;
                $domain['department_id'] = $this->resolve('hr_departments', 'department_id', 'code', $row['department_code'] ?? '', $company, 'AND active=TRUE AND deleted_at IS NULL');
                if (!$domain['department_id']) $result->addError($rowNumber, 'Department Code', 'Active department not found in this company.', $row['department_code'] ?? '');
                $domain['manager_employee_id'] = '';
                if (($row['manager_number'] ?? '') !== '') {
                    $domain['manager_employee_id'] = $this->resolve('hr_employees', 'employee_id', 'employee_number', $row['manager_number'], $company, "AND deleted_at IS NULL AND employment_status<>'terminated'");
                    if (!$domain['manager_employee_id']) $result->addError($rowNumber, 'Manager Employee Number', 'Existing manager not found in this company.', $row['manager_number']);
                }
                $domain['user_id'] = '';
                if (($row['username'] ?? '') !== '') {
                    $statement = \db()->prepare('SELECT u.user_id FROM users u JOIN company_users cu ON cu.user_id=u.user_id AND cu.company_id=? AND cu.active=TRUE WHERE u.username=? AND u.active=TRUE AND u.deleted_at IS NULL');
                    $statement->execute([$company,$row['username']]);
                    $domain['user_id'] = $statement->fetchColumn() ?: '';
                    if (!$domain['user_id']) $result->addError($rowNumber, 'Username', 'Active account not found in this company.', $row['username']);
                }
                $validator = new EmployeeRecordValidator();
                $errors = $validator->validate($validator->normalize($domain));
                foreach ($errors as $key => $message) {
                    if (str_contains($message, 'already in use') || str_contains($message, 'already linked')) $duplicate = true;
                    $displayKey = ['department_id'=>'department_code','manager_employee_id'=>'manager_number','user_id'=>'username'][$key] ?? $key;
                    $result->addError($rowNumber, $fields[$displayKey]->label ?? $displayKey, $message, $row[$displayKey] ?? '');
                }
                foreach (['employee_number','work_email','username'] as $key) {
                    $value = mb_strtolower(trim((string)($row[$key] ?? '')));
                    if ($value === '') continue;
                    if (isset($seen[$key][$value])) {
                        $duplicate = true;
                        $result->addError($rowNumber, $fields[$key]->label, 'Repeated value in this upload (first seen on row ' . $seen[$key][$value] . ').', $row[$key]);
                    } else $seen[$key][$value] = $rowNumber;
                }
            } else {
                $employeeId = $this->resolve('hr_employees', 'employee_id', 'employee_number', $row['employee_number'] ?? '', $company, "AND deleted_at IS NULL AND employment_status IN ('active','on_leave')");
                $domain = $row + ['employee_id' => $employeeId ?: 0];
                if (!$employeeId) $result->addError($rowNumber, 'Employee Number', 'Active employee not found in this company.', $row['employee_number'] ?? '');
                foreach ((new AttendanceManagementService())->validateImport($domain) as $key => $message) {
                    $result->addError($rowNumber, $fields[$key]->label ?? $key, $message, $row[$key] ?? '');
                }
                $key = mb_strtolower(($row['employee_number'] ?? '') . '|' . ($row['attendance_date'] ?? ''));
                $statement = \db()->prepare('SELECT attendance_id FROM attendance_records WHERE company_id=? AND employee_id=? AND attendance_date=?');
                $statement->execute([$company,$employeeId ?: 0,$row['attendance_date'] ?? '']);
                if ($statement->fetchColumn() !== false || isset($seen[$key])) {
                    $duplicate = true;
                    $result->addError($rowNumber, 'Attendance Date', 'Employee/date already exists or is repeated in this upload. Imports never overwrite attendance.', $row['attendance_date'] ?? '');
                }
                $seen[$key] = true;
            }
            if ($duplicate) ++$result->duplicateRows;
            if (count($result->errors) === $before) {
                $prepared[] = $domain;
                ++$result->valid;
            } else ++$result->invalidRows;
        }
        return ['rows' => $prepared, 'result' => $result];
    }

    public function import(string $entity, array $rows, array $mapping, int $actor): ImportResult
    {
        $company = (new TenantContext())->companyId();
        if ($actor < 1 || $actor !== (int)($_SESSION['auth']['user_id'] ?? 0)) throw new RuntimeException('Import actor does not match the current session.');
        $connection = \db();
        if ($connection->inTransaction()) throw new RuntimeException('Confirm the HR import outside an existing transaction.');
        $result = new ImportResult(rowsRead: count($rows));
        try {
            $connection->beginTransaction();
            $validated = $this->validate($entity, $rows, $mapping);
            $result = $validated['result'];
            if ($result->errors !== []) { $connection->rollBack(); return $result; }
            foreach ($validated['rows'] as $row) {
                $operation = $entity === 'employees'
                    ? (new EmployeeCreationService())->create($row, $actor)
                    : (new AttendanceManagementService())->record($row, $actor, true);
                if (empty($operation['successful'])) throw new RuntimeException(implode(' ', $operation['errors'] ?? ['HR import failed.']));
                ++$result->created;
            }
            (new AuditLog())->record($actor, 'IMPORT', $entity === 'employees' ? 'hr' : 'attendance',
                $entity === 'employees' ? 'hr_employees' : 'attendance_records', null, null,
                ['source'=>'spreadsheet','mode'=>'create_only','rows'=>$result->created,
                 'content_sha256'=>hash('sha256', json_encode([$rows,$mapping], JSON_THROW_ON_ERROR))], $company);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) $connection->rollBack();
            $result->created = 0;
            $result->addError(0, 'Import', 'No rows were saved. ' . $exception->getMessage());
        }
        return $result;
    }

    private function resolve(string $table, string $id, string $code, string $value, int $company, string $extra): ?int
    {
        // Identifiers and predicates above are constants owned by this adapter, never upload values.
        $statement = \db()->prepare("SELECT $id FROM $table WHERE company_id=? AND $code=? $extra");
        $statement->execute([$company,$value]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int)$id;
    }
}
