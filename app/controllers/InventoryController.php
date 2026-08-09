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

    public function postTransfer(): void
    {
        $this->authorize('inventory.transfers.manage');

        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('inventory_notice', [
                'type' => 'error',
                'message' => 'The form session expired. Please try again.',
            ]);
            \redirect('/inventory');
        }

        $result = $this->inventory->postTransfer(
            (int) \postString('transfer_id'),
            (int) ($_SESSION['auth']['user_id'] ?? 0)
        );
        $successful = !empty($result['successful']);
        \flash('inventory_notice', [
            'type' => $successful ? 'success' : 'error',
            'message' => $successful
                ? 'The inventory transfer was posted.'
                : (string) ($result['errors']['form'] ?? 'The transfer could not be posted.'),
        ]);
        \redirect('/inventory');
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
