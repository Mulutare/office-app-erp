<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Services\AuthorizationService;
use App\Services\TenantContext;
use App\Services\UserPermissionOverrideService;
use PDO;
use RuntimeException;
final class AccessControlController
{
    public function index(): void
    {
        (new AuthorizationService())->requireTenantPermission('administration.roles.manage');
        $company = (new TenantContext())->companyId();
        $query = \db()->prepare('SELECT u.user_id,u.display_name,u.username FROM company_users cu JOIN users u ON u.user_id=cu.user_id WHERE cu.company_id=? AND u.deleted_at IS NULL AND u.is_platform_admin=FALSE ORDER BY u.display_name,u.user_id');
        $query->execute([$company]);
        $users = $query->fetchAll(PDO::FETCH_ASSOC);
        $userId = filter_input(INPUT_GET,'user_id',FILTER_VALIDATE_INT) ?: 0;
        try { $access = $userId ? (new UserPermissionOverrideService())->formData($userId) : null; }
        catch (RuntimeException $e) { http_response_code(404); \view('errors.404',['applicationName'=>\config('name')]); return; }
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'pageTitle'=>'Access Control',
            'pageDescription'=>'Choose a user to add or deny functions without changing their role.',
            'contentView'=>'administration.access-control','user'=>$_SESSION['auth'],'users'=>$users,'access'=>$access,
            'notice'=>\getFlash('user_access_notice'),'error'=>\getFlash('user_access_error')]);
    }

    public function save(): void
    {
        (new AuthorizationService())->requireTenantPermission('administration.roles.manage');
        $userId = filter_input(INPUT_POST,'user_id',FILTER_VALIDATE_INT) ?: 0;
        $return = \postString('return_to') === 'user_edit' ? '/administration/users/edit?id='.$userId : '/administration/access-control?user_id='.$userId;
        if (!\verifyCsrfToken(\postString('_token'))) { http_response_code(419); echo 'The form session expired. Reload and try again.'; return; }
        try {
            (new UserPermissionOverrideService())->update($userId,is_array($_POST['access']??null)?$_POST['access']:[],\postString('version'),(int)$_SESSION['auth']['user_id']);
            \flash('user_access_notice','User function access saved. Their role assignments are unchanged.');
        } catch (RuntimeException $e) { \flash('user_access_error',$e->getMessage()); }
        \redirect($return);
    }
}
