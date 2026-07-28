<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\BranchRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\JobTitleRepository;
use App\Repositories\PositionRepository;
use App\Repositories\RepositoryFactory;
use PDOException;
use Throwable;

final class PositionManagementService
{
    private const STATUSES = [
        'planned' => 'Planned',
        'open' => 'Open',
        'frozen' => 'Frozen',
        'closed' => 'Closed',
    ];

    private PositionRepository $positions;
    private BranchRepository $branches;
    private DepartmentRepository $departments;
    private JobTitleRepository $jobTitles;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?PositionRepository $positions = null,
        ?BranchRepository $branches = null,
        ?DepartmentRepository $departments = null,
        ?JobTitleRepository $jobTitles = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->positions = $positions
            ?? RepositoryFactory::positions();
        $this->branches = $branches
            ?? RepositoryFactory::branches();
        $this->departments = $departments
            ?? RepositoryFactory::departments();
        $this->jobTitles = $jobTitles
            ?? RepositoryFactory::jobTitles();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array{
     *     positions: list<array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         open: int,
     *         planned: int,
     *         approvedHeadcount: int
     *     }
     * }
     */
    public function listing(): array
    {
        $positions = $this->positions
            ->listForCompany(
                $this->tenant->companyId()
            );
        $open = 0;
        $planned = 0;
        $approvedHeadcount = 0;

        foreach ($positions as &$position) {
            $position['position_id'] = (int) (
                $position['position_id'] ?? 0
            );
            $position['branch_id'] =
                $this->nullableInteger(
                    $position['branch_id'] ?? null
                );
            $position['department_id'] = (int) (
                $position['department_id'] ?? 0
            );
            $position['job_title_id'] = (int) (
                $position['job_title_id'] ?? 0
            );
            $position['approved_headcount'] =
                (int) (
                    $position[
                        'approved_headcount'
                    ] ?? 0
                );
            $position['status'] = (string) (
                $position['status'] ?? ''
            );
            $open += $position['status'] === 'open'
                ? 1
                : 0;
            $planned +=
                $position['status'] === 'planned'
                    ? 1
                    : 0;
            $approvedHeadcount +=
                $position['approved_headcount'];
        }

        unset($position);

        return [
            'positions' => $positions,
            'summary' => [
                'total' => count($positions),
                'open' => $open,
                'planned' => $planned,
                'approvedHeadcount' =>
                    $approvedHeadcount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function form(int $positionId): ?array
    {
        $position = $this->positions->find(
            $this->tenant->companyId(),
            $positionId
        );

        if ($position === null) {
            return null;
        }

        return $this->recordValues($position)
            + [
                'position_id' => (int) (
                    $position['position_id'] ?? 0
                ),
            ];
    }

    /**
     * @return array{
     *     branches: list<array<string, mixed>>,
     *     departments: list<array<string, mixed>>,
     *     jobTitles: list<array<string, mixed>>,
     *     statuses: array<string, string>
     * }
     */
    public function options(): array
    {
        $companyId = $this->tenant->companyId();
        $branches = array_values(array_filter(
            $this->branches->listForCompany($companyId),
            static fn (array $branch): bool =>
                !empty($branch['active'])
        ));
        $jobTitles = array_values(array_filter(
            $this->jobTitles->listForCompany($companyId),
            static fn (array $jobTitle): bool =>
                !empty($jobTitle['active'])
        ));

        return [
            'branches' => $branches,
            'departments' =>
                $this->departments->activeOptions(
                    $companyId
                ),
            'jobTitles' => $jobTitles,
            'statuses' => self::STATUSES,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     positionId?: int,
     *     positionName?: string
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

            $positionId = $this->positions->create(
                $companyId,
                $values,
                $createdBy
            );
            $this->auditLogs->record(
                $createdBy,
                'CREATE',
                'organization',
                'organization_positions',
                (string) $positionId,
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
            'positionId' => $positionId,
            'positionName' => (string) (
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
     *     positionId?: int,
     *     positionName?: string,
     *     changed?: bool
     * }
     */
    public function update(
        int $positionId,
        array $input,
        int $updatedBy
    ): array {
        $companyId = $this->tenant->companyId();
        $position = $this->positions->find(
            $companyId,
            $positionId
        );

        if ($position === null) {
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
            $positionId
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $oldValues = $this->recordValues($position);
        $newValues = $this->recordValues($values);

        if ($oldValues === $newValues) {
            return [
                'successful' => true,
                'errors' => [],
                'positionId' => $positionId,
                'positionName' => (string) (
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

            $updated = $this->positions->update(
                $companyId,
                $positionId,
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
                'organization_positions',
                (string) $positionId,
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
            'positionId' => $positionId,
            'positionName' => (string) (
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
            'branch_id' =>
                $this->normalizeOptionalId(
                    $input['branch_id'] ?? null
                ),
            'department_id' =>
                $this->normalizeRequiredId(
                    $input['department_id'] ?? null
                ),
            'job_title_id' =>
                $this->normalizeRequiredId(
                    $input['job_title_id'] ?? null
                ),
            'approved_headcount' =>
                $this->normalizeRequiredId(
                    $input['approved_headcount']
                        ?? null
                ),
            'status' => strtolower(trim((string) (
                $input['status'] ?? ''
            ))),
            'description' => $this->nullable(
                $input['description'] ?? null
            ),
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
        ?int $ignorePositionId = null
    ): array {
        $errors = [];
        $code = (string) $values['code'];
        $name = (string) $values['name'];

        if (
            preg_match(
                '/^[A-Z][A-Z0-9_-]{1,39}$/',
                $code
            ) !== 1
        ) {
            $errors['code'] =
                'Code must contain 2-40 uppercase letters, numbers, hyphens or underscores and begin with a letter.';
        } elseif (
            $this->positions->codeExists(
                $companyId,
                $code,
                $ignorePositionId
            )
        ) {
            $errors['code'] =
                'That position code is already in use.';
        }

        if (
            mb_strlen($name) < 2
            || mb_strlen($name) > 140
        ) {
            $errors['name'] =
                'Position name must contain 2-140 characters.';
        }

        if (
            !array_key_exists(
                (string) $values['status'],
                self::STATUSES
            )
        ) {
            $errors['status'] =
                'Select a valid position status.';
        }

        $headcount = (int) (
            $values['approved_headcount'] ?? 0
        );

        if ($headcount < 1 || $headcount > 10000) {
            $errors['approved_headcount'] =
                'Approved headcount must be between 1 and 10,000.';
        }

        if (
            is_string($values['description'])
            && mb_strlen($values['description']) > 500
        ) {
            $errors['description'] =
                'Description cannot exceed 500 characters.';
        }

        $departmentId = (int) (
            $values['department_id'] ?? 0
        );
        $department = $this->departments->find(
            $companyId,
            $departmentId
        );

        if (
            $department === null
            || empty($department['active'])
        ) {
            $errors['department_id'] =
                'Select an active department from the current company.';
        }

        $jobTitleId = (int) (
            $values['job_title_id'] ?? 0
        );
        $jobTitle = $this->jobTitles->find(
            $companyId,
            $jobTitleId
        );

        if (
            $jobTitle === null
            || empty($jobTitle['active'])
        ) {
            $errors['job_title_id'] =
                'Select an active job title from the current company.';
        }

        $branchId = $values['branch_id'];

        if ($branchId !== null) {
            $branch = $this->branches->find(
                $companyId,
                (int) $branchId
            );

            if (
                $branch === null
                || empty($branch['active'])
            ) {
                $errors['branch_id'] =
                    'Select an active branch from the current company.';
            }
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
            'branch_id' => $this->nullableInteger(
                $record['branch_id'] ?? null
            ),
            'department_id' => (int) (
                $record['department_id'] ?? 0
            ),
            'job_title_id' => (int) (
                $record['job_title_id'] ?? 0
            ),
            'approved_headcount' => (int) (
                $record['approved_headcount'] ?? 0
            ),
            'status' => (string) (
                $record['status'] ?? ''
            ),
            'description' => $this->nullable(
                $record['description'] ?? null
            ),
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

    private function normalizeOptionalId(
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

        return $this->nullableInteger($value)
            ?? -1;
    }

    private function normalizeRequiredId(
        mixed $value
    ): int {
        return $this->nullableInteger($value)
            ?? 0;
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
                    'A position with that code already exists, or one of its organization references is no longer valid.',
            ],
        ];
    }
}
