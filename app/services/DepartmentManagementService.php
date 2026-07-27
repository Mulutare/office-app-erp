<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Department;
use PDOException;
use Throwable;

final class DepartmentManagementService
{
    private Department $departments;
    private AuditLog $auditLogs;
    private TenantContext $tenant;

    public function __construct()
    {
        $this->departments = new Department();
        $this->auditLogs = new AuditLog();
        $this->tenant = new TenantContext();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listing(): array
    {
        return $this->departments->managementList(
            $this->tenant->companyId()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function form(
        int $departmentId
    ): ?array {
        return $this->departments->find(
            $this->tenant->companyId(),
            $departmentId
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     departmentId?: int,
     *     departmentName?: string,
     *     changed?: bool
     * }
     */
    public function update(
        int $departmentId,
        array $input,
        int $updatedBy
    ): array {
        $companyId = $this->tenant->companyId();
        $department = $this->departments->find(
            $companyId,
            $departmentId
        );

        if ($department === null) {
            return [
                'successful' => false,
                'errors' => [
                    'form' =>
                        'The department record no longer exists.',
                ],
            ];
        }

        $values = [
            'code' => strtoupper(trim((string) (
                $input['code'] ?? ''
            ))),
            'name' => trim((string) (
                $input['name'] ?? ''
            )),
            'description' => trim((string) (
                $input['description'] ?? ''
            )),
            'active' => !empty($input['active']),
        ];
        $errors = $this->validate(
            $companyId,
            $departmentId,
            $values,
            !empty($department['active'])
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $oldValues = $this->recordValues(
            $department
        );
        $newValues = $this->recordValues($values);

        if ($oldValues === $newValues) {
            return [
                'successful' => true,
                'errors' => [],
                'departmentId' => $departmentId,
                'departmentName' =>
                    (string) $values['name'],
                'changed' => false,
            ];
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $this->departments->update(
                $companyId,
                $departmentId,
                (string) $values['code'],
                (string) $values['name'],
                (string) $values['description'],
                (bool) $values['active'],
                $updatedBy
            );
            $this->auditLogs->record(
                $updatedBy,
                'UPDATE',
                'hr',
                'hr_departments',
                (string) $departmentId,
                $oldValues,
                $newValues
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (PDOException $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            if (
                (string) $exception->getCode()
                === '23000'
            ) {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'A department with that code or name already exists.',
                    ],
                ];
            }

            throw $exception;
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'errors' => [],
            'departmentId' => $departmentId,
            'departmentName' =>
                (string) $values['name'],
            'changed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private function validate(
        int $companyId,
        int $departmentId,
        array $values,
        bool $wasActive
    ): array {
        $errors = [];
        $code = (string) ($values['code'] ?? '');
        $name = (string) ($values['name'] ?? '');
        $description = (string) (
            $values['description'] ?? ''
        );
        $active = !empty($values['active']);

        if (
            preg_match(
                '/^[A-Z][A-Z0-9_-]{1,29}$/',
                $code
            ) !== 1
        ) {
            $errors['code'] =
                'Code must contain 2-30 uppercase letters, numbers, hyphens or underscores and begin with a letter.';
        } elseif (
            $this->departments->codeExists(
                $companyId,
                $code,
                $departmentId
            )
        ) {
            $errors['code'] =
                'That department code is already in use.';
        }

        $nameLength = mb_strlen($name);

        if (
            $nameLength < 2
            || $nameLength > 100
        ) {
            $errors['name'] =
                'Department name must contain 2-100 characters.';
        } elseif (
            $this->departments->nameExists(
                $companyId,
                $name,
                $departmentId
            )
        ) {
            $errors['name'] =
                'That department name is already in use.';
        }

        if (mb_strlen($description) > 255) {
            $errors['description'] =
                'Description cannot exceed 255 characters.';
        }

        if (
            !$active
            && $wasActive
            && $this->departments
                ->currentEmployeeCount(
                    $companyId,
                    $departmentId
                ) > 0
        ) {
            $errors['active'] =
                'Move or terminate current employees before deactivating this department.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function recordValues(array $record): array
    {
        return [
            'code' => (string) (
                $record['code'] ?? ''
            ),
            'name' => (string) (
                $record['name'] ?? ''
            ),
            'description' =>
                $this->nullableString(
                    $record['description'] ?? null
                ),
            'active' => !empty($record['active']),
        ];
    }

    private function nullableString(
        mixed $value
    ): ?string {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
