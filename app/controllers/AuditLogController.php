<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogAdministrationService;
use App\Services\AuthorizationService;

final class AuditLogController
{
    private AuthorizationService $authorization;
    private AuditLogAdministrationService $auditLogs;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->auditLogs =
            new AuditLogAdministrationService();
    }

    public function index(): void
    {
        $this->authorization->requirePermission(
            'audit.logs.view'
        );

        $listing = $this->auditLogs->listing(
            $this->queryString('search'),
            $this->queryString('module'),
            $this->queryString('action'),
            $this->queryString('actor'),
            $this->queryString('date_from'),
            $this->queryString('date_to'),
            $this->queryInteger('page', 1)
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
            'pageTitle' => 'Audit Logs',
            'pageDescription' =>
                'Review security and administrative activity across the ERP.',
            'contentView' =>
                'administration.audit-logs.index',
            'user' => $_SESSION['auth'],
            'logs' => $listing['logs'],
            'options' => $listing['options'],
            'filters' => $listing['filters'],
            'pagination' =>
                $listing['pagination'],
        ]);
    }

    public function show(): void
    {
        $this->authorization->requirePermission(
            'audit.logs.view'
        );

        $log = $this->auditLogs->details(
            $this->queryInteger('id', 0)
        );

        if ($log === null) {
            $this->notFound();
        }

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Audit Event #'
                . (string) $log['audit_log_id'],
            'pageDescription' =>
                'Immutable event metadata and recorded value changes.',
            'contentView' =>
                'administration.audit-logs.show',
            'user' => $_SESSION['auth'],
            'log' => $log,
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

        \view('errors.audit-log-404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}
