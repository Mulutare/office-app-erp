<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\LeaveBalanceRepository
    as LeaveBalanceRepositoryContract;

final class LeaveBalanceRepository extends OracleRepository
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
             FETCH FIRST 1 ROWS ONLY'
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
             FETCH FIRST 1 ROWS ONLY'
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
             FETCH FIRST 1 ROWS ONLY'
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
            'MERGE INTO hr_leave_allocations target
             USING (
                SELECT
                    :company_id AS company_id,
                    :employee_id AS employee_id,
                    :leave_type_id AS leave_type_id,
                    :allocation_year AS allocation_year,
                    :entitlement_days AS entitlement_days,
                    :carry_over_days AS carry_over_days,
                    :notes AS notes,
                    :updated_by AS updated_by
                FROM dual
             ) source
             ON (
                target.company_id = source.company_id
                AND target.employee_id =
                    source.employee_id
                AND target.leave_type_id =
                    source.leave_type_id
                AND target.allocation_year =
                    source.allocation_year
             )
             WHEN MATCHED THEN UPDATE SET
                target.entitlement_days =
                    source.entitlement_days,
                target.carry_over_days =
                    source.carry_over_days,
                target.notes = source.notes,
                target.updated_by = source.updated_by,
                target.updated_at = SYSTIMESTAMP
             WHEN NOT MATCHED THEN INSERT (
                company_id,
                employee_id,
                leave_type_id,
                allocation_year,
                entitlement_days,
                carry_over_days,
                notes,
                created_by,
                updated_by
             ) VALUES (
                source.company_id,
                source.employee_id,
                source.leave_type_id,
                source.allocation_year,
                source.entitlement_days,
                source.carry_over_days,
                source.notes,
                source.updated_by,
                source.updated_by
             )'
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
                    TO_DATE(
                        :effective_date,
                        \'YYYY-MM-DD\'
                    ),
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

        $lookup = $this->connection()->prepare(
            'SELECT adjustment_id
             FROM hr_leave_balance_adjustments
             WHERE company_id = :company_id
               AND allocation_id = :allocation_id
               AND adjustment_type =
                    :adjustment_type
               AND adjustment_days =
                    :adjustment_days
               AND effective_date = TO_DATE(
                    :effective_date,
                    \'YYYY-MM-DD\'
               )
               AND reason = :reason
               AND created_by = :created_by
             ORDER BY adjustment_id DESC
             FETCH FIRST 1 ROWS ONLY'
        );
        $lookup->execute([
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

        return (int) $lookup->fetchColumn();
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
                TO_CHAR(
                    adjustments.effective_date,
                    \'YYYY-MM-DD\'
                ) AS effective_date,
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
             FETCH FIRST 100 ROWS ONLY'
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
