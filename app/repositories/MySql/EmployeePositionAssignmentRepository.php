<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\EmployeePositionAssignmentRepository
    as AssignmentRepositoryContract;

final class EmployeePositionAssignmentRepository
    extends MySqlRepository
    implements AssignmentRepositoryContract
{
    public function employee(
        int $companyId,
        int $employeeId,
        bool $lock = false
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                employee_id,
                employee_number,
                first_name,
                last_name,
                preferred_name,
                employment_status,
                hire_date,
                termination_date
             FROM hr_employees
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND deleted_at IS NULL'
            . ($lock ? ' FOR UPDATE' : '')
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

    public function history(
        int $companyId,
        int $employeeId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                assignments.assignment_id,
                assignments.position_id,
                assignments.effective_from,
                assignments.effective_to,
                assignments.assignment_status,
                assignments.position_code_snapshot,
                assignments.position_name_snapshot,
                assignments.department_name_snapshot,
                assignments.job_title_name_snapshot,
                assignments.branch_name_snapshot,
                assignments.notes,
                assignments.created_at,
                users.display_name AS assigned_by_name
             FROM hr_employee_position_assignments
                assignments
             LEFT JOIN users
               ON users.user_id =
                    assignments.created_by
             WHERE assignments.company_id =
                    :company_id
               AND assignments.employee_id =
                    :employee_id
             ORDER BY
                assignments.effective_from DESC,
                assignments.assignment_id DESC'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
        ]);
        $history = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($history) ? $history : [];
    }

    public function current(
        int $companyId,
        int $employeeId,
        bool $lock = false
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                assignment_id,
                position_id,
                effective_from,
                position_code_snapshot,
                position_name_snapshot,
                department_name_snapshot,
                job_title_name_snapshot,
                branch_name_snapshot
             FROM hr_employee_position_assignments
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND current_marker = 1'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
        ]);
        $assignment = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($assignment)
            ? $assignment
            : null;
    }

    public function positionOptions(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                positions.position_id,
                positions.code,
                positions.name,
                positions.approved_headcount,
                departments.name AS department_name,
                job_titles.name AS job_title_name,
                branches.name AS branch_name,
                COUNT(assignments.assignment_id)
                    AS filled_headcount
             FROM organization_positions positions
             INNER JOIN hr_departments departments
               ON departments.company_id =
                    positions.company_id
              AND departments.department_id =
                    positions.department_id
              AND departments.deleted_at IS NULL
             INNER JOIN organization_job_titles
                job_titles
               ON job_titles.company_id =
                    positions.company_id
              AND job_titles.job_title_id =
                    positions.job_title_id
              AND job_titles.deleted_at IS NULL
             LEFT JOIN organization_branches branches
               ON branches.company_id =
                    positions.company_id
              AND branches.branch_id =
                    positions.branch_id
              AND branches.deleted_at IS NULL
             LEFT JOIN hr_employee_position_assignments
                assignments
               ON assignments.company_id =
                    positions.company_id
              AND assignments.position_id =
                    positions.position_id
              AND assignments.current_marker = 1
             WHERE positions.company_id = :company_id
               AND positions.status = \'open\'
               AND positions.deleted_at IS NULL
             GROUP BY
                positions.position_id,
                positions.code,
                positions.name,
                positions.approved_headcount,
                departments.name,
                job_titles.name,
                branches.name
             ORDER BY
                departments.name,
                positions.name
             LIMIT 500'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $positions = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($positions) ? $positions : [];
    }

    public function position(
        int $companyId,
        int $positionId,
        bool $lock = false
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                positions.position_id,
                positions.code,
                positions.name,
                positions.department_id,
                positions.approved_headcount,
                positions.status,
                departments.name AS department_name,
                job_titles.name AS job_title_name,
                branches.name AS branch_name
             FROM organization_positions positions
             INNER JOIN hr_departments departments
               ON departments.company_id =
                    positions.company_id
              AND departments.department_id =
                    positions.department_id
              AND departments.deleted_at IS NULL
             INNER JOIN organization_job_titles
                job_titles
               ON job_titles.company_id =
                    positions.company_id
              AND job_titles.job_title_id =
                    positions.job_title_id
              AND job_titles.deleted_at IS NULL
             LEFT JOIN organization_branches branches
               ON branches.company_id =
                    positions.company_id
              AND branches.branch_id =
                    positions.branch_id
              AND branches.deleted_at IS NULL
             WHERE positions.company_id = :company_id
               AND positions.position_id = :position_id
               AND positions.deleted_at IS NULL'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            'company_id' => $companyId,
            'position_id' => $positionId,
        ]);
        $position = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($position)
            ? $position
            : null;
    }

    public function currentPositionCount(
        int $companyId,
        int $positionId
    ): int {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM hr_employee_position_assignments
             WHERE company_id = :company_id
               AND position_id = :position_id
               AND current_marker = 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'position_id' => $positionId,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function endAssignment(
        int $companyId,
        int $assignmentId,
        string $effectiveTo,
        int $updatedBy
    ): void {
        $statement = $this->connection()->prepare(
            'UPDATE hr_employee_position_assignments
             SET effective_to = :effective_to,
                 assignment_status = \'ended\',
                 current_marker = NULL,
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND assignment_id = :assignment_id
               AND current_marker = 1'
        );
        $statement->execute([
            'effective_to' => $effectiveTo,
            'updated_by' => $updatedBy,
            'company_id' => $companyId,
            'assignment_id' => $assignmentId,
        ]);
    }

    public function create(
        int $companyId,
        int $employeeId,
        int $positionId,
        array $values,
        int $createdBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO hr_employee_position_assignments
                (
                    company_id,
                    employee_id,
                    position_id,
                    effective_from,
                    assignment_status,
                    current_marker,
                    position_code_snapshot,
                    position_name_snapshot,
                    department_name_snapshot,
                    job_title_name_snapshot,
                    branch_name_snapshot,
                    notes,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :employee_id,
                    :position_id,
                    :effective_from,
                    \'current\',
                    1,
                    :position_code_snapshot,
                    :position_name_snapshot,
                    :department_name_snapshot,
                    :job_title_name_snapshot,
                    :branch_name_snapshot,
                    :notes,
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'position_id' => $positionId,
            'effective_from' =>
                $values['effective_from'],
            'position_code_snapshot' =>
                $values['position_code_snapshot'],
            'position_name_snapshot' =>
                $values['position_name_snapshot'],
            'department_name_snapshot' =>
                $values['department_name_snapshot'],
            'job_title_name_snapshot' =>
                $values['job_title_name_snapshot'],
            'branch_name_snapshot' =>
                $values['branch_name_snapshot'],
            'notes' => $values['notes'],
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);

        return (int) $this->connection()
            ->lastInsertId();
    }

    public function synchronizeEmployeeOrganization(
        int $companyId,
        int $employeeId,
        int $departmentId,
        string $jobTitle,
        int $updatedBy
    ): void {
        $statement = $this->connection()->prepare(
            'UPDATE hr_employees
             SET department_id = :department_id,
                 job_title = :job_title,
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'department_id' => $departmentId,
            'job_title' => $jobTitle,
            'updated_by' => $updatedBy,
            'company_id' => $companyId,
            'employee_id' => $employeeId,
        ]);
    }
}
