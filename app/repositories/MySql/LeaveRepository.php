<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\LeaveRepository
    as LeaveRepositoryContract;

final class LeaveRepository extends MySqlRepository
    implements LeaveRepositoryContract
{
    public function leaveTypes(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                leave_type_id,
                code,
                name,
                annual_entitlement,
                requires_approval
             FROM hr_leave_types
             WHERE company_id = :company_id
               AND active = TRUE
               AND deleted_at IS NULL
             ORDER BY name'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $types = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($types) ? $types : [];
    }

    public function employeeOptions(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                employee_id,
                employee_number,
                first_name,
                last_name,
                preferred_name
             FROM hr_employees
             WHERE company_id = :company_id
               AND employment_status
                    IN (\'active\', \'on_leave\')
               AND deleted_at IS NULL
             ORDER BY last_name, first_name'
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

    public function leaveType(
        int $companyId,
        int $leaveTypeId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                leave_type_id,
                code,
                name,
                annual_entitlement,
                requires_approval
             FROM hr_leave_types
             WHERE company_id = :company_id
               AND leave_type_id = :leave_type_id
               AND active = TRUE
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'leave_type_id' => $leaveTypeId,
        ]);
        $type = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($type) ? $type : null;
    }

    public function employeeExists(
        int $companyId,
        int $employeeId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
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

        return $statement->fetchColumn() !== false;
    }

    public function overlaps(
        int $companyId,
        int $employeeId,
        string $startDate,
        string $endDate
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM hr_leave_requests
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND request_status
                    IN (\'pending\', \'approved\')
               AND start_date <= :end_date
               AND end_date >= :start_date
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function requests(
        int $companyId,
        string $status = ''
    ): array {
        $sql = 'SELECT
                    requests.leave_request_id,
                    requests.employee_id,
                    requests.leave_type_id,
                    requests.start_date,
                    requests.end_date,
                    requests.requested_days,
                    requests.reason,
                    requests.request_status,
                    requests.decision_note,
                    requests.decided_at,
                    requests.created_at,
                    types.code AS leave_type_code,
                    types.name AS leave_type_name,
                    types.annual_entitlement,
                    employees.employee_number,
                    employees.first_name,
                    employees.last_name,
                    employees.preferred_name,
                    departments.name
                        AS department_name,
                    decider.display_name
                        AS decided_by_name
                FROM hr_leave_requests requests
                INNER JOIN hr_leave_types types
                  ON types.company_id =
                        requests.company_id
                 AND types.leave_type_id =
                        requests.leave_type_id
                INNER JOIN hr_employees employees
                  ON employees.company_id =
                        requests.company_id
                 AND employees.employee_id =
                        requests.employee_id
                LEFT JOIN hr_departments departments
                  ON departments.company_id =
                        employees.company_id
                 AND departments.department_id =
                        employees.department_id
                LEFT JOIN users decider
                  ON decider.user_id =
                        requests.decided_by
                WHERE requests.company_id =
                        :company_id';
        $parameters = [
            'company_id' => $companyId,
        ];

        if ($status !== '') {
            $sql .= '
                  AND requests.request_status =
                        :request_status';
            $parameters['request_status'] = $status;
        }

        $sql .= '
                ORDER BY
                    CASE requests.request_status
                        WHEN \'pending\' THEN 0
                        ELSE 1
                    END,
                    requests.start_date DESC,
                    requests.leave_request_id DESC
                LIMIT 100';
        $statement = $this->connection()->prepare(
            $sql
        );
        $statement->execute($parameters);
        $requests = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($requests)
            ? $requests
            : [];
    }

    public function findRequest(
        int $companyId,
        int $leaveRequestId,
        bool $lock = false
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                leave_request_id,
                company_id,
                employee_id,
                leave_type_id,
                start_date,
                end_date,
                requested_days,
                reason,
                request_status,
                decision_note,
                decided_by,
                decided_at
             FROM hr_leave_requests
             WHERE company_id = :company_id
               AND leave_request_id =
                    :leave_request_id'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            'company_id' => $companyId,
            'leave_request_id' => $leaveRequestId,
        ]);
        $request = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($request)
            ? $request
            : null;
    }

    public function createRequest(
        int $companyId,
        array $values,
        int $createdBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO hr_leave_requests
                (
                    company_id,
                    employee_id,
                    leave_type_id,
                    start_date,
                    end_date,
                    requested_days,
                    reason,
                    request_status,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :employee_id,
                    :leave_type_id,
                    :start_date,
                    :end_date,
                    :requested_days,
                    :reason,
                    \'pending\',
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $values['employee_id'],
            'leave_type_id' =>
                $values['leave_type_id'],
            'start_date' => $values['start_date'],
            'end_date' => $values['end_date'],
            'requested_days' =>
                $values['requested_days'],
            'reason' => $values['reason'],
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);

        return (int) $this->connection()
            ->lastInsertId();
    }

    public function decide(
        int $companyId,
        int $leaveRequestId,
        string $status,
        ?string $decisionNote,
        int $decidedBy
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE hr_leave_requests
             SET request_status = :request_status,
                 decision_note = :decision_note,
                 decided_by = :decided_by,
                 decided_at = NOW(),
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND leave_request_id =
                    :leave_request_id
               AND request_status = \'pending\''
        );
        $statement->execute([
            'request_status' => $status,
            'decision_note' => $decisionNote,
            'decided_by' => $decidedBy,
            'updated_by' => $decidedBy,
            'company_id' => $companyId,
            'leave_request_id' => $leaveRequestId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function provisionDefaultTypes(
        int $companyId,
        int $createdBy
    ): void {
        $statement = $this->connection()->prepare(
            'INSERT INTO hr_leave_types
                (
                    company_id,
                    code,
                    name,
                    annual_entitlement,
                    requires_approval,
                    active,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :code,
                    :name,
                    :annual_entitlement,
                    TRUE,
                    TRUE,
                    :created_by,
                    :updated_by
                )
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                annual_entitlement =
                    VALUES(annual_entitlement),
                requires_approval = TRUE,
                active = TRUE,
                deleted_at = NULL'
        );

        foreach ($this->defaults() as $type) {
            $statement->execute([
                'company_id' => $companyId,
                'code' => $type['code'],
                'name' => $type['name'],
                'annual_entitlement' =>
                    $type['annual_entitlement'],
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);
        }
    }

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     annual_entitlement: string
     * }>
     */
    private function defaults(): array
    {
        return [
            [
                'code' => 'ANNUAL',
                'name' => 'Annual Leave',
                'annual_entitlement' => '21.00',
            ],
            [
                'code' => 'SICK',
                'name' => 'Sick Leave',
                'annual_entitlement' => '14.00',
            ],
            [
                'code' => 'COMPASSIONATE',
                'name' => 'Compassionate Leave',
                'annual_entitlement' => '5.00',
            ],
            [
                'code' => 'UNPAID',
                'name' => 'Unpaid Leave',
                'annual_entitlement' => '0.00',
            ],
        ];
    }
}
