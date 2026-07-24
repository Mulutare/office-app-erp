<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;

final class HomeController
{
    public function index(): void
    {
        $databaseName = \db()
            ->query('SELECT DATABASE()')
            ->fetchColumn();

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
        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            [
                'application' => \config('name'),
                'environment' => \config('environment'),
                'status' => 'healthy',
                'database' => \db()
                    ->query('SELECT DATABASE()')
                    ->fetchColumn(),
                'server_time' => date('Y-m-d H:i:s'),
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