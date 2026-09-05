<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RepositoryFactory;

/** Company reporting relationships are independent of warehouse access. */
final class SalesHierarchyScope
{
    public function hasPermission(int $companyId, int $actorId, string $permission): bool
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*) FROM company_users cu
             INNER JOIN users u ON u.user_id=cu.user_id AND u.active=TRUE AND u.deleted_at IS NULL
             INNER JOIN company_user_roles ur ON ur.company_id=cu.company_id AND ur.user_id=cu.user_id
             INNER JOIN company_role_permissions rp ON rp.company_id=ur.company_id AND rp.role_id=ur.role_id
             INNER JOIN permissions p ON p.permission_id=rp.permission_id AND p.active=TRUE
             WHERE cu.company_id=? AND cu.user_id=? AND cu.active=TRUE AND p.code=?'
        );
        $statement->execute([$companyId, $actorId, $permission]);
        return (int) $statement->fetchColumn() > 0;
    }

    public function isAgent(int $companyId, int $actorId): bool
    {
        $context = RepositoryFactory::managerTeams()->reportingContext($companyId, $actorId);
        return in_array(strtolower(trim((string) ($context['job_title'] ?? ''))), ['dsa', 'dsp'], true);
    }

    /** @return list<int> */
    public function userIds(int $companyId, int $actorId): array
    {
        $statement = \db()->prepare(
            'SELECT cu.user_id,cu.manager_user_id FROM company_users cu
             INNER JOIN users u ON u.user_id=cu.user_id AND u.active=TRUE AND u.deleted_at IS NULL
             WHERE cu.company_id=? AND cu.active=TRUE'
        );
        $statement->execute([$companyId]);
        $parents = array_column($statement->fetchAll(\PDO::FETCH_ASSOC), 'manager_user_id', 'user_id');
        if (!array_key_exists($actorId, $parents)) return [];
        if ($this->isAgent($companyId, $actorId)) return [$actorId];
        $visible = [$actorId => true];
        $queue = [$actorId];
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($parents as $userId => $managerId) {
                if ((int) $managerId === $queue[$i] && !isset($visible[$userId])) {
                    $visible[$userId] = true;
                    $queue[] = (int) $userId;
                }
            }
        }
        return array_map('intval', array_keys($visible));
    }

    public function canManage(int $companyId, int $actorId): bool
    {
        return !$this->isAgent($companyId, $actorId)
            && $this->hasPermission($companyId, $actorId, 'sales.orders.confirm');
    }

    public function canReadOwner(int $companyId, int $actorId, int $ownerId): bool
    {
        return $this->hasPermission($companyId, $actorId, 'sales.view')
            && ($this->hasCompanyWideAccess($companyId, $actorId)
                || in_array($ownerId, $this->userIds($companyId, $actorId), true));
    }

    public function hasCompanyWideAccess(int $companyId, int $actorId): bool
    {
        if ($this->isAgent($companyId, $actorId)
            || !$this->hasPermission($companyId, $actorId, 'sales.view')) return false;
        $statement = \db()->prepare("SELECT COUNT(*) FROM company_user_roles ur
            INNER JOIN roles r ON r.role_id=ur.role_id
            WHERE ur.company_id=? AND ur.user_id=?
              AND r.code IN ('company_owner','system_administrator')");
        $statement->execute([$companyId, $actorId]);
        return (int) $statement->fetchColumn() > 0;
    }

    /** Apply reporting scope to operational Sales rows; preserve explicitly granted admin access. */
    public function canReadSalesRow(int $companyId, int $actorId, array $row): bool
    {
        $context = RepositoryFactory::managerTeams()->reportingContext($companyId, $actorId);
        if (!$context) return false;
        if (!$this->hasPermission($companyId, $actorId, 'sales.view')) return false;
        if (isset($row['company_id']) && (int) $row['company_id'] !== $companyId) return false;
        if ($this->hasCompanyWideAccess($companyId, $actorId)) return true;
        $ids = $this->userIds($companyId, $actorId);
        if (in_array((int) ($row['created_by'] ?? 0), $ids, true)) return true;
        $agent = (int) ($row['agent_id'] ?? 0);
        if ($agent < 1) return false;
        $statement = \db()->prepare('SELECT e.user_id FROM sales_agents a INNER JOIN hr_employees e
            ON e.company_id=a.company_id AND e.employee_id=a.employee_id AND e.deleted_at IS NULL
            WHERE a.company_id=? AND a.agent_id=?');
        $statement->execute([$companyId, $agent]);
        return in_array((int) $statement->fetchColumn(), $ids, true);
    }

    /** Validate the entire upward chain, including inactive/foreign links and cycles. */
    public function parentId(int $companyId, int $actorId): ?int
    {
        $statement = \db()->prepare('SELECT cu.user_id,cu.manager_user_id FROM company_users cu
            INNER JOIN users u ON u.user_id=cu.user_id AND u.active=TRUE AND u.deleted_at IS NULL
            WHERE cu.company_id=? AND cu.active=TRUE');
        $statement->execute([$companyId]);
        $parents = array_column($statement->fetchAll(\PDO::FETCH_ASSOC), 'manager_user_id', 'user_id');
        $seen = [];
        $current = $actorId;
        while ($current > 0) {
            if (isset($seen[$current]) || !array_key_exists($current, $parents)) {
                throw new \RuntimeException('The reporting hierarchy contains a cycle or an inactive/out-of-company manager.');
            }
            $seen[$current] = true;
            $current = (int) $parents[$current];
        }
        $parent = (int) ($parents[$actorId] ?? 0);
        return $parent > 0 ? $parent : null;
    }
}
