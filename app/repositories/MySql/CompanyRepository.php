<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

class CompanyRepository extends MySqlRepository
{
    public function administrationCount(
        string $search,
        string $status
    ): int {
        [$conditions, $parameters] =
            $this->administrationFilters(
                $search,
                $status
            );

        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM companies
             WHERE ' . implode(
                ' AND ',
                $conditions
            )
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function administrationPage(
        string $search,
        string $status,
        int $limit,
        int $offset
    ): array {
        [$conditions, $parameters] =
            $this->administrationFilters(
                $search,
                $status
            );

        $statement = $this->connection()->prepare(
            'SELECT
                companies.company_id,
                companies.code,
                companies.name,
                companies.legal_name,
                companies.contact_email,
                companies.country_code,
                companies.default_currency,
                companies.timezone,
                companies.subscription_status,
                companies.subscription_expires_at,
                companies.brand_primary_color,
                companies.approval_status,
                companies.approved_at,
                companies.active,
                companies.created_at,
                owner.display_name AS owner_name,
                owner.username AS owner_username,
                provisioner.display_name
                    AS provisioned_by_name,
                COUNT(company_modules.module_id)
                    AS catalog_module_count,
                SUM(
                    CASE
                        WHEN company_modules.enabled = TRUE
                         AND company_modules.license_status
                            IN (\'active\', \'trial\')
                         AND (
                            company_modules.expires_at IS NULL
                            OR company_modules.expires_at > NOW()
                         )
                            THEN 1
                        ELSE 0
                    END
                ) AS enabled_module_count
             FROM companies
             LEFT JOIN users provisioner
                 ON provisioner.user_id =
                    companies.provisioned_by
             LEFT JOIN users owner
                 ON owner.user_id =
                    companies.owner_user_id
             LEFT JOIN company_modules
                 ON company_modules.company_id =
                    companies.company_id
             WHERE ' . implode(
                ' AND ',
                $conditions
            ) . '
             GROUP BY
                companies.company_id,
                companies.code,
                companies.name,
                companies.legal_name,
                companies.contact_email,
                companies.country_code,
                companies.default_currency,
                companies.timezone,
                companies.subscription_status,
                companies.subscription_expires_at,
                companies.brand_primary_color,
                companies.approval_status,
                companies.approved_at,
                companies.active,
                companies.created_at,
                owner.display_name,
                owner.username,
                provisioner.display_name
             ORDER BY
                companies.created_at DESC,
                companies.name
             LIMIT :limit
             OFFSET :offset'
        );

        foreach ($parameters as $key => $value) {
            $statement->bindValue(
                ':' . $key,
                $value,
                \PDO::PARAM_STR
            );
        }

        $statement->bindValue(
            ':limit',
            $limit,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':offset',
            $offset,
            \PDO::PARAM_INT
        );
        $statement->execute();
        $companies = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($companies)
            ? $companies
            : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function provisioningModules(): array
    {
        $statement = $this->connection()->query(
            'SELECT
                module_id,
                code,
                name,
                description,
                icon_text,
                sort_order,
                available
             FROM erp_modules
             WHERE active = TRUE
             ORDER BY sort_order, name'
        );
        $modules = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($modules)
            ? $modules
            : [];
    }

    public function codeExists(string $code): bool
    {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM companies
             WHERE code = :code
             LIMIT 1'
        );
        $statement->execute(['code' => $code]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $company
     */
    public function create(
        array $company,
        int $provisionedBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO companies
                (
                    code,
                    name,
                    legal_name,
                    contact_email,
                    contact_phone,
                    country_code,
                    default_currency,
                    timezone,
                    subscription_status,
                    subscription_expires_at,
                    brand_primary_color,
                    approval_status,
                    approved_at,
                    approved_by,
                    owner_user_id,
                    active,
                    provisioned_by
                )
             VALUES
                (
                    :code,
                    :name,
                    :legal_name,
                    :contact_email,
                    :contact_phone,
                    :country_code,
                    :default_currency,
                    :timezone,
                    :subscription_status,
                    :subscription_expires_at,
                    :brand_primary_color,
                    \'pending\',
                    NULL,
                    NULL,
                    NULL,
                    FALSE,
                    :provisioned_by
                )'
        );
        $statement->execute([
            'code' => $company['code'],
            'name' => $company['name'],
            'legal_name' => $company['legal_name'],
            'contact_email' =>
                $company['contact_email'],
            'contact_phone' =>
                $company['contact_phone'],
            'country_code' =>
                $company['country_code'],
            'default_currency' =>
                $company['default_currency'],
            'timezone' => $company['timezone'],
            'subscription_status' =>
                $company['subscription_status'],
            'subscription_expires_at' =>
                $company['subscription_expires_at'],
            'brand_primary_color' =>
                $company['brand_primary_color'],
            'provisioned_by' => $provisionedBy,
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @param list<string> $selectedCodes
     */
    public function provisionModules(
        int $companyId,
        array $catalog,
        array $selectedCodes,
        string $licenseStatus,
        ?string $expiresAt,
        int $updatedBy
    ): void {
        $statement = $this->connection()->prepare(
            'INSERT INTO company_modules
                (
                    company_id,
                    module_id,
                    enabled,
                    license_status,
                    licensed_at,
                    expires_at,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :module_id,
                    :enabled,
                    :license_status,
                    :licensed_at,
                    :expires_at,
                    :updated_by
                )'
        );

        foreach ($catalog as $module) {
            $code = (string) (
                $module['code'] ?? ''
            );
            $licensed = !empty(
                $module['available']
            ) && in_array(
                $code,
                $selectedCodes,
                true
            );

            $statement->execute([
                'company_id' => $companyId,
                'module_id' =>
                    (int) $module['module_id'],
                'enabled' => $licensed ? 1 : 0,
                'license_status' => $licensed
                    ? $licenseStatus
                    : 'not_licensed',
                'licensed_at' => $licensed
                    ? date('Y-m-d H:i:s')
                    : null,
                'expires_at' => $licensed
                    ? $expiresAt
                    : null,
                'updated_by' => $updatedBy,
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForAdministration(
        int $companyId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                companies.company_id,
                companies.code,
                companies.name,
                companies.legal_name,
                companies.contact_email,
                companies.contact_phone,
                companies.country_code,
                companies.default_currency,
                companies.timezone,
                companies.subscription_status,
                companies.subscription_expires_at,
                companies.brand_primary_color,
                companies.approval_status,
                companies.approved_at,
                companies.active,
                companies.created_at,
                companies.updated_at,
                owner.user_id AS owner_user_id,
                owner.username AS owner_username,
                owner.email AS owner_email,
                owner.display_name AS owner_name,
                approver.display_name AS approved_by_name,
                provisioner.display_name
                    AS provisioned_by_name
             FROM companies
             LEFT JOIN users provisioner
                 ON provisioner.user_id =
                    companies.provisioned_by
             LEFT JOIN users owner
                 ON owner.user_id =
                    companies.owner_user_id
             LEFT JOIN users approver
                 ON approver.user_id =
                    companies.approved_by
             WHERE companies.company_id =
                    :company_id
               AND companies.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $company = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($company)
            ? $company
            : null;
    }

    public function assignOwner(
        int $companyId,
        int $ownerUserId
    ): void {
        $statement = $this->connection()->prepare(
            'UPDATE companies
             SET owner_user_id = :owner_user_id
             WHERE company_id = :company_id
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'owner_user_id' => $ownerUserId,
            'company_id' => $companyId,
        ]);
    }

    public function approve(
        int $companyId,
        int $approvedBy
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE companies
             SET approval_status = \'approved\',
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 active = TRUE
             WHERE company_id = :company_id
               AND approval_status = \'pending\'
               AND owner_user_id IS NOT NULL
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'approved_by' => $approvedBy,
            'company_id' => $companyId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function modulesForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                modules.code,
                modules.name,
                modules.description,
                modules.icon_text,
                modules.available,
                entitlements.enabled,
                entitlements.license_status,
                entitlements.licensed_at,
                entitlements.expires_at
             FROM erp_modules modules
             LEFT JOIN company_modules entitlements
                 ON entitlements.module_id =
                    modules.module_id
                AND entitlements.company_id =
                    :company_id
             WHERE modules.active = TRUE
             ORDER BY modules.sort_order, modules.name'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $modules = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($modules)
            ? $modules
            : [];
    }

    /**
     * @return array{
     *     0: list<string>,
     *     1: array<string, string>
     * }
     */
    private function administrationFilters(
        string $search,
        string $status
    ): array {
        $conditions = [
            'companies.deleted_at IS NULL',
        ];
        $parameters = [];

        if ($search !== '') {
            $conditions[] =
                '(companies.name LIKE :search_name
                  OR companies.legal_name
                    LIKE :search_legal_name
                  OR companies.code LIKE :search_code
                  OR companies.contact_email
                    LIKE :search_email)';
            $searchValue = '%' . $search . '%';
            $parameters['search_name'] =
                $searchValue;
            $parameters['search_legal_name'] =
                $searchValue;
            $parameters['search_code'] =
                $searchValue;
            $parameters['search_email'] =
                $searchValue;
        }

        if ($status === 'pending') {
            $conditions[] =
                'companies.approval_status = \'pending\'';
        } elseif ($status === 'active') {
            $conditions[] =
                'companies.approval_status = \'approved\'
                 AND companies.active = TRUE
                 AND companies.subscription_status = \'active\'
                 AND (
                    companies.subscription_expires_at IS NULL
                    OR companies.subscription_expires_at > NOW()
                 )';
        } elseif ($status === 'trial') {
            $conditions[] =
                'companies.active = TRUE
                 AND companies.subscription_status = \'trial\'
                 AND (
                    companies.subscription_expires_at IS NULL
                    OR companies.subscription_expires_at > NOW()
                 )';
        } elseif ($status === 'expired') {
            $conditions[] =
                'companies.subscription_expires_at IS NOT NULL
                 AND companies.subscription_expires_at <= NOW()';
        } elseif ($status === 'suspended') {
            $conditions[] =
                'companies.subscription_status = \'suspended\'';
        } elseif ($status === 'inactive') {
            $conditions[] =
                'companies.active = FALSE';
        }

        return [$conditions, $parameters];
    }
}
