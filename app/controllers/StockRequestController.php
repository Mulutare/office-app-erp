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
            'Stock request submitted to the responsible stock authority.'
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
            'Available stock allocated. Any shortage remains pending with the responsible manager.'
        );
    }

    public function proposePeer(string $id): void
    {
        $this->mutate('inventory.stock_requests.process',function() use($id): string {
            $this->service->proposePeer((int)$id,$_POST,$this->actor());
            return '/inventory/stock-requests/'.(int)$id;
        },'/inventory/stock-requests/'.(int)$id,'Peer proposal sent to the source manager. No stock moved.');
    }

    public function decidePeer(string $id): void
    {
        $this->mutate('inventory.stock_requests.process',function() use($id): string {
            $this->service->decidePeer((int)$id,(string)($_POST['decision']??''),(string)($_POST['reason']??''),$this->actor());
            return '/inventory/stock-requests';
        },'/inventory/stock-requests','Peer decision recorded.');
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

        $actorId = $this->actor();
        $companyId = (new \App\Services\TenantContext())->companyId();

        $authority = $workspace['stockRequestAuthority'] ?? null;
        $actorRole = (string) ($workspace['stockRequestActorRole'] ?? '');

        $isManagerAuthority =
            is_array($authority)
            && !empty($authority['active'])
            && in_array(
                (string) ($authority['authority_level'] ?? ''),
                ['shop', 'district', 'regional'],
                true
            );

        if ($isManagerAuthority) {
            $workspace['stockRequestActorRole'] =
                (string) $authority['authority_level'];
        }

        $workspace['canCreateStockRequest'] =
            (
                $isManagerAuthority
                || in_array($actorRole, ['dsa', 'dsp'], true)
            )
            && (new \App\Services\ModuleRoleService())->permissionAllowed(
                $companyId,
                $actorId,
                'inventory.stock_requests.create'
            );

        $workspace['canProcessStockRequest'] =
            $isManagerAuthority
            && (new \App\Services\ModuleRoleService())->permissionAllowed(
                $companyId,
                $actorId,
                'inventory.stock_requests.process'
            );
        /*
         * STOCK_REQUEST_RENDER_CONTEXT_V2
         *
         * Form rendering uses the current tenant, effective permission,
         * and active represented stock authority.
         */
        $renderActorId = $this->actor();

        $renderCompanyId =
            (new \App\Services\TenantContext())->companyId();

        $productStatement = \db()->prepare(
            "SELECT
                product_id,
                sku,
                name,
                unit_of_measure,
                product_type
             FROM sales_products
             WHERE company_id=:company_id
               AND active=TRUE
               AND deleted_at IS NULL
               AND (
                    product_type IS NULL
                    OR product_type NOT IN('service','fixed_asset')
               )
             ORDER BY name,product_id"
        );

        $productStatement->execute([
            'company_id' => $renderCompanyId,
        ]);

        $renderProducts =
            $productStatement->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $authorityStatement = \db()->prepare(
            "SELECT
                a.*,
                w.code AS warehouse_code,
                w.name AS warehouse_name,
                l.code AS location_code,
                l.name AS location_name
             FROM inventory_stock_authorities a
             INNER JOIN inventory_warehouses w
               ON w.company_id=a.company_id
              AND w.warehouse_id=a.warehouse_id
             INNER JOIN inventory_warehouse_locations l
               ON l.company_id=a.company_id
              AND l.warehouse_id=a.warehouse_id
              AND l.location_id=a.location_id
             WHERE a.company_id=:company_id
               AND a.user_id=:user_id
               AND a.active=TRUE
               AND w.active=TRUE
               AND w.deleted_at IS NULL
               AND l.active=TRUE
               AND l.deleted_at IS NULL
             LIMIT 1"
        );

        $authorityStatement->execute([
            'company_id' => $renderCompanyId,
            'user_id' => $renderActorId,
        ]);

        $renderAuthority =
            $authorityStatement->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($renderAuthority)) {
            $renderAuthority =
                is_array(
                    $workspace['stockRequestAuthority'] ?? null
                )
                    ? $workspace['stockRequestAuthority']
                    : null;
        }

        $renderRole = (string) (
            $workspace['stockRequestActorRole'] ?? ''
        );

        if (is_array($renderAuthority)) {
            $authorityLevel = (string) (
                $renderAuthority['authority_level'] ?? ''
            );

            if (
                in_array(
                    $authorityLevel,
                    ['shop', 'district', 'regional'],
                    true
                )
            ) {
                $renderRole = $authorityLevel;
            }
        }

        $moduleRole =
            new \App\Services\ModuleRoleService();

        $renderCanCreate =
            (
                is_array($renderAuthority)
                || in_array(
                    $renderRole,
                    ['dsa', 'dsp'],
                    true
                )
            )
            && $moduleRole->permissionAllowed(
                $renderCompanyId,
                $renderActorId,
                'inventory.stock_requests.create'
            );

        $renderCanProcess =
            is_array($renderAuthority)
            && $moduleRole->permissionAllowed(
                $renderCompanyId,
                $renderActorId,
                'inventory.stock_requests.process'
            );
        /*
         * STOCK_REQUEST_OWN_ASSIGNED_ROWS
         *
         * The list must always contain:
         *  - requests created by the current user; and
         *  - requests currently assigned to the current user.
         *
         * This keeps the visible task list consistent with the
         * Action Required badge.
         */
        $requestListStatement = \db()->prepare(
            "SELECT
                r.request_id,
                r.request_number,
                r.requester_user_id,
                r.current_handler_user_id,
                r.status,
                r.request_kind,
                r.notes,
                r.requested_at,

                requester.display_name AS requester_name,
                handler.display_name AS handler_name,

                a.authority_level AS serving_level,
                w.name AS serving_warehouse_name,
                l.name AS serving_location_name,

                COALESCE(
                    SUM(rl.requested_quantity),
                    0
                ) AS requested_quantity,

                COUNT(DISTINCT rl.request_line_id)
                    AS line_count

             FROM inventory_stock_requests r

             INNER JOIN users requester
               ON requester.user_id=r.requester_user_id

             LEFT JOIN users handler
               ON handler.user_id=r.current_handler_user_id

             LEFT JOIN inventory_stock_authorities a
               ON a.company_id=r.company_id
              AND a.authority_id=r.serving_authority_id

             LEFT JOIN inventory_warehouses w
               ON w.company_id=a.company_id
              AND w.warehouse_id=a.warehouse_id

             LEFT JOIN inventory_warehouse_locations l
               ON l.company_id=a.company_id
              AND l.warehouse_id=a.warehouse_id
              AND l.location_id=a.location_id

             LEFT JOIN inventory_stock_request_lines rl
               ON rl.company_id=r.company_id
              AND rl.request_id=r.request_id

             WHERE r.company_id=:company_id
               AND (
                    r.requester_user_id=:requester_user_id
                    OR
                    r.current_handler_user_id=:handler_user_id
               )

             GROUP BY
                r.request_id,
                r.request_number,
                r.requester_user_id,
                r.current_handler_user_id,
                r.status,
                r.request_kind,
                r.notes,
                r.requested_at,
                requester.display_name,
                handler.display_name,
                a.authority_level,
                w.name,
                l.name

             ORDER BY
                CASE
                    WHEN r.current_handler_user_id=:priority_user_id
                     AND r.status IN(
                        'pending_review',
                        'awaiting_transfer',
                        'awaiting_procurement'
                     )
                    THEN 0
                    ELSE 1
                END,
                r.requested_at DESC,
                r.request_id DESC"
        );

        $requestListStatement->execute([
            'company_id' => $renderCompanyId,
            'requester_user_id' => $renderActorId,
            'handler_user_id' => $renderActorId,
            'priority_user_id' => $renderActorId,
        ]);

        $ownAssignedRequests =
            $requestListStatement->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        /*
         * Preserve any hierarchy-visible requests already returned by
         * the service, while guaranteeing own/assigned rows are present.
         */
        $mergedRequests = [];

        foreach (
            is_array($workspace['stockRequests'] ?? null)
                ? $workspace['stockRequests']
                : []
            as $row
        ) {
            if (is_array($row)) {
                $mergedRequests[
                    (int) ($row['request_id'] ?? 0)
                ] = $row;
            }
        }

        foreach ($ownAssignedRequests as $row) {
            $id = (int) ($row['request_id'] ?? 0);

            if ($id > 0) {
                $mergedRequests[$id] = array_merge(
                    $mergedRequests[$id] ?? [],
                    $row
                );
            }
        }

        $workspace['stockRequests'] =
            array_values($mergedRequests);
        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => $requestId === null ? 'Stock Requests' : (string) (($workspace['stockRequest']['request_number'] ?? 'Stock Request')),
            'pageDescription' => 'Manager replenishment for each warehouse, with separate Shop allocation to DSA/DSP requests.',
            'contentView' => 'inventory.stock-requests',
            'user' => $_SESSION['auth'],
            'permissions' => $_SESSION['auth']['permissions'] ?? [],
            'stockRequests' => array_values($mergedRequests),

            'stockRequest' =>
                $workspace['stockRequest'] ?? null,

            'peerProposals' =>
                $workspace['peerProposals'] ?? [],

            'peerCandidates' =>
                $workspace['peerCandidates'] ?? [],

            'stockRequestActor' =>
                $workspace['stockRequestActor'] ?? null,

            'stockRequestDetailWorkspace' => [
                'request' =>
                    $workspace['stockRequest'] ?? null,

                'peerProposals' =>
                    $workspace['peerProposals'] ?? [],

                'peerCandidates' =>
                    $workspace['peerCandidates'] ?? [],
            ],
            'stockRequestProducts' => $renderProducts,
            'stockRequestAuthority' => $renderAuthority,
            'stockRequestActorRole' => $renderRole,
            'canCreateStockRequest' => $renderCanCreate,
            'canProcessStockRequest' => $renderCanProcess,
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
