<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;

final class HomeController
{
    public function index(): void
    {
        $auth = new AuthService();

        if ($auth->check()) {
            \redirect(\App\Services\WorkspaceAccessService::firstLanding());
        }

        \redirect('/login');
    }

    public function account(): void
    {
        (new \App\Services\AuthorizationService())->requireAuthentication();
        \view('layouts.app', ['applicationName'=>\config('name','OfficeApp ERP'), 'pageTitle'=>'Your account',
            'pageDescription'=>'No workspaces are currently assigned. Contact your company administrator.',
            'contentView'=>'account.empty', 'user'=>$_SESSION['auth']]);
    }

    public function health(): void
    {
        \databaseDriver()->assertHealthy(
            \db()
        );

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            [
                'application' => \config('name'),
                'status' => 'healthy',
                'database' => 'connected',
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

}
