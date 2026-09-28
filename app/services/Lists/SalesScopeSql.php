<?php
declare(strict_types=1);
namespace App\Services\Lists;

use App\Repositories\RepositoryFactory;
use App\Services\SalesHierarchyScope;

/** SQL translation of the existing SalesHierarchyScope; it grants no new authority. */
final class SalesScopeSql
{
    public static function row(int $company, int $actor, string $alias): string
    {
        $scope = new SalesHierarchyScope();
        if (!RepositoryFactory::managerTeams()->reportingContext($company,$actor)
            || !$scope->hasPermission($company,$actor,'sales.view')) return '1=0';
        if ($scope->hasCompanyWideAccess($company,$actor)) return '1=1';
        $ids = self::ids($scope->userIds($company,$actor));
        return "($alias.created_by IN ($ids) OR EXISTS (SELECT 1 FROM sales_agents scope_agent
            INNER JOIN hr_employees scope_employee ON scope_employee.company_id=scope_agent.company_id
                AND scope_employee.employee_id=scope_agent.employee_id AND scope_employee.deleted_at IS NULL
            WHERE scope_agent.company_id=$alias.company_id AND scope_agent.agent_id=$alias.agent_id
                AND scope_employee.user_id IN ($ids)))";
    }

    public static function member(int $company,int $actor,string $employeeAlias): string
    {
        $scope = new SalesHierarchyScope();
        if (!$scope->hasPermission($company,$actor,'sales.view')) return '1=0';
        if ($scope->hasCompanyWideAccess($company,$actor)) return '1=1';
        return "$employeeAlias.deleted_at IS NULL AND $employeeAlias.user_id IN (".self::ids($scope->userIds($company,$actor)).')';
    }

    public static function ids(array $ids): string
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0)));
        return $ids===[] ? '0' : implode(',',$ids);
    }
}
