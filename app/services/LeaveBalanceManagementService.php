<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\LeaveBalanceRepository;
use App\Repositories\LeaveRepository;
use App\Repositories\RepositoryFactory;
use DateTimeImmutable;
use Throwable;

final class LeaveBalanceManagementService
{
    private LeaveBalanceRepository $balances;
    private LeaveRepository $leave;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?LeaveBalanceRepository $balances = null,
        ?LeaveRepository $leave = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->balances = $balances
            ?? RepositoryFactory::leaveBalances();
        $this->leave = $leave
            ?? RepositoryFactory::leave();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function workspace(
        int $employeeId,
        int $year,
        int $leaveTypeId
    ): array {
        $companyId = $this->tenant->companyId();
        $year = $this->year($year);
        $employees = $this->balances
            ->employeeOptions($companyId);

        if ($employeeId < 1 && $employees !== []) {
            $employeeId = (int) (
                $employees[0]['employee_id'] ?? 0
            );
        }

        $employee = $employeeId > 0
            ? $this->balances->employee(
                $companyId,
                $employeeId
            )
            : null;

        if ($employeeId > 0 && $employee === null) {
            return [
                'notFound' => true,
            ];
        }

        foreach ($employees as &$option) {
            $option['displayName'] =
                $this->employeeName($option);
        }
        unset($option);

        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);
        $records = $employee === null
            ? []
            : $this->leave->balancesForEmployee(
                $companyId,
                $employeeId,
                $yearStart,
                $yearEnd
            );
        $summary = [
            'policies' => count($records),
            'allocated' => 0.0,
            'used' => 0.0,
            'remaining' => 0.0,
        ];

        foreach ($records as &$record) {
            $this->presentBalance($record);
            $summary['allocated'] += (float) (
                $record['availableDays'] ?? 0
            );
            $summary['used'] += (float) (
                $record['usedDays'] ?? 0
            );
            $summary['remaining'] += (float) (
                $record['remainingDays'] ?? 0
            );
        }
        unset($record);

        $policy = null;
        $allocation = null;

        if ($leaveTypeId > 0) {
            $policy = $this->balances->policy(
                $companyId,
                $leaveTypeId
            );

            if ($policy === null) {
                return [
                    'notFound' => true,
                ];
            }

            $allocation = $this->balances
                ->allocation(
                    $companyId,
                    $employeeId,
                    $leaveTypeId,
                    $year
                );
        }

        if ($employee !== null) {
            $employee['displayName'] =
                $this->employeeName($employee);
        }

