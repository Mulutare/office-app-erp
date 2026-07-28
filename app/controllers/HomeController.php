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
            \redirect('/dashboard');
        }

        \redirect('/login');
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
