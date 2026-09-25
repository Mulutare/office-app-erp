<?php

declare(strict_types=1);

require __DIR__ . '/../app/helpers/bootstrap.php';

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\PrivilegeEscalationProtectionService;
use App\Services\UserCreationService;
use App\Services\UserUpdateService;

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
});
if (getenv('DB_DATABASE') !== 'office_app_test') {
    throw new RuntimeException('Isolated database required');
}

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    ++$checks;
    echo 'PASS ' . $label . "\n";
};
$users = new User();
$memberships = new CompanyMembership();
$service = new UserUpdateService();
$guard = new PrivilegeEscalationProtectionService();
$createdUsers = $createdRoles = [];
$suffix = bin2hex(random_bytes(5));
$companyId = 2;
$sessionBefore = $_SESSION;
$makeRole = static function (string $name, array $permissions, bool $active = true) use (&$createdRoles, $suffix, $companyId): int {
    db()->prepare('INSERT INTO roles (name, code, active) VALUES (?, ?, ?)')
        ->execute([$name . $suffix, 'role_ux_' . $name . $suffix, (int) $active]);
    $id = (int) db()->lastInsertId();
    $createdRoles[] = $id;
    foreach ($permissions as $code) {
        db()->prepare('INSERT INTO company_role_permissions (company_id, role_id, permission_id) SELECT ?, ?, permission_id FROM permissions WHERE code = ?')
            ->execute([$companyId, $id, $code]);
    }
    return $id;
};
$makeUser = static function (string $name) use (&$createdUsers, $suffix, $users, $memberships, $companyId): int {
    $id = $users->createAdministrationUser('roleux_' . $name . $suffix, $name . $suffix . '@example.test', 'Role UX ' . $name, '!', true);
    $createdUsers[] = $id;
    $memberships->add($companyId, $id, $id, true, true, null);
    return $id;
};

