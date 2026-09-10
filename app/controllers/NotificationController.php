<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\TenantContext;
use App\Services\UserNotificationService;

final class NotificationController
{
    private function context(): array
    {
        (new AuthorizationService())->requireAuthentication();
        if (!\verifyCsrfToken(\postString('_token'))) {
            http_response_code(419);
            exit('The form session expired.');
        }
        return [(new TenantContext())->companyId(), (int) $_SESSION['auth']['user_id']];
    }

    public function read(string $id): void
    {
        [$company,$user] = $this->context();
        $path = (new UserNotificationService())->markRead($company,$user,(int)$id);
        if ($path === null) {
            http_response_code(404);
            \view('errors.404', ['applicationName'=>\config('name','OfficeApp ERP')]);
            return;
        }
        \redirect($path);
    }

    public function readAll(): void
    {
        [$company,$user] = $this->context();
        (new UserNotificationService())->markAllRead($company,$user);
        \redirect('/dashboard');
    }
}
