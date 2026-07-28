<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\DepartmentRepository;
use App\Repositories\RepositoryFactory;
use PDOException;
use Throwable;

final class DepartmentCatalogueService
{
    private DepartmentRepository $departments;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?DepartmentRepository $departments = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->departments = $departments
            ?? RepositoryFactory::departments();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array{
     *     departments: list<array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         active: int,
     *         topLevel: int,
     *         currentEmployees: int
     *     }
     * }
     */
    public function catalogue(): array
    {
        $departments = $this->departments
            ->listForCompany(
                $this->tenant->companyId()
            );
        $active = 0;
        $topLevel = 0;
        $currentEmployees = 0;

        foreach ($departments as &$department) {
            $department['department_id'] = (int) (
                $department['department_id'] ?? 0
            );
            $department['parent_department_id'] =
                $this->nullableInteger(
                    $department[
                        'parent_department_id'
                    ] ?? null
                );
            $department['active'] =
                !empty($department['active']);
            $department['employee_count'] = (int) (
                $department['employee_count'] ?? 0
            );
            $department['current_employee_count'] =
                (int) (
                    $department[
                        'current_employee_count'
                    ] ?? 0
                );
            $active += $department['active'] ? 1 : 0;
            $topLevel +=
                $department['parent_department_id']
                    === null
                    ? 1
                    : 0;
            $currentEmployees +=
                $department['current_employee_count'];
        }

        unset($department);

        return [
            'departments' => $departments,
            'summary' => [
                'total' => count($departments),
                'active' => $active,
                'topLevel' => $topLevel,
                'currentEmployees' =>
                    $currentEmployees,
            ],
        ];
    }

    /**
     * Backward-compatible list used by the HR module.
     *
     * @return list<array<string, mixed>>
     */
    public function listing(): array
    {
        return $this->catalogue()['departments'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function form(int $departmentId): ?array
    {
        $department = $this->departments->find(
            $this->tenant->companyId(),
            $departmentId
        );

        if ($department === null) {
            return null;
        }

        return $this->recordValues($department)
            + [
                'department_id' => (int) (
                    $department['department_id'] ?? 0
                ),
            ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parentOptions(
        int $excludeDepartmentId = 0
    ): array {
        $options = $this->departments
            ->activeOptions(
                $this->tenant->companyId()
            );

        if ($excludeDepartmentId < 1) {
            return $options;
        }

        $blocked = [
            $excludeDepartmentId => true,
        ];
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($options as $option) {
                $optionId = (int) (
                    $option['department_id'] ?? 0
                );
                $parentId = $this->nullableInteger(
                    $option['parent_department_id']
                        ?? null
                );

                if (
                    $optionId > 0
                    && $parentId !== null
                    && isset($blocked[$parentId])
                    && !isset($blocked[$optionId])
                ) {
                    $blocked[$optionId] = true;
                    $changed = true;
                }
            }
        }

        return array_values(array_filter(
            $options,
            static fn (array $option): bool =>
                !isset($blocked[(int) (
                    $option['department_id'] ?? 0
                )])
        ));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     departmentId?: int,
     *     departmentName?: string
     * }
     */
    public function create(
        array $input,
        int $createdBy
    ): array {
        $companyId = $this->tenant->companyId();
        $values = $this->normalize($input);
        $errors = $this->validate(
            $companyId,
            $values
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $departmentId = $this->departments
                ->create(
                    $companyId,
                    $values,
                    $createdBy
                );
            $this->auditLogs->record(
                $createdBy,
                'CREATE',
                'organization',
                'hr_departments',
                (string) $departmentId,
                null,
                $this->recordValues($values),
                $companyId
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
                return $this->conflictResult();
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
            'departmentName' => (string) (
                $values['name']
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     notFound?: bool,
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
                'notFound' => true,
                'errors' => [],
            ];
        }

        $values = $this->normalize($input);
        $errors = $this->validate(
            $companyId,
            $values,
            $departmentId,
            !empty($department['active'])
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $oldValues = $this->recordValues($department);
        $newValues = $this->recordValues($values);

        if ($oldValues === $newValues) {
            return [
                'successful' => true,
                'errors' => [],
                'departmentId' => $departmentId,
                'departmentName' => (string) (
                    $values['name']
                ),
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

            $updated = $this->departments->update(
                $companyId,
                $departmentId,
                $values,
                $updatedBy
            );

            if (!$updated) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'notFound' => true,
                    'errors' => [],
                ];
            }

            $this->auditLogs->record(
                $updatedBy,
                'UPDATE',
                'organization',
                'hr_departments',
                (string) $departmentId,
                $oldValues,
                $newValues,
                $companyId
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
                return $this->conflictResult();
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
            'departmentName' => (string) (
                $values['name']
            ),
            'changed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        return [
            'code' => strtoupper(trim((string) (
                $input['code'] ?? ''
            ))),
            'name' => trim((string) (
                $input['name'] ?? ''
            )),
            'parent_department_id' =>
                $this->normalizeParentId(
                    $input['parent_department_id']
                        ?? null
                ),
            'description' => $this->nullable(
                $input['description'] ?? null
            ),
            'active' => !empty($input['active']),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private function validate(
        int $companyId,
        array $values,
        ?int $departmentId = null,
        bool $wasActive = false
    ): array {
        $errors = [];
        $code = (string) $values['code'];
        $name = (string) $values['name'];

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

        if (
            mb_strlen($name) < 2
            || mb_strlen($name) > 100
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

        if (
            is_string($values['description'])
            && mb_strlen($values['description']) > 500
        ) {
            $errors['description'] =
                'Description cannot exceed 500 characters.';
        }

        $parentId = $values['parent_department_id'];

        if ($parentId !== null) {
            $parent = $this->departments->find(
                $companyId,
                $parentId
            );

            if ($parent === null) {
                $errors['parent_department_id'] =
                    'Select a department from the current company.';
            } elseif (
                $departmentId !== null
                && $this->createsCycle(
                    $companyId,
                    $departmentId,
                    $parentId
                )
            ) {
                $errors['parent_department_id'] =
                    'A department cannot report to itself or one of its descendants.';
            } elseif (
                !empty($values['active'])
                && empty($parent['active'])
            ) {
                $errors['parent_department_id'] =
                    'An active department requires an active parent department.';
            }
        }

        if (
            $departmentId !== null
            && $wasActive
            && empty($values['active'])
        ) {
            if (
                $this->departments
                    ->currentEmployeeCount(
                        $companyId,
                        $departmentId
                    ) > 0
            ) {
                $errors['active'] =
                    'Move or terminate current employees before deactivating this department.';
            } elseif (
                $this->departments
                    ->activeChildCount(
                        $companyId,
                        $departmentId
                    ) > 0
            ) {
                $errors['active'] =
                    'Move or deactivate child departments before deactivating this department.';
            }
        }

        return $errors;
    }

    private function createsCycle(
        int $companyId,
        int $departmentId,
        int $candidateParentId
    ): bool {
        $visited = [];
        $currentId = $candidateParentId;

        for ($depth = 0; $depth < 100; $depth++) {
            if ($currentId === $departmentId) {
                return true;
            }

            if (isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;
            $current = $this->departments->find(
                $companyId,
                $currentId
            );

            if ($current === null) {
                return false;
            }

            $parentId = $this->nullableInteger(
                $current['parent_department_id']
                    ?? null
            );

            if ($parentId === null) {
                return false;
            }

            $currentId = $parentId;
        }

        return true;
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
            'parent_department_id' =>
                $this->nullableInteger(
                    $record['parent_department_id']
                        ?? null
                ),
            'description' => $this->nullable(
                $record['description'] ?? null
            ),
            'active' => !empty($record['active']),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInteger(
        mixed $value
    ): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (
            is_string($value)
            && ctype_digit($value)
        ) {
            $integer = (int) $value;

            return $integer > 0 ? $integer : null;
        }

        return null;
    }

    private function normalizeParentId(
        mixed $value
    ): ?int {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }
        }

        $parentId = $this->nullableInteger($value);

        return $parentId ?? -1;
    }

    /**
     * @return array{
     *     successful: false,
     *     errors: array<string, string>
     * }
     */
    private function conflictResult(): array
    {
        return [
            'successful' => false,
            'errors' => [
                'form' =>
                    'A department with that code or name already exists.',
            ],
        ];
    }
}
