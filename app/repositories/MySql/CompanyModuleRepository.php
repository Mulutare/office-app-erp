<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

class CompanyModuleRepository extends MySqlRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function companyByCode(string $code): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                company_id,
                code,
                name,
                legal_name,
                default_currency,
                timezone,
                active
             FROM companies
             WHERE code = :code
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['code' => $code]);
        $company = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($company)
            ? $company
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function companyById(int $companyId): ?array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                company_id,
                code,
                name,
                legal_name,
                default_currency,
                timezone,
                brand_primary_color,
                subscription_status,
                subscription_expires_at,
                active
             FROM companies
             WHERE company_id = :company_id
               AND deleted_at IS NULL
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

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                modules.module_id,
                modules.code,
                modules.name,
                modules.navigation_label,
                modules.description,
                modules.route_path,
                modules.permission_namespace,
                modules.icon_text,
                modules.sort_order,
                modules.release_status,
                modules.first_release_version,
                modules.introduced_migration,
                modules.available,
                modules.active AS module_active,
                entitlements.enabled,
                entitlements.license_status,
                entitlements.licensed_at,
                entitlements.expires_at,
                entitlements.updated_at,
                (
                    SELECT GROUP_CONCAT(required_module.code ORDER BY required_module.code SEPARATOR \',\')
                    FROM erp_module_dependencies dependency
                    INNER JOIN erp_modules required_module
                       ON required_module.module_id=dependency.required_module_id
                    WHERE dependency.module_id=modules.module_id
                      AND dependency.dependency_type=\'required\'
                ) AS required_dependency_codes,
                NOT EXISTS (
                    SELECT 1
                    FROM erp_module_dependencies dependency
                    INNER JOIN erp_modules required_module
                       ON required_module.module_id=dependency.required_module_id
                    LEFT JOIN company_modules required_entitlement
                       ON required_entitlement.company_id=:dependency_company_id
                      AND required_entitlement.module_id=dependency.required_module_id
                    WHERE dependency.module_id=modules.module_id
                      AND dependency.dependency_type=\'required\'
                      AND (
                          required_module.release_status<>\'released\'
                          OR required_module.active<>TRUE
                          OR required_entitlement.module_id IS NULL
                          OR required_entitlement.license_status NOT IN(\'active\',\'trial\')
                          OR required_entitlement.enabled<>TRUE
                          OR (required_entitlement.expires_at IS NOT NULL AND required_entitlement.expires_at<=NOW())
                      )
                ) AS dependencies_satisfied
             FROM erp_modules modules
             LEFT JOIN company_modules entitlements
                 ON entitlements.module_id =
                    modules.module_id
                AND entitlements.company_id =
                    :company_id
             WHERE modules.active = TRUE
             ORDER BY
                modules.sort_order,
                modules.name'
        );
        $statement->execute([
            'company_id' => $companyId,
            'dependency_company_id' => $companyId,
        ]);
        $modules = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($modules)
            ? $modules
            : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                modules.module_id,
                modules.code,
                modules.name,
                modules.navigation_label,
                modules.route_path,
                modules.permission_namespace,
                modules.icon_text,
                modules.sort_order
             FROM company_modules entitlements
             INNER JOIN companies
                 ON companies.company_id =
                    entitlements.company_id
             INNER JOIN erp_modules modules
                 ON modules.module_id =
                    entitlements.module_id
             WHERE entitlements.company_id =
                    :company_id
               AND companies.active = TRUE
               AND companies.approval_status =
                    \'approved\'
               AND companies.deleted_at IS NULL
               AND companies.subscription_status
                    IN (\'active\', \'trial\')
               AND (
                    companies.subscription_expires_at
                        IS NULL
                    OR companies.subscription_expires_at
                        > NOW()
               )
               AND modules.active = TRUE
               AND modules.release_status = \'released\'
               AND entitlements.enabled = TRUE
               AND entitlements.license_status IN (
                    \'active\',
                    \'trial\'
               )
               AND (
                    entitlements.expires_at IS NULL
                    OR entitlements.expires_at > NOW()
               )
               AND NOT EXISTS (
                    SELECT 1
                    FROM erp_module_dependencies dependencies
                    INNER JOIN erp_modules required_module
                       ON required_module.module_id=dependencies.required_module_id
                    LEFT JOIN company_modules required_entitlement
                       ON required_entitlement.company_id=entitlements.company_id
                      AND required_entitlement.module_id=dependencies.required_module_id
                    WHERE dependencies.module_id=modules.module_id
                      AND dependencies.dependency_type=\'required\'
                      AND (
                          required_module.release_status<>\'released\'
                          OR required_module.active<>TRUE
                          OR required_entitlement.module_id IS NULL
                          OR required_entitlement.license_status NOT IN(\'active\',\'trial\')
                          OR required_entitlement.enabled<>TRUE
                          OR (required_entitlement.expires_at IS NOT NULL AND required_entitlement.expires_at<=NOW())
                      )
               )
             ORDER BY
                modules.sort_order,
                modules.name'
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
     * @return list<array<string, mixed>>
     */
    public function toggleableForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                modules.module_id,
                modules.code,
                entitlements.enabled
             FROM company_modules entitlements
             INNER JOIN companies
                 ON companies.company_id =
                    entitlements.company_id
             INNER JOIN erp_modules modules
                 ON modules.module_id =
                    entitlements.module_id
             WHERE entitlements.company_id =
                    :company_id
               AND companies.active = TRUE
               AND companies.approval_status =
                    \'approved\'
               AND companies.deleted_at IS NULL
               AND companies.subscription_status
                    IN (\'active\', \'trial\')
               AND (
                    companies.subscription_expires_at
                        IS NULL
                    OR companies.subscription_expires_at
                        > NOW()
               )
               AND modules.active = TRUE
               AND modules.release_status = \'released\'
               AND entitlements.license_status IN (
                    \'active\',
                    \'trial\'
               )
               AND (
                    entitlements.expires_at IS NULL
                    OR entitlements.expires_at > NOW()
               )
             ORDER BY modules.sort_order'
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

    public function setEnabled(
        int $companyId,
        int $moduleId,
        bool $enabled,
        int $updatedBy
    ): void {
        $statement = $this->connection()->prepare(
            'UPDATE company_modules
             SET enabled = :enabled,
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND module_id = :module_id'
        );
        $statement->execute([
            'enabled' => $enabled ? 1 : 0,
            'updated_by' => $updatedBy,
            'company_id' => $companyId,
            'module_id' => $moduleId,
        ]);
    }
}
