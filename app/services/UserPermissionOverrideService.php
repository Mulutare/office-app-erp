<?php

declare(strict_types=1);
namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompanyMembership;
use App\Models\Role;
use PDO;
use RuntimeException;
use Throwable;

final class UserPermissionOverrideService
{
    public function formData(int $userId): array
    {
        $company = (new TenantContext())->companyId();
        $query = \db()->prepare('SELECT u.user_id,u.display_name,u.username,u.is_platform_admin FROM company_users cu JOIN users u ON u.user_id=cu.user_id WHERE cu.company_id=? AND cu.user_id=? AND u.deleted_at IS NULL');
        $query->execute([$company,$userId]);
        $target = $query->fetch(PDO::FETCH_ASSOC);
        if (!$target || $target['is_platform_admin']) throw new RuntimeException('Select a user in this company.');
        $query = \db()->prepare('SELECT permission_id,allowed FROM company_user_permission_overrides WHERE company_id=? AND user_id=?');
        $query->execute([$company,$userId]);
        $overrides = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $overrides[(int)$row['permission_id']] = (bool)$row['allowed'] ? 'allow' : 'deny';
        $memberships = new CompanyMembership();
        $roles=\db()->prepare('SELECT r.name FROM company_user_roles ur JOIN roles r ON r.role_id=ur.role_id AND r.active=TRUE WHERE ur.company_id=? AND ur.user_id=? ORDER BY r.name');$roles->execute([$company,$userId]);
        return ['target'=>$target, 'company'=>(new \App\Models\CompanyModule())->companyById($company), 'roles'=>$roles->fetchAll(PDO::FETCH_COLUMN), 'permissions'=>(new Role())->activePermissions(false,$company),
            'defaults'=>$memberships->permissionCodes($userId,$company,false),
            'effective'=>$memberships->permissionCodes($userId,$company), 'overrides'=>$overrides,
            'version'=>$this->version($overrides)];
    }

    public function update(int $userId, array $submitted, string $version, int $actor): void
    {
        $company = (new TenantContext())->companyId();
        if ($userId === $actor) throw new RuntimeException('You cannot change your own access. Another access administrator must do this.');
        $connection = \db();
        $connection->beginTransaction();
        try {
            $lock = $connection->prepare('SELECT user_id FROM company_users WHERE company_id=? AND user_id=? FOR UPDATE');
            $lock->execute([$company,$userId]);
            if (!$lock->fetchColumn()) throw new RuntimeException('User not found in this company.');
            $actorPermissions = (new CompanyMembership())->permissionCodes($actor,$company);
            if (!(new ModuleRoleService())->permissionAllowed($company,$actor,'administration.roles.manage')) throw new RuntimeException('Access-control administration is required.');
            $form = $this->formData($userId);
            if (!hash_equals($form['version'],$version)) throw new RuntimeException('Access changed since this page was opened. Reload before saving.');
            $catalogue = array_column($form['permissions'],null,'permission_id');
            foreach ($submitted as $id=>$choice) {
                if (!isset($catalogue[$id]) || !in_array($choice,['inherit','allow','deny'],true)) throw new RuntimeException('An invalid access choice was submitted.');
            }
            $delete = $connection->prepare('DELETE FROM company_user_permission_overrides WHERE company_id=? AND user_id=? AND permission_id=?');
            $save = $connection->prepare('INSERT INTO company_user_permission_overrides(company_id,user_id,permission_id,allowed,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE allowed=VALUES(allowed),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
            $changes = [];
            foreach ($submitted as $id=>$choice) {
                $old = $form['overrides'][$id] ?? 'inherit';
                if ($old === $choice) continue;
                if ($choice === 'inherit') $delete->execute([$company,$userId,$id]);
                else $save->execute([$company,$userId,$id,$choice==='allow'?1:0,$actor]);
                $changes[] = ['permission'=>$catalogue[$id]['code'],'old'=>$old,'new'=>$choice];
            }
            $effective = (new CompanyMembership())->permissionCodes($userId,$company);
            $added = array_diff($effective,$form['effective']);
            if (array_diff($added,$actorPermissions) !== []) throw new RuntimeException('You cannot grant access that you do not hold.');
            // A dormant allow is also a grant: it must not become an escalation when the module is re-enabled.
            foreach ($changes as $change) {
                if ($change['new']==='allow' && !in_array($change['permission'],$actorPermissions,true)) throw new RuntimeException('You cannot grant access that you do not hold.');
                (new AuditLog())->record($actor,'USER_PERMISSION_OVERRIDE','administration','users',(string)$userId,
                    ['permission'=>$change['permission'],'override'=>$change['old'],'effective'=>in_array($change['permission'],$form['effective'],true)],
                    ['permission'=>$change['permission'],'override'=>$change['new'],'effective'=>in_array($change['permission'],$effective,true)],$company);
            }
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) $connection->rollBack();
            throw $error;
        }
    }

    private function version(array $overrides): string
    {
        ksort($overrides);
        return hash('sha256',json_encode($overrides,JSON_THROW_ON_ERROR));
    }
}
