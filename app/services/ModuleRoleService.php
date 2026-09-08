<?php

declare(strict_types=1);

namespace App\Services;

/** Explicit module owners; permission namespaces never create entitlement. */
final class ModuleRoleService
{
    public const OWNERS = [
        'sales' => ['sales_user','sales_manager','sales_officer','sales_approver','sales_cashier','sales_inventory_controller','sales_commission_officer','sales_credit_controller'],
        'inventory' => ['warehouse_inventory_user','warehouse_sales_employee'],
        'procurement' => ['procurement_requester','procurement_approver','purchasing_officer'],
        'finance' => ['finance_officer','finance_approver','executive_viewer','auditor'],
        'hr' => ['hr_administrator','employee_self_service'],
        'attendance' => ['hr_administrator','employee_self_service'],
        'it' => ['it_administrator','executive_viewer','auditor'],
        'business' => ['business_development_officer','executive_viewer','auditor'],
        'assets' => ['fixed_asset_viewer','fixed_asset_officer','fixed_asset_manager'],
    ];

    public static function roleOwns(string $role, string $module): bool
    {
        return in_array($role, ['company_owner','system_administrator'], true)
            || in_array($role, self::OWNERS[$module] ?? [], true);
    }

    public static function rolesOwn(array $roles, string $module): bool
    {
        foreach ($roles as $role) if (self::roleOwns((string) $role, $module)) return true;
        return false;
    }

    public function assignedRoles(int $company, int $user): array
    {
        $s = \db()->prepare('SELECT r.code FROM company_users cu JOIN users u ON u.user_id=cu.user_id AND u.active=TRUE AND u.deleted_at IS NULL JOIN company_user_roles ur ON ur.company_id=cu.company_id AND ur.user_id=cu.user_id JOIN roles r ON r.role_id=ur.role_id AND r.active=TRUE WHERE cu.company_id=? AND cu.user_id=? AND cu.active=TRUE');
        $s->execute([$company, $user]);
        return $s->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function entitled(int $company, int $user, string $module): bool
    {
        return self::rolesOwn($this->assignedRoles($company, $user), $module);
    }

    public function permissionAllowed(int $company, int $user, string $permission): bool
    {
        $module = explode('.', $permission, 2)[0];
        if (isset(self::OWNERS[$module]) && !in_array($module, array_column((new \App\Models\CompanyModule())->enabledForCompany($company), 'code'), true)) return false;
        $s = \db()->prepare('SELECT r.code FROM company_users cu JOIN users u ON u.user_id=cu.user_id AND u.active=TRUE AND u.deleted_at IS NULL JOIN company_user_roles ur ON ur.company_id=cu.company_id AND ur.user_id=cu.user_id JOIN roles r ON r.role_id=ur.role_id AND r.active=TRUE JOIN company_role_permissions rp ON rp.company_id=ur.company_id AND rp.role_id=ur.role_id JOIN permissions p ON p.permission_id=rp.permission_id AND p.active=TRUE WHERE cu.company_id=? AND cu.user_id=? AND cu.active=TRUE AND p.code=?');
        $s->execute([$company, $user, $permission]);
        foreach ($s->fetchAll(\PDO::FETCH_COLUMN) as $role) if (self::grantAllowed((string) $role, $permission)) return true;
        return false;
    }

    /** Technical cross-grants remain stored, but cannot unlock direct module actions. */
    public static function grantAllowed(string $role, string $permission): bool
    {
        $module = explode('.', $permission, 2)[0];
        return !isset(self::OWNERS[$module]) || self::roleOwns($role, $module);
    }
}
