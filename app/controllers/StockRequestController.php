<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\StockRequestService;
use Throwable;

final class StockRequestController
{
    private AuthorizationService $authorization;
    private StockRequestService $service;

    public function __construct()
    {
        $this->authorization = new AuthorizationService();
        $this->service = new StockRequestService();
    }

    public function index(): void
    {
        $this->authorize('inventory.stock_requests.view');
        $this->render();
    }

    public function show(string $id): void
    {
        $this->authorize('inventory.stock_requests.view');
        $requestId = (int) $id;
        $workspace = $this->service->workspace($this->actor(), $requestId);
        if (!is_array($workspace['stockRequest'] ?? null)) {
            http_response_code(404);
            \view('errors.404', ['applicationName' => \config('name', 'OfficeApp ERP')]);
            return;
        }
        $this->render($requestId, $workspace);
    }

    public function create(): void
    {
        $this->mutate(
            'inventory.stock_requests.create',
            function (): string {
                $id = $this->service->createRequest($_POST, $this->actor());
                return '/inventory/stock-requests/' . $id;
            },
            '/inventory/stock-requests',
            'Stock request submitted to your direct Shop Manager.'
        );
    }

    public function process(string $id): void
    {
        $requestId = (int) $id;
        $this->mutate(
            'inventory.stock_requests.process',
            function () use ($requestId): string {
                $this->service->processRequest($requestId, $this->actor());
                return '/inventory/stock-requests/' . $requestId;
            },
            '/inventory/stock-requests/' . $requestId,
            'Stock checked, allocated and routed using the remaining quantity.'
        );
    }

    public function issue(string $id): void
    {
        $requestId = (int) $id;
        $this->mutate(
            'inventory.stock_requests.issue',
            function () use ($requestId): string {
                $this->service->issueRequest($requestId, $this->actor());
                return '/inventory/stock-requests/' . $requestId;
            },
            '/inventory/stock-requests/' . $requestId,
            'Stock issued to the requesting employee. Waiting for receipt confirmation.'
        );
    }

    public function receive(string $id): void
    {
        $requestId = (int) $id;
        $this->mutate(
            'inventory.stock_requests.receive',
            function () use ($requestId): string {
                $this->service->confirmReceipt($requestId, $this->actor());
                return '/inventory/stock-requests/' . $requestId;
            },
            '/inventory/stock-requests/' . $requestId,
            'Receipt confirmed. The stock request is closed.'
        );
    }

    public function saveAuthority(): void
    {
        $this->mutate(
            'inventory.stock_authorities.manage',
            function (): string {
                $this->service->saveAuthority($_POST, $this->actor());
                return '/inventory/stock-requests?section=authorities';
            },
            '/inventory/stock-requests?section=authorities',
            'Manager stock authority saved.'
        );
    }

    public function saveReorderThreshold(): void
    {
        $this->mutate(
            'inventory.reorder_thresholds.manage',
            function (): string {
                $this->service->saveReorderThreshold($_POST, $this->actor());
                return '/inventory/stock-requests?section=reorder';
            },
            '/inventory/stock-requests?section=reorder',
            'Low-stock notification quantity saved. No requisition was created automatically.'
        );
    }

    private function render(?int $requestId = null, ?array $workspace = null): void
    {
        $workspace ??= $this->service->workspace($this->actor(), $requestId);
        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => $requestId === null ? 'Stock Requests' : (string) (($workspace['stockRequest']['request_number'] ?? 'Stock Request')),
            'pageDescription' => 'Stock-aware DSA/DSP requests routed Shop → District → Regional, with company procurement only for the remaining Regional shortage.',
            'contentView' => 'inventory.stock-requests',
            'user' => $_SESSION['auth'],
            'permissions' => $_SESSION['auth']['permissions'] ?? [],
            'notice' => \getFlash('stock_request_notice'),
            'error' => \getFlash('stock_request_error'),
        ] + $workspace);
    }

    private function mutate(
        string $permission,
        callable $work,
        string $failureRedirect,
        string $successMessage
    ): void {
        $this->authorize($permission);
        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('stock_request_error', 'The form session expired.');
            \redirect($failureRedirect);
        }
        try {
            $redirect = $work();
            \flash('stock_request_notice', $successMessage);
            \redirect($redirect);
        } catch (Throwable $e) {
            \flash('stock_request_error', $e->getMessage());
            \redirect($failureRedirect);
        }
    }

    private function authorize(string $permission): void
    {
        $this->authorization->requireModulePermission('inventory', $permission);
    }

    private function actor(): int
    {
        return (int) ($_SESSION['auth']['user_id'] ?? 0);
    }
}
