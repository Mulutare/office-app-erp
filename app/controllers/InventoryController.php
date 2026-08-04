<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\InventoryService;

final class InventoryController
{
    private AuthorizationService $authorization;
    private InventoryService $inventory;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();

        $this->inventory =
            new InventoryService();
    }

    public function index(): void
    {
        $this->authorize('inventory.view');

        \view('layouts.app', [
            'applicationName' =>
                \config(
                    'name',
                    'OfficeApp ERP'
                ),
            'environment' =>
                \config(
                    'environment',
                    'unknown'
                ),
            'pageTitle' => 'Inventory',
            'pageDescription' =>
                'Stock, warehouses, receipts and inventory controls.',
            'contentView' => 'inventory.index',
            'user' => $_SESSION['auth'],
        ] + $this->inventory->workspace());
    }

    private function authorize(
        string $permission
    ): void {
        $this->authorization->requireModule(
            'inventory'
        );

        $this->authorization
            ->requireTenantPermission(
                $permission
            );
    }
}