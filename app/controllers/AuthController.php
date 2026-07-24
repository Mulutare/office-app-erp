<?php

declare(strict_types=1);

namespace App\Controllers;

final class AuthController
{
    public function showLogin(): void
    {
        \view('auth.login', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);
    }
}