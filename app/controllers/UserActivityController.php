<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\UserActivityService;

final class UserActivityController
{
    private AuthorizationService $authorization;
    private UserActivityService $activity;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->activity =
            new UserActivityService();
    }

    public function index(): void
    {
        $this->authorization->requirePermission(
            'audit.logs.view'
        );

        $listing=$this->activity->smartListing($this->queryInteger('id',0),$_GET);

        if ($listing === null) {
            $this->notFound();
        }

        $profile = $listing['user'];

        if(isset($_GET['download']))\App\Services\Lists\ListDownload::send('user-activity',$listing['exportList'],(new \App\Services\Lists\AdministrationListService())->columns('user-activity'),$_GET['download']);
        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'User Activity',
            'pageDescription' =>
                'Authentication and administrative history for '
                . (string) (
                    $profile['display_name']
                    ?? 'this account'
                )
                . '.',
            'contentView' =>
                'administration.users.activity',
            'user' => $_SESSION['auth'],
            'listing'=>$listing,
            'profile' => $profile,
            'events' => $listing['events'],
            'filters' => $listing['filters'],
            'pagination' =>
                $listing['pagination'],
            'canManageUsers' => in_array(
                'administration.users.manage',
                $_SESSION['auth']['permissions'] ?? [],
                true
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
        $value = $_GET[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : $default;
    }

    private function notFound(): void
    {
        http_response_code(404);

        \view('errors.404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}
