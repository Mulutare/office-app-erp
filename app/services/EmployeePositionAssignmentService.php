<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\EmployeePositionAssignmentRepository;
use App\Repositories\RepositoryFactory;
use DateTimeImmutable;
use PDOException;
use Throwable;

final class EmployeePositionAssignmentService
{
    private EmployeePositionAssignmentRepository
        $assignments;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?EmployeePositionAssignmentRepository
            $assignments = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->assignments = $assignments
            ?? RepositoryFactory::
                employeePositionAssignments();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array{
     *     current: array<string, mixed>|null,
     *     history: list<array<string, mixed>>
     * }
     */
    public function overview(int $employeeId): array
    {
        if ($employeeId < 1) {
            return [
                'current' => null,
                'history' => [],
            ];
        }

        $companyId = $this->tenant->companyId();

        return [
            'current' => $this->assignments->current(
                $companyId,
                $employeeId
            ),
            'history' => $this->assignments->history(
                $companyId,
                $employeeId
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function form(int $employeeId): ?array
    {
        if ($employeeId < 1) {
            return null;
        }

        $companyId = $this->tenant->companyId();
        $employee = $this->assignments->employee(
            $companyId,
            $employeeId
        );

        if ($employee === null) {
            return null;
        }

        $employee['display_name'] =
            $this->employeeName($employee);

        return [
            'employee' => $employee,
            'current' => $this->assignments->current(
                $companyId,
                $employeeId
            ),
            'positions' =>
                $this->normalizePositions(
                    $this->assignments
                        ->positionOptions($companyId)
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
     *     employeeId?: int,
     *     employeeName?: string,
     *     positionName?: string
     * }
     */
    public function assign(
        int $employeeId,
        array $input,
        int $actorId
    ): array {
        $positionId = $this->positiveInteger(
            $input['position_id'] ?? null
        );
        $effectiveFrom = trim((string) (
            $input['effective_from'] ?? ''
        ));
        $notes = trim((string) (
            $input['notes'] ?? ''
        ));
        $errors = $this->validateInput(
            $positionId,
            $effectiveFrom,
            $notes
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $companyId = $this->tenant->companyId();
        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $employee = $this->assignments->employee(
                $companyId,
                $employeeId,
                true
            );

            if ($employee === null) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'notFound' => true,
                    'errors' => [],
                ];
            }

            $current = $this->assignments->current(
                $companyId,
                $employeeId,
                true
            );
            $position = $this->assignments->position(
                $companyId,
                $positionId,
                true
            );
            $businessErrors =
                $this->validateBusinessRules(
                    $employee,
                    $current,
                    $position,
                    $effectiveFrom
                );

            if ($businessErrors !== []) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'errors' => $businessErrors,
                ];
            }

            if (
                $this->assignments
                    ->currentPositionCount(
                        $companyId,
                        $positionId
                    )
                >= (int) $position[
                    'approved_headcount'
                ]
            ) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'errors' => [
                        'position_id' =>
                            'That position has reached its approved headcount.',
                    ],
                ];
            }

            if ($current !== null) {
                $this->assignments->endAssignment(
                    $companyId,
                    (int) $current['assignment_id'],
                    $effectiveFrom,
                    $actorId
                );
            }

            $values = [
                'effective_from' => $effectiveFrom,
                'position_code_snapshot' =>
                    (string) $position['code'],
                'position_name_snapshot' =>
                    (string) $position['name'],
                'department_name_snapshot' =>
                    (string) $position[
                        'department_name'
                    ],
                'job_title_name_snapshot' =>
                    (string) $position[
                        'job_title_name'
                    ],
                'branch_name_snapshot' =>
                    $this->nullableString(
                        $position['branch_name'] ?? null
                    ),
                'notes' => $notes === ''
                    ? null
                    : $notes,
            ];
            $assignmentId =
                $this->assignments->create(
                    $companyId,
                    $employeeId,
                    $positionId,
                    $values,
                    $actorId
                );
            $this->assignments
                ->synchronizeEmployeeOrganization(
                    $companyId,
                    $employeeId,
                    (int) $position['department_id'],
                    (string) $position[
                        'job_title_name'
                    ],
                    $actorId
                );
            $this->auditLogs->record(
                $actorId,
                $current === null
                    ? 'ASSIGN_POSITION'
                    : 'TRANSFER_POSITION',
                'hr',
                'hr_employee_position_assignments',
                (string) $assignmentId,
                $current === null
                    ? null
                    : [
                        'assignment_id' =>
                            (int) $current[
                                'assignment_id'
                            ],
                        'position_id' =>
                            (int) $current[
                                'position_id'
                            ],
                        'position_name' =>
                            (string) $current[
                                'position_name_snapshot'
                            ],
                        'effective_from' =>
                            (string) $current[
                                'effective_from'
                            ],
                        'effective_to' =>
                            $effectiveFrom,
                    ],
                [
                    'employee_id' => $employeeId,
                    'position_id' => $positionId,
                    'position_name' =>
                        (string) $position['name'],
                    'effective_from' =>
                        $effectiveFrom,
                ],
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
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'The assignment changed while you were working. Reload and try again.',
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
            'employeeId' => $employeeId,
            'employeeName' =>
                $this->employeeName($employee),
            'positionName' =>
                (string) $position['name'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validateInput(
        int $positionId,
        string $effectiveFrom,
        string $notes
    ): array {
        $errors = [];

        if ($positionId < 1) {
            $errors['position_id'] =
                'Select an approved open position.';
        }

        if (!$this->validDate($effectiveFrom)) {
            $errors['effective_from'] =
                'Enter a valid effective date.';
        } elseif (
            $effectiveFrom
            > (new DateTimeImmutable('today'))
                ->format('Y-m-d')
        ) {
            $errors['effective_from'] =
                'Future-dated assignments are not supported in this phase.';
        }

        if (mb_strlen($notes) > 500) {
            $errors['notes'] =
                'Notes must not exceed 500 characters.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $employee
     * @param array<string, mixed>|null $current
     * @param array<string, mixed>|null $position
     *
     * @return array<string, string>
     */
    private function validateBusinessRules(
        array $employee,
        ?array $current,
        ?array $position,
        string $effectiveFrom
    ): array {
        $errors = [];

        if (
            (string) $employee['employment_status']
            === 'terminated'
        ) {
            $errors['form'] =
                'A terminated employee cannot receive a current position assignment.';
        }

        $hireDate = substr(
            (string) $employee['hire_date'],
            0,
            10
        );

        if ($effectiveFrom < $hireDate) {
            $errors['effective_from'] =
                'The assignment cannot begin before the employee hire date.';
        }

        if ($position === null) {
            $errors['position_id'] =
                'The selected position is not available in this company.';

            return $errors;
        }

        if ((string) $position['status'] !== 'open') {
            $errors['position_id'] =
                'Only open positions can receive an employee.';
        }

        if ($current !== null) {
            if (
                (int) $current['position_id']
                === (int) $position['position_id']
            ) {
                $errors['position_id'] =
                    'The employee already holds this position.';
            }

            if (
                $effectiveFrom
                <= substr(
                    (string) $current[
                        'effective_from'
                    ],
                    0,
                    10
                )
            ) {
                $errors['effective_from'] =
                    'A transfer must start after the current assignment start date.';
            }
        }

        return $errors;
    }

    private function employeeName(array $employee): string
    {
        $preferred = trim((string) (
            $employee['preferred_name'] ?? ''
        ));
        $first = trim((string) (
            $employee['first_name'] ?? ''
        ));
        $last = trim((string) (
            $employee['last_name'] ?? ''
        ));

        return trim(
            ($preferred !== '' ? $preferred : $first)
            . ' '
            . $last
        );
    }

    /**
     * @param list<array<string, mixed>> $positions
     *
     * @return list<array<string, mixed>>
     */
    private function normalizePositions(
        array $positions
    ): array {
        foreach ($positions as &$position) {
            $position['position_id'] = (int) (
                $position['position_id'] ?? 0
            );
            $position['approved_headcount'] =
                (int) (
                    $position[
                        'approved_headcount'
                    ] ?? 0
                );
            $position['filled_headcount'] = (int) (
                $position['filled_headcount'] ?? 0
            );
            $position['available'] =
                $position['filled_headcount']
                < $position['approved_headcount'];
        }

        unset($position);

        return $positions;
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : 0;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );

        return $date !== false
            && $date->format('Y-m-d') === $value;
    }

    private function nullableString(
        mixed $value
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