        return [
            'notFound' => false,
            'employees' => $employees,
            'employee' => $employee,
            'employeeId' => $employeeId,
            'year' => $year,
            'years' => $this->years($year),
            'balances' => $records,
            'summary' => [
                'policies' => $summary['policies'],
                'allocated' => $this->days(
                    $summary['allocated']
                ),
                'used' => $this->days(
                    $summary['used']
                ),
                'remaining' => $this->days(
                    $summary['remaining']
                ),
            ],
            'selectedPolicy' => $policy,
            'allocation' => $allocation,
            'allocationForm' => $policy === null
                ? []
                : [
                    'entitlement_days' =>
                        $this->days(
                            $allocation[
                                'entitlement_days'
                            ] ?? $policy[
                                'annual_entitlement'
                            ] ?? 0
                        ),
                    'carry_over_days' =>
                        $this->days(
                            $allocation[
                                'carry_over_days'
                            ] ?? 0
                        ),
                    'notes' => (string) (
                        $allocation['notes'] ?? ''
                    ),
                ],
            'adjustments' => $employee === null
                ? []
                : $this->presentAdjustments(
                    $this->balances->adjustments(
                        $companyId,
                        $employeeId,
                        $year
                    )
                ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function saveAllocation(
        array $input,
        int $updatedBy
    ): array {
        $companyId = $this->tenant->companyId();
        $values = $this->allocationValues($input);
        $scope = $this->scope(
            $companyId,
            $values
        );
        $errors = $scope['errors'];

        if (
            !$this->validDays(
                $values['entitlement_days']
            )
        ) {
            $errors['entitlement_days'] =
                'Entitlement must be between 0 and 366 days with no more than two decimal places.';
        }

        if (
            !$this->validDays(
                $values['carry_over_days']
            )
        ) {
            $errors['carry_over_days'] =
                'Carry-over must be between 0 and 366 days with no more than two decimal places.';
        }

        if (
            is_string($values['notes'])
            && mb_strlen($values['notes']) > 500
        ) {
            $errors['notes'] =
                'Notes cannot exceed 500 characters.';
        }

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $old = $this->balances->allocation(
            $companyId,
            (int) $values['employee_id'],
            (int) $values['leave_type_id'],
            (int) $values['year']
        );
        $new = [
            'employee_id' =>
                (int) $values['employee_id'],
            'leave_type_id' =>
                (int) $values['leave_type_id'],
            'allocation_year' =>
                (int) $values['year'],
            'entitlement_days' =>
                $this->days(
                    $values['entitlement_days']
                ),
            'carry_over_days' =>
                $this->days(
                    $values['carry_over_days']
                ),
            'notes' => $values['notes'],
        ];

        if (
            $old !== null
            && $this->allocationRecord($old) === $new
        ) {
            return [
                'successful' => true,
                'errors' => [],
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

            $allocationId = $this->balances
                ->saveAllocation(
                    $companyId,
                    (int) $values['employee_id'],
                    (int) $values['leave_type_id'],
                    (int) $values['year'],
                    $new,
                    $updatedBy
                );
            $this->auditLogs->record(
                $updatedBy,
                $old === null
                    ? 'CREATE_LEAVE_ALLOCATION'
                    : 'UPDATE_LEAVE_ALLOCATION',
                'hr',
                'hr_leave_allocations',
                (string) $allocationId,
                $old === null
                    ? null
                    : $this->allocationRecord($old),
                $new,
                $companyId
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
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
            'changed' => true,
            'allocationId' => $allocationId,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function addAdjustment(
        array $input,
        int $createdBy
    ): array {
        $companyId = $this->tenant->companyId();
        $values = $this->adjustmentValues($input);
        $scope = $this->scope(
            $companyId,
            $values
        );
        $errors = $scope['errors'];

        if (!in_array(
            $values['adjustment_type'],
            ['credit', 'debit'],
            true
        )) {
            $errors['adjustment_type'] =
                'Select credit or debit.';
        }

        if (
            !$this->validDays($values['days'])
            || (float) $values['days'] <= 0
        ) {
            $errors['days'] =
                'Adjustment must be greater than 0 and no more than 366 days.';
        }

        if (
            !$this->validDate(
                $values['effective_date']
            )
        ) {
            $errors['effective_date'] =
                'Enter a valid effective date.';
        } elseif (
            (int) substr(
                $values['effective_date'],
                0,
                4
            ) !== (int) $values['year']
        ) {
            $errors['effective_date'] =
                'Effective date must be inside the selected balance year.';
        }

        $reasonLength = mb_strlen(
            $values['reason']
        );

        if (
            $reasonLength < 3
            || $reasonLength > 500
        ) {
            $errors['reason'] =
                'Reason must contain 3-500 characters.';
        }

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $employeeId = (int) $values['employee_id'];
        $leaveTypeId =
            (int) $values['leave_type_id'];
        $year = (int) $values['year'];
        $allocation = $this->balances->allocation(
            $companyId,
            $employeeId,
            $leaveTypeId,
            $year
        );
        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            if ($allocation === null) {
                $policy = $scope['policy'];
                $allocationValues = [
                    'entitlement_days' =>
                        $this->days(
                            $policy[
                                'annual_entitlement'
                            ] ?? 0
                        ),
                    'carry_over_days' => '0.00',
                    'notes' => null,
                ];
                $allocationId = $this->balances
                    ->saveAllocation(
                        $companyId,
                        $employeeId,
                        $leaveTypeId,
                        $year,
                        $allocationValues,
                        $createdBy
                    );
                $this->auditLogs->record(
                    $createdBy,
                    'CREATE_LEAVE_ALLOCATION',
                    'hr',
                    'hr_leave_allocations',
                    (string) $allocationId,
                    null,
                    [
                        'employee_id' => $employeeId,
                        'leave_type_id' =>
                            $leaveTypeId,
                        'allocation_year' => $year,
                    ] + $allocationValues,
                    $companyId
                );
            } else {
                $allocationId = (int) (
                    $allocation['allocation_id'] ?? 0
                );
            }

            $signedDays =
                $values['adjustment_type'] === 'debit'
                    ? -(float) $values['days']
                    : (float) $values['days'];
            $record = [
                'adjustment_type' =>
                    $values['adjustment_type'],
                'adjustment_days' =>
                    $this->days($signedDays),
                'effective_date' =>
                    $values['effective_date'],
                'reason' => $values['reason'],
            ];
            $adjustmentId = $this->balances
                ->addAdjustment(
                    $companyId,
                    $allocationId,
                    $record,
                    $createdBy
                );
            $this->auditLogs->record(
                $createdBy,
                'ADJUST_LEAVE_BALANCE',
                'hr',
                'hr_leave_balance_adjustments',
                (string) $adjustmentId,
                null,
                [
                    'allocation_id' => $allocationId,
                    'employee_id' => $employeeId,
                    'leave_type_id' => $leaveTypeId,
                    'allocation_year' => $year,
                ] + $record,
                $companyId
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
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
            'adjustmentId' => $adjustmentId,
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{
     *     errors: array<string, string>,
     *     policy: array<string, mixed>
     * }
     */
    private function scope(
        int $companyId,
        array $values
    ): array {
        $errors = [];
        $employee = $this->balances->employee(
            $companyId,
            (int) ($values['employee_id'] ?? 0)
        );
        $policy = $this->balances->policy(
            $companyId,
            (int) ($values['leave_type_id'] ?? 0)
        );
        $year = (int) ($values['year'] ?? 0);

        if ($employee === null) {
            $errors['employee_id'] =
                'Select an active employee from this company.';
        }

        if ($policy === null) {
            $errors['leave_type_id'] =
                'Select a leave policy from this company.';
        }

        if ($year < 2000 || $year > 2100) {
            $errors['year'] =
                'Select a balance year from 2000-2100.';
        }

        return [
            'errors' => $errors,
            'policy' => $policy ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function allocationValues(
        array $input
    ): array {
        return [
            'employee_id' => $this->integer(
                $input['employee_id'] ?? null
            ),
            'leave_type_id' => $this->integer(
                $input['leave_type_id'] ?? null
            ),
            'year' => $this->integer(
                $input['year'] ?? null
            ),
            'entitlement_days' => trim(
                (string) (
                    $input['entitlement_days'] ?? ''
                )
            ),
            'carry_over_days' => trim(
                (string) (
                    $input['carry_over_days'] ?? ''
                )
            ),
            'notes' => $this->nullable(
                $input['notes'] ?? null
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function adjustmentValues(
        array $input
    ): array {
        return [
            'employee_id' => $this->integer(
                $input['employee_id'] ?? null
            ),
            'leave_type_id' => $this->integer(
                $input['leave_type_id'] ?? null
            ),
            'year' => $this->integer(
                $input['year'] ?? null
            ),
            'adjustment_type' => strtolower(
                trim((string) (
                    $input['adjustment_type'] ?? ''
                ))
            ),
            'days' => trim((string) (
                $input['days'] ?? ''
            )),
            'effective_date' => trim(
                (string) (
                    $input['effective_date'] ?? ''
                )
            ),
            'reason' => trim((string) (
                $input['reason'] ?? ''
            )),
        ];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function allocationRecord(
        array $record
    ): array {
        return [
            'employee_id' => (int) (
                $record['employee_id'] ?? 0
            ),
            'leave_type_id' => (int) (
                $record['leave_type_id'] ?? 0
            ),
            'allocation_year' => (int) (
                $record['allocation_year'] ?? 0
            ),
            'entitlement_days' => $this->days(
                $record['entitlement_days'] ?? 0
            ),
            'carry_over_days' => $this->days(
                $record['carry_over_days'] ?? 0
            ),
            'notes' => $this->nullable(
                $record['notes'] ?? null
            ),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function presentBalance(
        array &$record
    ): void {
        $entitlement = (float) (
            $record['annual_entitlement'] ?? 0
        );
        $carryOver = (float) (
            $record['carry_over_days'] ?? 0
        );
        $adjustments = (float) (
            $record['adjustment_days'] ?? 0
        );
        $used = (float) (
            $record['used_days'] ?? 0
        );
        $available =
            $entitlement + $carryOver + $adjustments;

        $record['entitlementDays'] =
            $this->days($entitlement);
        $record['carryOverDays'] =
            $this->days($carryOver);
        $record['adjustmentDays'] =
            $this->days($adjustments);
        $record['availableDays'] =
            $this->days($available);
        $record['usedDays'] = $this->days($used);
        $record['remainingDays'] =
            $this->days($available - $used);
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    private function presentAdjustments(
        array $records
    ): array {
        foreach ($records as &$record) {
            $record['adjustmentDays'] =
                $this->days(
                    $record['adjustment_days'] ?? 0
                );
            $record['typeLabel'] =
                ($record['adjustment_type'] ?? '')
                    === 'debit'
                    ? 'Debit'
                    : 'Credit';
        }
        unset($record);

        return $records;
    }

    /**
     * @return list<int>
     */
    private function years(int $selected): array
    {
        $current = (int) date('Y');
        $years = range($current - 2, $current + 2);

        if (!in_array($selected, $years, true)) {
            $years[] = $selected;
            rsort($years);
        }

        return array_values(
            array_unique($years)
        );
    }

    private function year(int $year): int
    {
        return $year >= 2000 && $year <= 2100
            ? $year
            : (int) date('Y');
    }

    private function validDays(mixed $value): bool
    {
        $value = trim((string) $value);

        return preg_match(
            '/^\d{1,3}(?:\.\d{1,2})?$/',
            $value
        ) === 1
            && (float) $value <= 366;
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

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : 0;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function days(mixed $value): string
    {
        return number_format(
            (float) $value,
            2,
            '.',
            ''
        );
    }

    /**
     * @param array<string, mixed> $employee
     */
    private function employeeName(array $employee): string
    {
        $preferred = trim((string) (
            $employee['preferred_name'] ?? ''
        ));
        $first = $preferred !== ''
            ? $preferred
            : trim((string) (
                $employee['first_name'] ?? ''
            ));
        $name = trim(
            $first . ' ' . (string) (
                $employee['last_name'] ?? ''
            )
        );

        return $name !== ''
            ? $name
            : (string) (
                $employee['employee_number']
                    ?? 'Employee'
            );
    }
}
