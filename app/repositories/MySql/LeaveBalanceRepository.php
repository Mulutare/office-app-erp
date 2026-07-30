<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\LeaveBalanceRepository
    as LeaveBalanceRepositoryContract;

final class LeaveBalanceRepository extends MySqlRepository
    implements LeaveBalanceRepositoryContract
{
    public function employeeOptions(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                employee_id,
                employee_number,
                first_name,
                last_name,
                preferred_name,
                job_title
             FROM hr_employees
             WHERE company_id = :company_id
               AND employment_status
                    IN (\'active\', \'on_leave\')
               AND deleted_at IS NULL
             ORDER BY
                COALESCE(
                    NULLIF(preferred_name, \'\'),
                    first_name
                ),
                last_name,
                employee_number'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $employees = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($employees)
            ? $employees
            : [];
    }

    public function employee(
        int $companyId,
        int $employeeId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                employee_id,
                employee_number,
                first_name,
                last_name,
                preferred_name,
                job_title
             FROM hr_employees
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND employment_status
                    IN (\'active\', \'on_leave\')
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
        ]);
        $employee = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($employee)
            ? $employee
            : null;
    }

    public function policy(
        int $companyId,
        int $leaveTypeId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                leave_type_id,
                code,
                name,
                annual_entitlement,
                active
             FROM hr_leave_types
             WHERE company_id = :company_id
               AND leave_type_id = :leave_type_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'leave_type_id' => $leaveTypeId,
        ]);
        $policy = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($policy)
            ? $policy
            : null;
    }

    public function allocation(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $year
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                allocation_id,
                employee_id,
                leave_type_id,
                allocation_year,
                entitlement_days,
                carry_over_days,
                notes,
                created_at,
                updated_at
             FROM hr_leave_allocations
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND leave_type_id = :leave_type_id
               AND allocation_year =
                    :allocation_year
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'allocation_year' => $year,
        ]);
        $allocation = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($allocation)
            ? $allocation
            : null;
    }

    public function saveAllocation(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $year,
        array $values,
        int $updatedBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO hr_leave_allocations
                (
                    company_id,
                    employee_id,
                    leave_type_id,
                    allocation_year,
                    entitlement_days,
                    carry_over_days,
                    notes,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :employee_id,
                    :leave_type_id,
                    :allocation_year,
                    :entitlement_days,
                    :carry_over_days,
                    :notes,
                    :created_by,
                    :updated_by
                )
             ON DUPLICATE KEY UPDATE
                entitlement_days =
                    VALUES(entitlement_days),
                carry_over_days =
                    VALUES(carry_over_days),
                notes = VALUES(notes),
                updated_by = VALUES(updated_by)'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'allocation_year' => $year,
            'entitlement_days' =>
                $values['entitlement_days'],
            'carry_over_days' =>
                $values['carry_over_days'],
            'notes' => $values['notes'],
            'created_by' => $updatedBy,
            'updated_by' => $updatedBy,
        ]);

        $allocation = $this->allocation(
            $companyId,
            $employeeId,
            $leaveTypeId,
            $year
        );

        return (int) (
            $allocation['allocation_id'] ?? 0
        );
    }

    public function addAdjustment(
        int $companyId,
        int $allocationId,
        array $values,
        int $createdBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO hr_leave_balance_adjustments
                (
                    company_id,
                    allocation_id,
                    adjustment_type,
                    adjustment_days,
                    effective_date,
                    reason,
                    created_by
                )
             VALUES
                (
                    :company_id,
                    :allocation_id,
                    :adjustment_type,
                    :adjustment_days,
                    :effective_date,
                    :reason,
                    :created_by
                )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'allocation_id' => $allocationId,
            'adjustment_type' =>
                $values['adjustment_type'],
            'adjustment_days' =>
                $values['adjustment_days'],
            'effective_date' =>
                $values['effective_date'],
            'reason' => $values['reason'],
            'created_by' => $createdBy,
        ]);

        return (int) $this->connection()
            ->lastInsertId();
    }

    public function adjustments(
        int $companyId,
        int $employeeId,
        int $year
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                adjustments.adjustment_id,
                adjustments.adjustment_type,
                adjustments.adjustment_days,
                adjustments.effective_date,
                adjustments.reason,
                adjustments.created_at,
                allocations.allocation_id,
                allocations.leave_type_id,
                types.code AS leave_type_code,
                types.name AS leave_type_name,
                users.display_name AS created_by_name
             FROM hr_leave_balance_adjustments
                    adjustments
             INNER JOIN hr_leave_allocations
                    allocations
               ON allocations.company_id =
                    adjustments.company_id
              AND allocations.allocation_id =
                    adjustments.allocation_id
             INNER JOIN hr_leave_types types
               ON types.company_id =
                    allocations.company_id
              AND types.leave_type_id =
                    allocations.leave_type_id
             LEFT JOIN users
               ON users.user_id =
                    adjustments.created_by
             WHERE adjustments.company_id =
                    :company_id
               AND allocations.employee_id =
                    :employee_id
               AND allocations.allocation_year =
                    :allocation_year
             ORDER BY
                adjustments.effective_date DESC,
                adjustments.adjustment_id DESC
             LIMIT 100'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'allocation_year' => $year,
        ]);
        $records = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($records)
            ? $records
            : [];
    }
}
