<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\OrganizationReadinessRepository
    as OrganizationReadinessRepositoryContract;

final class OrganizationReadinessRepository
    extends OracleRepository
    implements OrganizationReadinessRepositoryContract
{
    public function snapshot(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                company.country_code,
                company.default_currency,
                company.timezone,
                branches.branches_total,
                branches.branches_active,
                branches.head_offices_active,
                branches.localized_branches,
                departments.departments_total,
                departments.departments_active,
                job_titles.job_titles_total,
                job_titles.job_titles_active,
                positions.positions_total,
                positions.positions_open,
                positions.positions_planned,
                positions.approved_headcount,
                workforce.active_employees,
                workforce.assigned_employees,
                workforce.linked_employees,
                workforce.managed_employees
             FROM (
                SELECT
                    country_code,
                    default_currency,
                    timezone
                FROM companies
                WHERE company_id =
                    :company_company_id
                  AND deleted_at IS NULL
             ) company
             CROSS JOIN (
                SELECT
                    COUNT(*) AS branches_total,
                    NVL(SUM(
                        CASE
                            WHEN active = 1 THEN 1
                            ELSE 0
                        END
                    ), 0) AS branches_active,
                    NVL(SUM(
                        CASE
                            WHEN active = 1
                             AND is_head_office = 1
                            THEN 1
                            ELSE 0
                        END
                    ), 0) AS head_offices_active,
                    NVL(SUM(
                        CASE
                            WHEN active = 1
                             AND LENGTH(
                                TRIM(country_code)
                             ) = 2
                             AND timezone IS NOT NULL
                            THEN 1
                            ELSE 0
                        END
                    ), 0) AS localized_branches
                FROM organization_branches
                WHERE company_id =
                    :branches_company_id
                  AND deleted_at IS NULL
             ) branches
             CROSS JOIN (
                SELECT
                    COUNT(*) AS departments_total,
                    NVL(SUM(
                        CASE
                            WHEN active = 1 THEN 1
                            ELSE 0
                        END
                    ), 0) AS departments_active
                FROM hr_departments
                WHERE company_id =
                    :departments_company_id
                  AND deleted_at IS NULL
             ) departments
             CROSS JOIN (
                SELECT
                    COUNT(*) AS job_titles_total,
                    NVL(SUM(
                        CASE
                            WHEN active = 1 THEN 1
                            ELSE 0
                        END
                    ), 0) AS job_titles_active
                FROM organization_job_titles
                WHERE company_id =
                    :job_titles_company_id
                  AND deleted_at IS NULL
             ) job_titles
             CROSS JOIN (
                SELECT
                    COUNT(*) AS positions_total,
                    NVL(SUM(
                        CASE
                            WHEN status = \'open\' THEN 1
                            ELSE 0
                        END
                    ), 0) AS positions_open,
                    NVL(SUM(
                        CASE
                            WHEN status = \'planned\' THEN 1
                            ELSE 0
                        END
                    ), 0) AS positions_planned,
                    NVL(SUM(
                        CASE
                            WHEN status IN (
                                \'open\',
                                \'planned\'
                            )
                            THEN approved_headcount
                            ELSE 0
                        END
                    ), 0) AS approved_headcount
                FROM organization_positions
                WHERE company_id =
                    :positions_company_id
                  AND deleted_at IS NULL
             ) positions
             CROSS JOIN (
                SELECT
                    COUNT(*) AS active_employees,
                    NVL(SUM(
                        CASE
                            WHEN assignments.assignment_id
                                IS NOT NULL
                            THEN 1
                            ELSE 0
                        END
                    ), 0) AS assigned_employees,
                    NVL(SUM(
                        CASE
                            WHEN memberships.user_id
                                IS NOT NULL
                            THEN 1
                            ELSE 0
                        END
                    ), 0) AS linked_employees,
                    NVL(SUM(
                        CASE
                            WHEN memberships.user_id
                                IS NOT NULL
                             AND memberships.manager_user_id
                                IS NOT NULL
                            THEN 1
                            ELSE 0
                        END
                    ), 0) AS managed_employees
                FROM hr_employees employees
                LEFT JOIN
                    hr_employee_position_assignments
                    assignments
                  ON assignments.company_id =
                        employees.company_id
                 AND assignments.employee_id =
                        employees.employee_id
                 AND assignments.current_marker = 1
                LEFT JOIN company_users memberships
                  ON memberships.company_id =
                        employees.company_id
                 AND memberships.user_id =
                        employees.user_id
                 AND memberships.active = 1
                WHERE employees.company_id =
                    :workforce_company_id
                  AND employees.employment_status
                        <> \'terminated\'
                  AND employees.deleted_at IS NULL
             ) workforce'
        );
        $statement->execute([
            'company_company_id' => $companyId,
            'branches_company_id' => $companyId,
            'departments_company_id' => $companyId,
            'job_titles_company_id' => $companyId,
            'positions_company_id' => $companyId,
            'workforce_company_id' => $companyId,
        ]);
        $snapshot = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($snapshot)
            ? $snapshot
            : [];
    }
}