try {
    $adminRole = $makeRole('admin', ['administration.module.enabled', 'administration.users.manage', 'administration.roles.manage']);
    $first = $makeRole('first', ['administration.users.manage']);
    $second = $makeRole('second', ['administration.roles.manage']);
    $forbidden = $makeRole('forbidden', ['finance.records.view']);
    $inactive = $makeRole('inactive', [], false);
    $selfService = $makeRole('selfservice', ['attendance.self.view']);
    $actor = $makeUser('actor');
    $target = $makeUser('target');
    $users->assignRoles($companyId, $actor, [$adminRole], $actor);
    $ownerRole = (int) db()->query("SELECT role_id FROM roles WHERE code = 'company_owner'")->fetchColumn();
    $check($ownerRole > 0 && $guard->roleAssignmentError([$ownerRole], $actor, $companyId) !== null, 'Company Owner fixture exceeds actor authority');
    $users->assignRoles($companyId, $target, [$ownerRole, $first, $inactive], $actor);
    $memberships->updateManager($companyId, $target, $actor);
    $_SESSION['auth'] = ['user_id' => $actor, 'company' => ['company_id' => $companyId], 'permissions' => $memberships->permissionCodes($actor, $companyId), 'is_platform_admin' => false];

    $form = $service->formData($target);
    $ids = static fn (array $roles): array => array_map('intval', array_column($roles, 'role_id'));
    $check(in_array($first, $ids($form['assignableRoles']), true) && in_array($second, $ids($form['assignableRoles']), true), 'Authorized roles are editable choices');
    $check(!in_array($forbidden, $ids($form['assignableRoles']), true) && !in_array($ownerRole, $ids($form['assignableRoles']), true), 'Unauthorized roles are hidden from available roles');
    $check(in_array($ownerRole, $ids($form['protectedAssignedRoles']), true) && in_array($inactive, $ids($form['protectedAssignedRoles']), true), 'Existing Owner and inactive roles are protected');
    $check(in_array($selfService, $ids($form['assignableRoles']), true), 'Existing self-service delegation policy is retained');
    $check($ids((new UserCreationService())->roles()) === $ids($form['assignableRoles']), 'Create and Edit share assignable filtering');
    ob_start();
    view('administration.users.edit', $form);
    $html = (string) ob_get_clean();
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $check($xpath->query('//input[@name="role_ids[]" and @value="' . $ownerRole . '"]')->length === 0
        && str_contains($html, 'Protected roles') && str_contains($html, 'Company Owner'), 'Protected role renders read-only without a hidden role input');
    $check($xpath->query('//input[@name="role_ids[]" and @value="' . $first . '" and @checked]')->length === 1
        && $xpath->query('//input[@name="role_ids[]" and @value="' . $forbidden . '"]')->length === 0, 'Edit renders authorized selections and hides unauthorized choices');
    $check(str_contains($html, "Edit this user's function access") && str_contains($html, 'id="user-function-access"')
        && str_contains($html, 'Only roles you are authorized to assign are shown.'), 'Function Access link and editor remain present with neutral guidance');
    ob_start();
    view('administration.users.create', ['roles' => (new UserCreationService())->roles()]);
    $createHtml = (string) ob_get_clean();
    @$dom->loadHTML($createHtml);
    $xpath = new DOMXPath($dom);
    $check($xpath->query('//input[@name="role_ids[]" and @value="' . $first . '"]')->length === 1
        && $xpath->query('//input[@name="role_ids[]" and @value="' . $forbidden . '"]')->length === 0, 'Create renders only assignable role checkboxes');
    $input = $form['profile'];
    $input['role_ids'] = [$second];
    $result = $service->update($target, $input, $actor);
    $check($result['successful'], 'Authorized role change succeeds');
    $saved = $users->roleIds($companyId, $target);
    $check(in_array($ownerRole, $saved, true) && in_array($inactive, $saved, true) && in_array($second, $saved, true) && !in_array($first, $saved, true), 'Omitted protected roles survive while editable roles change');
    $input['role_ids'] = [];
    $check($service->update($target, $input, $actor)['successful'], 'All editable roles can be removed when protected roles remain');
    $before = $users->roleIds($companyId, $target);
    foreach ([$forbidden, 99999999, (int) db()->query("SELECT role_id FROM roles WHERE code = 'system_administrator'")->fetchColumn()] as $badId) {
        $input['role_ids'] = [$badId];
        $check(!$service->update($target, $input, $actor)['successful'] && $users->roleIds($companyId, $target) === $before, 'Crafted unauthorized/invalid role rejected: ' . $badId);
    }
    $input['role_ids'] = [$ownerRole, $second];
    $check($service->update($target, $input, $actor)['successful'], 'Reposting an existing protected role retains it without granting authority');
    $create = $input;
    $create['username'] = 'roleux_new' . $suffix;
    $create['email'] = 'new' . $suffix . '@example.test';
    $create['role_ids'] = [$forbidden];
    $check(!(new UserCreationService())->create($create, $actor)['successful'], 'Create rejects unauthorized crafted role');
    $primaryOwner = (int) db()->query('SELECT owner_user_id FROM companies WHERE company_id = 2')->fetchColumn();
    if ($primaryOwner > 0) {
        $check(!$service->update($primaryOwner, $input, $actor)['successful'], 'Primary company owner protection remains enforced');
    }

    $self = $service->formData($actor);
    $check($self['assignableRoles'] === [] && in_array($adminRole, $ids($self['protectedAssignedRoles']), true), 'Self assignments are read-only');
    $selfInput = $self['profile'];
    $selfInput['manager_user_id'] = $target;
    $memberships->updateManager($companyId, $actor, $target);
    $selfInput['role_ids'] = [];
    $check($service->update($actor, $selfInput, $actor)['successful'] && $users->roleIds($companyId, $actor) === [$adminRole], 'Self protected roles survive omitted POST fields');
    $selfInput['role_ids'] = [$second];
    $check(!$service->update($actor, $selfInput, $actor)['successful'], 'Self cannot add a role');

    db()->prepare('UPDATE users SET is_platform_admin = TRUE WHERE user_id = ?')->execute([$target]);
    $check(!$service->update($target, $input, $actor)['successful'], 'Company actor cannot manage platform administrator');
    db()->prepare('UPDATE users SET is_platform_admin = TRUE WHERE user_id = ?')->execute([$actor]);
    $defaultCompany = (int) db()->query("SELECT company_id FROM companies WHERE code = 'default'")->fetchColumn();
    $systemRole = (int) db()->query("SELECT role_id FROM roles WHERE code = 'system_administrator'")->fetchColumn();
    $memberships->add($defaultCompany, $actor, $actor, false, true, null);
    $users->assignRoles($defaultCompany, $actor, [$systemRole], $actor);
    $platformForm = $service->formData($target);
    $check($platformForm['assignableRoles'] === [], 'Platform target roles remain read-only');
    $input['role_ids'] = [];
    $saved = $users->roleIds($companyId, $target);
    $check($service->update($target, $input, $actor)['successful'] && $users->roleIds($companyId, $target) === $saved, 'Active platform actor can edit profile while omitted roles are preserved');
    $input['role_ids'] = [$first];
    $check(!$service->update($target, $input, $actor)['successful'], 'Company editor cannot add roles to a platform target');

    $_SESSION['auth']['company']['company_id'] = 1;
    $check($service->formData($target) === null && !empty($service->update($target, $input, $actor)['notFound']), 'Foreign-company target is inaccessible');
} finally {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    foreach (array_reverse($createdUsers) as $id) {
        db()->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$id]);
    }
    foreach ($createdRoles as $id) {
        db()->prepare('DELETE FROM roles WHERE role_id = ?')->execute([$id]);
    }
    $_SESSION = $sessionBefore;
}
echo "$checks company user role checks passed\n";
