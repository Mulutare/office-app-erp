<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\LeaveRepository
    as LeaveRepositoryContract;

final class LeaveRepository extends OracleRepository
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
               AND active = 1
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
               AND active = 1
               AND deleted_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
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
             FETCH FIRST 1 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function employeeForUser(
        int $companyId,
        int $userId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                employees.employee_id,
                employees.employee_number,
                employees.first_name,
                employees.last_name,
                employees.preferred_name,
                employees.job_title,
                employees.employment_status,
                employees.manager_employee_id,
                memberships.manager_user_id,
                (
                    SELECT COUNT(*)
                    FROM company_users reports
                    INNER JOIN hr_employees report_employee
                      ON report_employee.company_id =
                            reports.company_id
                     AND report_employee.user_id =
                            reports.user_id
                     AND report_employee.deleted_at
                            IS NULL
                    WHERE reports.company_id =
                            memberships.company_id
                      AND reports.manager_user_id =
                            memberships.user_id
                      AND reports.active = 1
                ) AS direct_report_count
             FROM company_users memberships
             INNER JOIN hr_employees employees
               ON employees.company_id =
                    memberships.company_id
              AND employees.user_id =
                    memberships.user_id
             WHERE memberships.company_id =
                    :company_id
               AND memberships.user_id = :user_id
               AND memberships.active = 1
               AND employees.employment_status
                    IN (\'active\', \'on_leave\')
               AND employees.deleted_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);
        $employee = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($employee)
            ? $employee
            : null;
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
               AND start_date <= TO_DATE(
                    :end_date,
                    \'YYYY-MM-DD\'
               )
               AND end_date >= TO_DATE(
                    :start_date,
                    \'YYYY-MM-DD\'
               )
             FETCH FIRST 1 ROWS ONLY'
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
        return $this->scopedRequests(
            $companyId,
            $status,
            null,
            null
        );
    }

    public function requestsForEmployee(
        int $companyId,
        int $employeeId,
        string $status = ''
    ): array {
        return $this->scopedRequests(
            $companyId,
            $status,
            $employeeId,
            null
        );
    }

    public function requestsForManager(
        int $companyId,
        int $managerUserId,
        string $status = ''
    ): array {
        return $this->scopedRequests(
            $companyId,
            $status,
            null,
            $managerUserId
        );
    }

    public function balancesForEmployee(
        int $companyId,
        int $employeeId,
        string $yearStart,
        string $yearEnd
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                types.leave_type_id,
                types.code,
                types.name,
                types.annual_entitlement,
                NVL(SUM(
                    CASE
                        WHEN requests.request_status =
                                \'approved\'
                         AND requests.start_date >=
                                TO_DATE(
                                    :year_start,
                                    \'YYYY-MM-DD\'
                                )
                         AND requests.start_date <=
                                TO_DATE(
                                    :year_end,
                                    \'YYYY-MM-DD\'
                                )
                        THEN requests.requested_days
                        ELSE 0
                    END
                ), 0) AS used_days
             FROM hr_leave_types types
             LEFT JOIN hr_leave_requests requests
               ON requests.company_id =
                    types.company_id
              AND requests.leave_type_id =
                    types.leave_type_id
              AND requests.employee_id =
                    :employee_id
             WHERE types.company_id = :company_id
               AND types.active = 1
               AND types.deleted_at IS NULL
             GROUP BY
                types.leave_type_id,
                types.code,
                types.name,
                types.annual_entitlement
             ORDER BY types.name'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'year_start' => $yearStart,
            'year_end' => $yearEnd,
        ]);
        $balances = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($balances)
            ? $balances
            : [];
    }

    public function managerCanDecide(
        int $companyId,
        int $managerUserId,
        int $leaveRequestId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM hr_leave_requests requests
             INNER JOIN hr_employees employees
               ON employees.company_id =
                    requests.company_id
              AND employees.employee_id =
                    requests.employee_id
             INNER JOIN company_users memberships
               ON memberships.company_id =
                    employees.company_id
              AND memberships.user_id =
                    employees.user_id
             WHERE requests.company_id =
                    :company_id
               AND requests.leave_request_id =
                    :leave_request_id
               AND memberships.manager_user_id =
                    :manager_user_id
               AND memberships.active = 1
               AND employees.deleted_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'leave_request_id' => $leaveRequestId,
            'manager_user_id' => $managerUserId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scopedRequests(
        int $companyId,
        string $status,
        ?int $employeeId,
        ?int $managerUserId
    ): array {
        $sql = 'SELECT
                    requests.leave_request_id,
                    requests.employee_id,
                    requests.leave_type_id,
                    TO_CHAR(
                        requests.start_date,
                        \'YYYY-MM-DD\'
                    ) AS start_date,
                    TO_CHAR(
                        requests.end_date,
                        \'YYYY-MM-DD\'
                    ) AS end_date,
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
                LEFT JOIN company_users memberships
                  ON memberships.company_id =
                        employees.company_id
                 AND memberships.user_id =
                        employees.user_id
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

        if ($employeeId !== null) {
            $sql .= '
                  AND requests.employee_id =
                        :employee_id';
            $parameters['employee_id'] = $employeeId;
        }

        if ($managerUserId !== null) {
            $sql .= '
                  AND memberships.manager_user_id =
                        :manager_user_id
                  AND memberships.active = 1';
            $parameters['manager_user_id'] =
                $managerUserId;
        }

        $sql .= '
                ORDER BY
                    CASE requests.request_status
                        WHEN \'pending\' THEN 0
                        ELSE 1
                    END,
                    requests.start_date DESC,
                    requests.leave_request_id DESC
                FETCH FIRST 100 ROWS ONLY';
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
                TO_CHAR(
                    start_date,
                    \'YYYY-MM-DD\'
                ) AS start_date,
                TO_CHAR(
                    end_date,
                    \'YYYY-MM-DD\'
                ) AS end_date,
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
                    TO_DATE(
                        :start_date,
                        \'YYYY-MM-DD\'
                    ),
                    TO_DATE(
                        :end_date,
                        \'YYYY-MM-DD\'
                    ),
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

        $lookup = $this->connection()->prepare(
            'SELECT leave_request_id
             FROM hr_leave_requests
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND leave_type_id = :leave_type_id
               AND start_date = TO_DATE(
                    :start_date,
                    \'YYYY-MM-DD\'
               )
               AND end_date = TO_DATE(
                    :end_date,
                    \'YYYY-MM-DD\'
               )
               AND created_by = :created_by
             ORDER BY leave_request_id DESC
             FETCH FIRST 1 ROWS ONLY'
        );
        $lookup->execute([
            'company_id' => $companyId,
            'employee_id' => $values['employee_id'],
            'leave_type_id' =>
                $values['leave_type_id'],
            'start_date' => $values['start_date'],
            'end_date' => $values['end_date'],
            'created_by' => $createdBy,
        ]);

        return (int) $lookup->fetchColumn();
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
                 decided_at = SYSTIMESTAMP,
                 updated_by = :updated_by,
                 updated_at = SYSTIMESTAMP
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
            'MERGE INTO hr_leave_types target
             USING (
                SELECT
                    :company_id AS company_id,
                    :code AS code,
                    :name AS name,
                    :annual_entitlement
                        AS annual_entitlement,
                    :actor_id AS actor_id
                FROM dual
             ) source
             ON (
                target.company_id = source.company_id
                AND target.code = source.code
             )
             WHEN MATCHED THEN UPDATE SET
                target.name = source.name,
                target.annual_entitlement =
                    source.annual_entitlement,
                target.requires_approval = 1,
                target.active = 1,
                target.deleted_at = NULL,
                target.updated_by = source.actor_id,
                target.updated_at = SYSTIMESTAMP
             WHEN NOT MATCHED THEN INSERT (
                company_id,
                code,
                name,
                annual_entitlement,
                requires_approval,
                active,
                created_by,
                updated_by
             ) VALUES (
                source.company_id,
                source.code,
                source.name,
                source.annual_entitlement,
                1,
                1,
                source.actor_id,
                source.actor_id
             )'
        );

        foreach ($this->defaults() as $type) {
            $statement->execute([
                'company_id' => $companyId,
                'code' => $type['code'],
                'name' => $type['name'],
                'annual_entitlement' =>
                    $type['annual_entitlement'],
                'actor_id' => $createdBy,
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
