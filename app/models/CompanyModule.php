<?php

declare(strict_types=1);

namespace App\Models;

final class CompanyModule
{
    /**
     * @return array<string, mixed>|null
     */
    public function companyByCode(string $code): ?array
    {
        $statement = \db()->prepare(
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
     * @return list<array<string, mixed>>
     */
    public function catalogForCompany(
        int $companyId
    ): array {
        $statement = \db()->prepare(
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
                modules.available,
                modules.active AS module_active,
                entitlements.enabled,
                entitlements.license_status,
                entitlements.licensed_at,
                entitlements.expires_at,
                entitlements.updated_at
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
        $statement = \db()->prepare(
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
               AND companies.deleted_at IS NULL
               AND modules.active = TRUE
               AND modules.available = TRUE
               AND entitlements.enabled = TRUE
               AND entitlements.license_status IN (
                    \'active\',
                    \'trial\'
               )
               AND (
                    entitlements.expires_at IS NULL
                    OR entitlements.expires_at > NOW()
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
        $statement = \db()->prepare(
            'SELECT
                modules.module_id,
                modules.code,
                entitlements.enabled
             FROM company_modules entitlements
             INNER JOIN erp_modules modules
                 ON modules.module_id =
                    entitlements.module_id
             WHERE entitlements.company_id =
                    :company_id
               AND modules.active = TRUE
               AND modules.available = TRUE
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
        $statement = \db()->prepare(
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
