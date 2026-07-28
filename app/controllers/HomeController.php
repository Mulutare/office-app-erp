<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;

final class HomeController
{
    public function index(): void
    {
        $driver = \databaseDriver();
        $databaseName = $driver->databaseName(
            \db()
        );

        \view('home.index', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'databaseName' => $databaseName,
            'serverTime' => date('Y-m-d H:i:s'),
        ]);
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

    public function userModelHealth(): void
    {
        $userModel = new User();

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            [
                'status' => 'working',
                'model' => User::class,
                'users_table_count' => $userModel->count(),
                'test_missing_user' =>
                    $userModel->findById(999999) === null,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }
}
