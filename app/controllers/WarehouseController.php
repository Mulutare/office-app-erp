<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\WarehouseManagementService;

final class WarehouseController
{
    private AuthorizationService $authorization;
    private WarehouseManagementService $warehouses;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->warehouses =
            new WarehouseManagementService();
    }

    public function index(): void
    {
        $this->authorize('inventory.warehouses.view');
        $listing = $this->warehouses->listing();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Warehouses',
            'pageDescription' =>
                'Manage tenant-scoped warehouses, operational locations and Inventory routes.',
            'contentView' =>
                'inventory.warehouses.index',
            'user' => $_SESSION['auth'],
            'warehouses' => $listing['warehouses'],
            'summary' => $listing['summary'],
            'canManage' => $this->canManage(),
            'notice' => \getFlash('warehouse_notice'),
        ]);
    }

    public function create(): void
    {
        $this->authorize('inventory.warehouses.manage');
        $old = \getFlash('warehouse_create_old');

        if (!is_array($old)) {
            $old = $this->warehouses->defaults();
        }

        $this->renderForm(
            $old,
            \getFlash('warehouse_create_errors', [])
        );
    }

    public function store(): void
    {
        $this->authorize('inventory.warehouses.manage');

        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('warehouse_create_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect('/inventory/warehouses/create');
        }

        $input = $this->warehouseInput();
        $result = $this->warehouses->create(
            $input,
            (int) ($_SESSION['auth']['user_id'] ?? 0)
        );

        if (!$result['successful']) {
            \flash(
                'warehouse_create_errors',
                $result['errors']
            );
            \flash('warehouse_create_old', $input);
            \redirect('/inventory/warehouses/create');
        }

        \flash('warehouse_notice', [
            'type' => 'success',
            'message' => sprintf(
                'Warehouse %s was created with six operational locations and RCPT, INT, DLV and ADJ routes.',
                (string) $result['warehouseName']
            ),
        ]);
        \redirect('/inventory/warehouses');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(
        array $old,
        array $errors
    ): void {
        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Create Warehouse',
            'pageDescription' =>
                'Create a warehouse, six operational locations and four Inventory operation routes atomically.',
            'contentView' =>
                'inventory.warehouses.form',
            'user' => $_SESSION['auth'],
            'old' => $old,
            'errors' => $errors,
        ] + $this->warehouses->formOptions());
    }

    private function authorize(string $permission): void
    {
        $this->authorization->requireModulePermission(
            'inventory',
            $permission
        );
    }

    private function canManage(): bool
    {
        return in_array(
            'inventory.warehouses.manage',
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    /** @return array<string, mixed> */
    private function warehouseInput(): array
    {
        return [
            'code' => \postString('code'),
            'name' => \postString('name'),
            'warehouse_type' =>
                \postString('warehouse_type'),
            'branch_id' => \postString('branch_id'),
            'manager_user_id' =>
                \postString('manager_user_id'),
            'address' => \postString('address'),
            'phone' => \postString('phone'),
            'email' => \postString('email'),
            'allow_negative_stock' =>
                isset($_POST['allow_negative_stock']),
            'is_default' => isset($_POST['is_default']),
            'active' => isset($_POST['active']),
        ];
    }
}
