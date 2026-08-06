<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\WarehouseLocationManagementService;

final class WarehouseLocationController
{
    private AuthorizationService $authorization;
    private WarehouseLocationManagementService $locations;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->locations =
            new WarehouseLocationManagementService();
    }

    public function index(): void
    {
        $this->authorize('inventory.warehouses.view');
        $listing = $this->locations->listing();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Warehouse Locations',
            'pageDescription' =>
                'Manage Odoo-style warehouse zones, receiving, stock, dispatch, returns and quarantine locations.',
            'contentView' =>
                'inventory.locations.index',
            'user' => $_SESSION['auth'],
            'locations' => $listing['locations'],
            'warehouses' => $listing['warehouses'],
            'summary' => $listing['summary'],
            'canManage' => $this->canManage(),
            'notice' => \getFlash('location_notice'),
            'errors' => \getFlash(
                'location_provision_errors',
                []
            ),
        ]);
    }

    public function create(): void
    {
        $this->authorize('inventory.warehouses.manage');
        $old = \getFlash('location_create_old');

        if (!is_array($old)) {
            $old = $this->locations->defaults();
        }

        $this->renderForm(
            $old,
            \getFlash('location_create_errors', [])
        );
    }

    public function store(): void
    {
        $this->authorize('inventory.warehouses.manage');

        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('location_create_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect('/inventory/locations/create');
        }

        $input = $this->locationInput();
        $result = $this->locations->create(
            $input,
            (int) ($_SESSION['auth']['user_id'] ?? 0)
        );

        if (!$result['successful']) {
            \flash(
                'location_create_errors',
                $result['errors']
            );
            \flash('location_create_old', $input);
            \redirect('/inventory/locations/create');
        }

        \flash('location_notice', [
            'type' => 'success',
            'message' => sprintf(
                'Location %s was created.',
                (string) $result['locationName']
            ),
        ]);
        \redirect('/inventory/locations');
    }

    public function provision(): void
    {
        $this->authorize('inventory.warehouses.manage');

        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('location_provision_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect('/inventory/locations');
        }

        $result = $this->locations->provisionDefaults(
            (int) \postString('warehouse_id'),
            (int) ($_SESSION['auth']['user_id'] ?? 0)
        );

        if (!$result['successful']) {
            \flash(
                'location_provision_errors',
                $result['errors']
            );
            \redirect('/inventory/locations');
        }

        \flash('location_notice', [
            'type' => 'success',
            'message' => sprintf(
                '%s now has six operational locations and mapped Inventory operation types.',
                (string) $result['warehouseName']
            ),
        ]);
        \redirect('/inventory/locations');
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
            'pageTitle' => 'Create Warehouse Location',
            'pageDescription' =>
                'Create a tenant-scoped location inside an active warehouse.',
            'contentView' =>
                'inventory.locations.form',
            'user' => $_SESSION['auth'],
            'old' => $old,
            'errors' => $errors,
        ] + $this->locations->formOptions());
    }

    private function authorize(string $permission): void
    {
        $this->authorization->requireModule('inventory');
        $this->authorization
            ->requireTenantPermission($permission);
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
    private function locationInput(): array
    {
        return [
            'warehouse_id' =>
                \postString('warehouse_id'),
            'parent_location_id' =>
                \postString('parent_location_id'),
            'code' => \postString('code'),
            'name' => \postString('name'),
            'location_type' =>
                \postString('location_type'),
            'barcode' => \postString('barcode'),
            'aisle' => \postString('aisle'),
            'rack' => \postString('rack'),
            'shelf' => \postString('shelf'),
            'bin' => \postString('bin'),
            'pick_priority' =>
                \postString('pick_priority'),
            'receiving_allowed' =>
                isset($_POST['receiving_allowed']),
            'picking_allowed' =>
                isset($_POST['picking_allowed']),
            'active' => isset($_POST['active']),
        ];
    }
}
