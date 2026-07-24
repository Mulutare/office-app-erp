<?php

declare(strict_types=1);

namespace App\Controllers;
use App\Services\UserCreationService;
use App\Services\AuthorizationService;
use App\Services\UserAdministrationService;

final class UserAdministrationController
{
    private UserCreationService $creation;
    private AuthorizationService $authorization;
    private UserAdministrationService $users;

    public function __construct()
    {
        $this->creation =
    new UserCreationService();
        $this->authorization =
            new AuthorizationService();

        $this->users =
            new UserAdministrationService();
    }

    public function index(): void
    {
        $this->authorization
            ->requirePermission(
                'administration.users.manage'
            );

        $listing = $this->users->listing(
            $this->queryString('search'),
            $this->queryString(
                'status',
                'all'
            ),
            $this->queryString(
                'sort',
                'created_at'
            ),
            $this->queryString(
                'direction',
                'desc'
            ),
            $this->queryInteger(
                'page',
                1
            )
        );

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'User Management',
            'pageDescription' =>
                'Manage ERP accounts, access roles and account security.',
            'contentView' =>
                'administration.users.index',
            'user' => $_SESSION['auth'],
            'users' => $listing['users'],
            'filters' => $listing['filters'],
            'pagination' =>
                $listing['pagination'],
                'createdCredentials' => \getFlash(
    'created_user_credentials'
),
        ]);
    }

    private function queryString(
        string $key,
        string $default = ''
    ): string {
        $value = $_GET[$key] ?? $default;

        return is_string($value)
            ? trim($value)
            : $default;
    }

    private function queryInteger(
        string $key,
        int $default
    ): int {
        $value = filter_input(
            INPUT_GET,
            $key,
            FILTER_VALIDATE_INT
        );

        return is_int($value)
            ? $value
            : $default;
    }
    public function create(): void
{
    $this->authorization
        ->requirePermission(
            'administration.users.manage'
        );

    $this->authorization
        ->requirePermission(
            'administration.roles.manage'
        );

    \view('layouts.app', [
        'applicationName' => \config(
            'name',
            'OfficeApp ERP'
        ),
        'environment' => \config(
            'environment',
            'unknown'
        ),
        'pageTitle' => 'Create User',
        'pageDescription' =>
            'Create a secure ERP account and assign access roles.',
        'contentView' =>
            'administration.users.create',
        'user' => $_SESSION['auth'],
        'roles' => $this->creation->roles(),
        'errors' => \getFlash(
            'user_create_errors',
            []
        ),
        'old' => \getFlash(
            'user_create_old',
            []
        ),
    ]);
}

public function store(): void
{
    $this->authorization
        ->requirePermission(
            'administration.users.manage'
        );

    $this->authorization
        ->requirePermission(
            'administration.roles.manage'
        );

    if (
        !\verifyCsrfToken(
            \postString('_token')
        )
    ) {
        \flash(
            'user_create_errors',
            [
                'form' =>
                    'The form session expired. Please try again.',
            ]
        );

        \redirect(
            '/administration/users/create'
        );
    }

    $input = [
        'username' =>
            \postString('username'),
        'email' =>
            \postString('email'),
        'display_name' =>
            \postString('display_name'),
        'active' =>
            isset($_POST['active']),
        'role_ids' =>
            $_POST['role_ids'] ?? [],
    ];

    $result = $this->creation->create(
        $input,
        (int) $_SESSION['auth']['user_id']
    );

    if (!$result['successful']) {
        \flash(
            'user_create_errors',
            $result['errors']
        );

        \flash(
            'user_create_old',
            $input
        );

        \redirect(
            '/administration/users/create'
        );
    }

    \flash(
        'created_user_credentials',
        [
            'username' => $input['username'],
            'temporary_password' =>
                $result['temporaryPassword'],
        ]
    );

    \redirect('/administration/users');
}
}