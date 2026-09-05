<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\SalesService;
use App\Services\SalesQuickSaleService;
use App\Services\SalesWorkflowTraceService;
use App\Services\TenantContext;

final class SalesController
{
    private AuthorizationService $authorization;
    private SalesService $sales;
    private SalesQuickSaleService $quickSales;

    public function __construct()
    {
        $this->authorization = new AuthorizationService();
        $this->sales = new SalesService();
        $this->quickSales = new SalesQuickSaleService();
    }

    public function index(): void
    {
        $this->renderWorkspace('orders');
    }

    public function customers(): void { $this->renderWorkspace('customers'); }
    public function products(): void { $this->renderWorkspace('products'); }
    public function showCustomer(string $id): void{$this->authorize('sales.view');$this->renderMaster('customer',(int)$id);}
    public function showProduct(string $id): void{$this->authorize('sales.view');$this->renderMaster('product',(int)$id);}
    private function renderMaster(string $type,int $id): void
    {
        $this->redirectSimpleSalesUser();$record=$type==='customer'?$this->sales->customer($id):$this->sales->product($id);if($record===null){http_response_code(404);\view('errors.404',['applicationName'=>\config('name','OfficeApp ERP')]);return;}$workspace=$this->sales->workspace();\view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>(string)$record['name'],'pageDescription'=>$type==='customer'?'Customer commercial master.':'Shared Sales and Inventory product master.','contentView'=>'sales.master','masterType'=>$type,'record'=>$record,'user'=>$_SESSION['auth'],'notice'=>\getFlash('sales_notice'),'errors'=>\getFlash('sales_errors',[]),'canManage'=>$this->can('sales.catalogue.manage')]+$workspace);}
    public function quotations(): void { $this->renderWorkspace('quotations'); }
    public function orders(): void { $this->renderWorkspace('orders'); }
    public function quickSale(): void
    {
        $this->authorize('sales.view');

        $quickSale = $this->quickSales->workspace(
            $this->actorId()
        );

        if (empty($quickSale['eligible'])) {
            http_response_code(403);
        }

        $managerMode =
            ($quickSale['mode'] ?? '') === 'manager';

        \view('layouts.app', [
            'applicationName' =>
                \config('name', 'OfficeApp ERP'),
            'environment' =>
                \config('environment', 'unknown'),
            'pageTitle' =>
                $managerMode
                    ? 'Quick Sales'
                    : 'Quick Sale',
            'pageDescription' =>
                $managerMode
                    ? 'Review DSA/DSP Quick Sales awaiting allocation.'
                    : 'Simple DSA/DSP Sales entry.',
            'contentView' =>
                $managerMode
                    ? 'sales.quick-sale-manager'
                    : 'sales.quick-sale',
            'quickSale' => $quickSale,
            'simpleSalesUser' =>
                ($quickSale['mode'] ?? '') === 'dsa',
            'moduleContext' => [
                'module' => 'sales',
                'section' => 'quick_sale',
            ],
            'user' => $_SESSION['auth'],
            'notice' => \getFlash('sales_notice'),
            'errors' => \getFlash('sales_errors', []),
            'old' => \getFlash('sales_old', []),
        ]);
    }

public function showQuickSale(string $id): void
    {
        $privilegedReviewer =
            $this->can('finance.records.view')
            || $this->can('sales.settlements.review')
            || $this->can('finance.settlements.view')
            || $this->can('finance.settlements.reconcile')
            || $this->can('finance.settlements.approve');

        if (!$privilegedReviewer) {
            $this->authorize('sales.view');
        }

        $detail = $this->quickSales->detail(
            (int) $id,
            $this->actorId(),
            $privilegedReviewer
        );

        if (!empty($detail['notFound'])) {
            http_response_code(404);
            \view('errors.404', [
                'applicationName' =>
                    \config('name', 'OfficeApp ERP'),
            ]);
            return;
        }

        if (empty($detail['successful'])) {
            http_response_code(403);
        }

        \view('layouts.app', [
            'applicationName' =>
                \config('name', 'OfficeApp ERP'),
            'environment' =>
                \config('environment', 'unknown'),
            'pageTitle' =>
                (string) (
                    $detail['quickSale']['quotation_number']
                    ?? 'Quick Sale'
                ),
            'pageDescription' =>
                'Simple DSA/DSP sale and Shop Manager allocation.',
            'contentView' =>
                'sales.quick-sale-detail',
            'quickSaleDetail' => $detail,
            'simpleSalesUser' =>
                $this->quickSales->isSimpleSalesUser(
                    $this->actorId()
                ),
            'moduleContext' => [
                'module' => 'sales',
                'section' => 'quick_sale',
            ],
            'user' => $_SESSION['auth'],
            'notice' => \getFlash('sales_notice'),
            'errors' => \getFlash('sales_errors', []),
        ]);
    }

    public function quickSaleEvidence(
        string $id,
        string $reportId
    ): void {
        $privilegedReviewer =
            $this->can('sales.settlements.review')
            || $this->can('finance.settlements.view')
            || $this->can('finance.settlements.reconcile')
            || $this->can('finance.settlements.approve');

        if (!$privilegedReviewer) {
            $this->authorize('sales.view');
        }

        $evidence = $this->quickSales->reportEvidence(
            (int) $id,
            (int) $reportId,
            $this->actorId(),
            $privilegedReviewer
        );

        if ($evidence === null) {
            http_response_code(404);
            return;
        }

        $path = (string) ($evidence['evidence_path'] ?? '');

        if ($path === '' || !is_file($path)) {
            http_response_code(404);
            return;
        }

        $mime = (string) (
            $evidence['evidence_mime']
            ?? 'application/octet-stream'
        );

        $allowedMime = [
            'application/pdf',
            'image/png',
            'image/jpeg',
        ];

        if (!in_array($mime, $allowedMime, true)) {
            http_response_code(404);
            return;
        }

        $filename = (string) (
            $evidence['evidence_original_name']
            ?? 'quick-sale-evidence'
        );

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header(
            'Content-Disposition: inline; filename="'
            . rawurlencode($filename)
            . '"'
        );
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');

        readfile($path);
        exit;
    }

    public function confirmQuickSale(string $id): void
    {
        $this->authorize('sales.view');
        $this->requireCsrf('quick_sale_confirm');

        $result = $this->quickSales->confirm(
            (int) $id,
            $this->actorId(),
            [
                'source_location_id' =>
                    $this->scalar(
                        $_POST['source_location_id']
                        ?? ''
                    ),
                'discount_amount' =>
                    is_array($_POST['discount_amount'] ?? null)
                        ? $_POST['discount_amount']
                        : [],
                'tax_rate' =>
                    is_array($_POST['tax_rate'] ?? null)
                        ? $_POST['tax_rate']
                        : [],
            ]
        );

        $this->finishTo(
            $result,
            'quick_sale_confirm',
            'Quick Sale confirmed and stock allocated.',
            [],
            '/sales/quick-sale/' . (int) $id,
            '/sales/quick-sale/' . (int) $id
        );
    }

    public function escalateQuickSale(string $id): void
    {
        $this->authorize('sales.orders.confirm');
        $this->requireCsrf('quick_sale_escalate');
        $result = $this->quickSales->escalate((int) $id, $this->actorId(), \postString('reason'));
        $message = !empty($result['unresolved'])
            ? 'Stock unavailable at the highest manager. Request remains open.'
            : 'Request escalated to your direct parent manager.';
        $this->finishTo($result, 'quick_sale_escalate', $message, [],
            '/sales/quick-sale/' . (int) $id, '/sales/quick-sale');
    }

    public function handoffQuickSale(string $id, string $reportId): void
    {
        $this->authorize('sales.orders.confirm');
        $this->requireCsrf('quick_sale_handoff');
        $result = $this->quickSales->handoffToFinance((int) $id, (int) $reportId, $this->actorId());
        $this->finishTo($result, 'quick_sale_handoff', 'Confirmed sale sent to Finance.', [],
            '/sales/quick-sale/' . (int) $id, '/sales/quick-sale/' . (int) $id);
    }

    public function confirmQuickSaleReport(
        string $id,
        string $reportId
    ): void {
        $this->authorize('sales.view');
        $this->requireCsrf('quick_sale_report_confirm');

        $result = $this->quickSales->confirmReport(
            (int) $id,
            (int) $reportId,
            $this->actorId()
        );

        $this->finishTo(
            $result,
            'quick_sale_report_confirm',
            'Sales report confirmed. Quick Sale closed.',
            [],
            '/sales/quick-sale',
            '/sales/quick-sale/' . (int) $id
        );
    }

    public function returnQuickSaleReportForCorrection(
        string $id,
        string $reportId
    ): void {
        $this->authorize('sales.view');
        $this->requireCsrf('quick_sale_report_correction');

        $result = $this->quickSales->requestReportCorrection(
            (int) $id,
            (int) $reportId,
            $this->actorId(),
            \postString('review_note')
        );

        $this->finishTo(
            $result,
            'quick_sale_report_correction',
            'Sales report returned to the DSA/DSP for correction.',
            [],
            '/sales/quick-sale/' . (int) $id,
            '/sales/quick-sale/' . (int) $id
        );
    }

    public function reportQuickSale(string $id): void
    {
        $this->authorize('sales.view');
        $this->requireCsrf('quick_sale_report');

        $lines = [];

        foreach ((array) ($_POST['report_lines'] ?? []) as $lineId => $line) {
            if (!is_array($line)) {
                continue;
            }

            /*
             * DSA/DSP may submit only outcome quantities.
             * Product, allocation, warehouse, picking and order
             * identities are resolved again by the server.
             */
            $lines[(int) $lineId] = [
                'sold_quantity' =>
                    $this->scalar(
                        $line['sold_quantity'] ?? ''
                    ),
                'returned_quantity' =>
                    $this->scalar(
                        $line['returned_quantity'] ?? ''
                    ),
            ];
        }

        $result = $this->quickSales->submitReport(
            (int) $id,
            $this->actorId(),
            [
                'invoice_reference' =>
                    \postString('invoice_reference'),
                'payment_method' =>
                    \postString('payment_method'),
                'payment_reference' =>
                    \postString('payment_reference'),
                'report_note' =>
                    \postString('report_note'),
                'lines' => $lines,
            ],
            is_array($_FILES['invoice_attachment'] ?? null)
                ? $_FILES['invoice_attachment']
                : []
        );

        $this->finishTo(
            $result,
            'quick_sale_report',
            'Sales report sent to your Shop Manager for confirmation.',
            [],
            '/sales/quick-sale/' . (int) $id,
            '/sales/quick-sale/' . (int) $id
        );
    }

    public function storeQuickSale(): void
    {
        $this->authorize('sales.view');
        $this->requireCsrf('quick_sale');

        $lines = [];

        foreach ((array) ($_POST['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }

            /*
             * Intentionally accept ONLY product and quantity.
             * Agent/team/customer/pricelist/warehouse/tax/discount
             * are never trusted from the DSA/DSP request.
             */
            $lines[] = [
                'product_id' =>
                    $this->scalar($line['product_id'] ?? ''),
                'quantity' =>
                    $this->scalar($line['quantity'] ?? ''),
            ];
        }

        $result = $this->quickSales->create(
            $lines,
            $this->actorId()
        );

        $this->finishTo(
            $result,
            'quick_sale',
            'Quick Sale sent to your Shop Manager.',
            ['lines' => $lines],
            '/sales/quick-sale',
            '/sales/quick-sale'
        );
    }
    public function showOrder(string $id): void
    {
        $this->redirectSimpleSalesUser();
        $this->authorize('sales.view');
        $order=$this->sales->orderDetail((int)$id);
        if($order===null){http_response_code(404);\view('errors.404',['applicationName'=>\config('name','OfficeApp ERP')]);return;}
        $fulfilment=$this->sales->fulfilmentOptions($this->actorId(),array_column($order['lines'],'product_id'));
        $availability=[];if((int)($order['warehouse_id']??0)>0&&(int)($order['source_location_id']??0)>0){try{$availability=$this->sales->exactAvailability($this->actorId(),(int)$order['warehouse_id'],(int)$order['source_location_id'],array_column($order['lines'],'product_id'));}catch(\Throwable){}}
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>(string)$order['order_number'],'pageDescription'=>'Authoritative Sales, Inventory and Finance state.','contentView'=>'sales.order','order'=>$order,'fulfilmentWarehouses'=>$fulfilment['warehouses'],'fulfilmentLocations'=>$fulfilment['locations'],'fulfilmentAvailability'=>$availability,'user'=>$_SESSION['auth'],'notice'=>\getFlash('sales_notice'),'errors'=>\getFlash('sales_errors',[]),'canCreateInvoice'=>$this->can('finance.records.manage'),'canConfirm'=>$this->can('sales.orders.confirm'),'workflowTrace'=>$this->workflowTrace('order',(int)$order['order_id'])]);
    }
    public function createInvoice(string $id): void
    {
        $this->redirectSimpleSalesUser();$this->authorize('sales.view');$this->authorization->requireModulePermission('finance','finance.records.manage');$this->requireCsrf('sales_invoice');$result=$this->sales->createInvoice((int)$id,\postString('invoice_policy')?:'delivered',$this->actorId());$this->finishTo($result,'sales_invoice','Customer invoice created.',[],'/sales/orders/'.(int)$id,'/sales/orders/'.(int)$id);}
    public function createCreditNote(string $id): void
    {
        $this->redirectSimpleSalesUser();$this->authorize('sales.view');$this->authorization->requireModulePermission('finance','finance.records.manage');$this->requireCsrf('sales_invoice');$result=$this->sales->createCreditNote((int)$id,$this->actorId());$this->finishTo($result,'sales_invoice','Customer credit note created.',[],'/sales/orders/'.(int)$id,'/sales/orders/'.(int)$id);}
    public function pricelists(): void { $this->renderWorkspace('pricelists'); }
    public function teams(): void { $this->renderWorkspace('teams'); }
    public function deliveries(): void
    {
        $this->redirectSimpleSalesUser();
        $this->authorize('sales.view');
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Deliveries','pageDescription'=>'Authoritative Inventory pickings created from Sales Orders.','contentView'=>'sales.deliveries','deliveries'=>$this->sales->deliveries(),'user'=>$_SESSION['auth'],'notice'=>\getFlash('sales_notice'),'errors'=>\getFlash('sales_errors',[])]);
    }
    public function showDelivery(string $id): void
    {
        $this->redirectSimpleSalesUser();
        $this->authorize('sales.view');$delivery=$this->sales->delivery((int)$id);
        if($delivery===null){http_response_code(404);\view('errors.404',['applicationName'=>\config('name','OfficeApp ERP')]);return;}
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>(string)$delivery['picking_number'],'pageDescription'=>'Validate authoritative Inventory delivery and return documents.','contentView'=>'sales.delivery','delivery'=>$delivery,'user'=>$_SESSION['auth'],'notice'=>\getFlash('sales_notice'),'errors'=>\getFlash('sales_errors',[]),'canComplete'=>$this->can('inventory.deliveries.validate'),'canReturn'=>$this->can('inventory.deliveries.validate'),'workflowTrace'=>$this->workflowTrace('delivery',(int)$delivery['picking_id'])]);
    }
    public function completeDelivery(string $id): void
    {
        $this->authorizeInventoryDelivery();$this->requireCsrf('sales_delivery');
        $document=$this->sales->delivery((int)$id);
        $result=$this->sales->completeDelivery((int)$id,['completed_quantity'=>$_POST['completed_quantity']??[],'create_backorder'=>\postString('create_backorder'),'idempotency_key'=>\postString('idempotency_key')],$this->actorId());
        $message=is_array($document)&&($document['picking_type']??'')==='customer_return'?'Return validated in Inventory.':'Delivery quantities validated in Inventory.';
        $this->finishTo($result,'sales_delivery',$message,[],'/sales/deliveries/'.(int)$id,'/sales/deliveries/'.(int)($result['backorderPickingId']??$id));
    }
    public function reserveDelivery(string $id): void
    {$this->authorizeInventoryDelivery();$this->requireCsrf('sales_delivery_reserve');$result=$this->sales->reserveDelivery((int)$id,$this->actorId());$this->finishTo($result,'sales_delivery_reserve','Stock reserved. The delivery is ready for validation.',[],'/sales/deliveries/'.(int)$id,'/sales/deliveries/'.(int)$id);}
    public function createReturn(string $id): void
    {$this->authorizeInventoryDelivery();$this->requireCsrf('sales_delivery_return');$result=$this->sales->createReturn((int)$id,$_POST,$this->actorId());$this->finishTo($result,'sales_delivery_return','Return document created. Validate it to move returned stock.',[],'/sales/deliveries/'.(int)$id,'/sales/deliveries/'.(int)($result['returnPickingId']??$id));}
    public function showPricelist(string $id): void{$this->authorize('sales.view');$this->renderCommercial('pricelist',(int)$id);}
    public function showTeam(string $id): void{$this->authorize('sales.view');$this->renderCommercial('team',(int)$id);}
    private function renderCommercial(string $type,int $id): void
    {
        $this->redirectSimpleSalesUser();$record=$type==='pricelist'?$this->sales->pricelist($id):$this->sales->salesTeam($id,$this->actorId());if($record===null){http_response_code(404);\view('errors.404',['applicationName'=>\config('name','OfficeApp ERP')]);return;}$workspace=$this->sales->workspace();\view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>(string)$record['name'],'pageDescription'=>$type==='pricelist'?'Pricelist details and deterministic rules.':'Sales team leader and members.','contentView'=>'sales.commercial','commercialType'=>$type,'record'=>$record,'user'=>$_SESSION['auth'],'notice'=>\getFlash('sales_notice'),'errors'=>\getFlash('sales_errors',[]),'canManage'=>$this->can('sales.catalogue.manage')]+$workspace);}

    public function createQuotation(): void
    {
        $this->authorize('sales.orders.create');
        $this->renderQuotationPage('create');
    }

    public function showQuotation(string $id): void
    {
        $this->authorize('sales.view');
        $this->renderQuotationPage('show', (int) $id);
    }

    public function editQuotation(string $id): void
    {
        $this->authorize('sales.orders.create');
        $this->renderQuotationPage('edit', (int) $id);
    }

    private function renderQuotationPage(string $mode, ?int $quotationId = null): void
    {
        $this->redirectSimpleSalesUser();

        if ($quotationId !== null) {
            $this->guardQuickSaleQuotation($quotationId);
        }

        $workspace = $this->sales->workspace();
        $quotation = $quotationId === null ? null : $this->sales->quotation($quotationId);
        if ($quotationId !== null && $quotation === null) {
            http_response_code(404);
            \view('errors.404', ['applicationName' => \config('name', 'OfficeApp ERP')]);
            return;
        }
        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => $mode === 'create' ? 'New quotation' : (string) ($quotation['quotation_number'] ?? 'Quotation'),
            'pageDescription' => 'Create, review and progress an authoritative Sales quotation.',
            'contentView' => 'sales.quotation', 'quotationMode' => $mode,
            'quotation' => $quotation, 'user' => $_SESSION['auth'],
            'notice' => \getFlash('sales_notice'),
            'errors' => \getFlash('sales_errors', []),
            'old' => \getFlash('sales_old', []),
            'canEdit' => $this->can('sales.orders.create'),
            'canTransition' => $this->can('sales.orders.submit'),
            'workflowTrace' => $quotationId === null
                ? null
                : $this->workflowTrace('quotation', $quotationId),
        ] + $workspace);
    }

    private function workflowTrace(string $type, int $id): ?array
    {
        return (new SalesWorkflowTraceService())->trace(
            (new TenantContext())->companyId(),
            $type,
            $id,
            $_SESSION['auth'] ?? []
        );
    }

    private function renderWorkspace(string $section): void
    {
        $this->redirectSimpleSalesUser();
        $this->authorize('sales.view');
        $workspace = $this->sales->workspace();

        \view('layouts.app', [
            'applicationName' => \config('name', 'OfficeApp ERP'),
            'environment' => \config('environment', 'unknown'),
            'pageTitle' => 'Sales',
            'pageDescription' => 'Customer orders, telecom products, commissions and receivables.',
            'salesSection' => $section,
            'contentView' => 'sales.index',
            'user' => $_SESSION['auth'],
            'notice' => \getFlash('sales_notice'),
            'errors' => \getFlash('sales_errors', []),
            'old' => \getFlash('sales_old', []),
            'canManageCatalogue' => $this->can('sales.catalogue.manage'),
            'canCreateOrders' => $this->can('sales.orders.create'),
            'canRecordPayments' => $this->can('sales.payments.record'),
            'canManageTargets' => $this->can('sales.targets.manage'),
            'canSubmitOrders' => $this->can('sales.orders.submit'),
            'canApproveOrders' => $this->can('sales.orders.approve'),
            'canFulfillOrders' => $this->can('sales.orders.confirm'),
            'canCancelOrders' => $this->can('sales.orders.cancel'),
            'canManageSerials' => $this->can('sales.serials.manage'),
            'canManageCommissions' => $this->can('sales.commissions.manage'),
            'canExportReports' => $this->can('sales.reports.export'),
        ] + $workspace);
    }

    public function storeTerritory(): void
    {
        $this->authorize('sales.catalogue.manage');
        $this->requireCsrf('territory');
        $input = $this->input(['code', 'name']);
        $this->finish(
            $this->sales->createTerritory($input, $this->actorId()),
            'territory',
            'Territory created successfully.',
            $input
        );
    }

    public function storeAgent(): void
    {
        $this->authorize('sales.catalogue.manage');
        $this->requireCsrf('agent');
        $input = $this->input([
            'agent_code', 'name', 'agent_type', 'territory_id', 'phone',
        ]);
        $this->finish(
            $this->sales->createAgent($input, $this->actorId()),
            'agent',
            'DSA/DSP created successfully.',
            $input
        );
    }

    public function storeTarget(): void
    {
        $this->authorize('sales.targets.manage');
        $this->requireCsrf('target');
        $input = $this->input([
            'territory_id', 'agent_id', 'period_start', 'period_end',
            'target_amount', 'target_quantity',
        ]);
        $this->finish(
            $this->sales->createTarget($input, $this->actorId()),
            'target',
            'Sales target created successfully.',
            $input
        );
    }

    public function storeCustomer(): void
    {
        $this->authorize('sales.catalogue.manage');
        $this->requireCsrf('customer');
        $input = $this->input([
            'customer_number', 'name', 'customer_type', 'territory_id',
            'agent_id','team_id','pricelist_id','legal_name','tax_number','email','phone','mobile','address','street','street2','city','state_region','postal_code','country','credit_limit','credit_status','payment_terms_days','preferred_currency','credit_mode',
        ]);
        $this->finish($this->sales->createCustomer($input, $this->actorId()), 'customer', 'Customer created successfully.', $input);
    }

    public function updateCustomer(string $id): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('customer');$input=$this->input(['customer_number','name','customer_type','territory_id','agent_id','team_id','pricelist_id','legal_name','tax_number','email','phone','mobile','address','street','street2','city','state_region','postal_code','country','credit_limit','credit_status','payment_terms_days','preferred_currency','credit_mode']);$result=$this->sales->updateCustomer((int)$id,$input,$this->actorId());$this->finishTo($result,'customer','Customer saved.',$input,'/sales/customers/'.(int)$id,'/sales/customers/'.(int)$id);}
    public function toggleCustomer(string $id): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('customer');$result=$this->sales->setCustomerActive((int)$id,\postString('active')==='1',$this->actorId());$this->finishTo($result,'customer','Customer status updated.',[],'/sales/customers/'.(int)$id,'/sales/customers/'.(int)$id);}

    public function storeProduct(): void
    {
        $this->authorize('sales.catalogue.manage');
        $this->requireCsrf('product');
        $input = $this->input([
            'sku', 'name', 'category', 'product_type', 'unit_of_measure',
            'unit_price', 'commission_rate',
        ]) + ['serial_tracking' => isset($_POST['serial_tracking'])];
        $this->finish($this->sales->createProduct($input, $this->actorId()), 'product', 'Product created successfully.', $input);
    }

    public function updateProduct(string $id): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('product');$input=$this->input(['sku','name','category','product_type','unit_of_measure','unit_price','commission_rate'])+['serial_tracking'=>isset($_POST['serial_tracking'])];$result=$this->sales->updateProduct((int)$id,$input,$this->actorId());$this->finishTo($result,'product','Product saved.',$input,'/sales/products/'.(int)$id,'/sales/products/'.(int)$id);}
    public function toggleProduct(string $id): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('product');$result=$this->sales->setProductActive((int)$id,\postString('active')==='1',$this->actorId());$this->finishTo($result,'product','Product status updated.',[],'/sales/products/'.(int)$id,'/sales/products/'.(int)$id);}

    public function storeOrder(): void
    {
        $this->authorize('sales.orders.create');
        $this->requireCsrf('order');
        $input = $this->input([
            'customer_id', 'order_date', 'due_date', 'currency', 'territory_id',
            'agent_id', 'branch_id', 'warehouse_id', 'source_location_id', 'notes',
        ]) + [
            'confirm' => isset($_POST['confirm']),
            'lines' => $this->orderLines(),
        ];
        $result = $this->sales->createOrder($input, $this->actorId());
        $message = !empty($result['orderNumber'])
            ? 'Sales order ' . $result['orderNumber'] . ' created successfully.'
            : 'Sales order created successfully.';
        $this->finish($result, 'order', $message, $input);
    }

    public function storeQuotation(): void
    {
        $this->authorize('sales.orders.create');$this->requireCsrf('quotation');$input=$this->quotationInput();$result=$this->sales->createQuotation($input,$this->actorId());$this->finishTo($result,'quotation','Quotation created successfully.',$input,'/sales/quotations/create',!empty($result['id'])?'/sales/quotations/'.(int)$result['id']:'/sales/quotations');
    }
    public function updateQuotation(string $id): void
    {
        $quotationId = (int) $id;
        $this->guardQuickSaleQuotation($quotationId);
        $this->authorize('sales.orders.create');$this->requireCsrf('quotation');$input=$this->quotationInput();$result=$this->sales->updateQuotation($quotationId,$input,$this->actorId());$this->finishTo($result,'quotation','Quotation saved successfully.',$input,'/sales/quotations/'.$quotationId.'/edit','/sales/quotations/'.$quotationId);
    }
    public function confirmQuotation(string $id): void
    {
        $this->quotationTransition((int)$id,'confirm');
    }
    public function cancelQuotation(string $id): void
    {
        $this->quotationTransition((int)$id,'cancel');
    }
    public function sendQuotation(string $id): void
    {
        $this->quotationTransition((int)$id,'send');
    }
    private function quotationTransition(int $id,string $action): void
    {
        $this->guardQuickSaleQuotation($id);
        $this->authorize('sales.orders.submit');$this->requireCsrf('quotation_action');$result=$this->sales->transitionQuotation($id,$action,$this->actorId(),['warehouse_id'=>\postString('warehouse_id'),'source_location_id'=>\postString('source_location_id')]);$this->finishTo($result,'quotation_action','Quotation updated successfully.',['quotation_id'=>$id,'action'=>$action],'/sales/quotations/'.$id,'/sales/quotations/'.$id);
    }
    public function transitionQuotation(): void
    {
        $this->authorize('sales.orders.submit');
        $this->requireCsrf('quotation_action');

        $input = $this->input([
            'quotation_id',
            'action',
            'warehouse_id',
            'source_location_id',
        ]);

        $quotationId = (int) $input['quotation_id'];

        $this->guardQuickSaleQuotation($quotationId);

        $this->finish(
            $this->sales->transitionQuotation(
                $quotationId,
                $input['action'],
                $this->actorId(),
                $input
            ),
            'quotation_action',
            'Quotation updated successfully.',
            $input
        );
    }
    public function storePricelist(): void
    {
        $this->authorize('sales.catalogue.manage');$this->requireCsrf('pricelist');$input=$this->input(['name','currency','valid_from','valid_to','product_id','category','minimum_quantity','calculation','fixed_price','percentage_adjustment','rule_from','rule_to','priority']);$this->finish($this->sales->createPricelist($input,$this->actorId()),'pricelist','Pricelist created successfully.',$input);
    }
    public function updatePricelist(string $id): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('pricelist');$input=$this->input(['name','currency','valid_from','valid_to']);$result=$this->sales->updatePricelist((int)$id,$input);$this->finishTo($result,'pricelist','Pricelist saved.',$input,'/sales/pricelists/'.(int)$id,'/sales/pricelists/'.(int)$id);}
    public function storePricelistRule(string $id): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('pricelist_rule');$input=$this->input(['product_id','category','minimum_quantity','calculation','fixed_price','percentage_adjustment','rule_from','rule_to','priority']);$result=$this->sales->addPricelistRule((int)$id,$input);$this->finishTo($result,'pricelist_rule','Pricing rule added.',$input,'/sales/pricelists/'.(int)$id,'/sales/pricelists/'.(int)$id);}
    public function updatePricelistRule(string $id,string $ruleId): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('pricelist_rule');$input=$this->input(['product_id','category','minimum_quantity','calculation','fixed_price','percentage_adjustment','rule_from','rule_to','priority']);$result=$this->sales->updatePricelistRule((int)$id,(int)$ruleId,$input);$this->finishTo($result,'pricelist_rule','Pricing rule saved.',$input,'/sales/pricelists/'.(int)$id,'/sales/pricelists/'.(int)$id);}
    public function togglePricelistRule(string $id,string $ruleId): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('pricelist_rule');$result=$this->sales->setPricelistRuleActive((int)$id,(int)$ruleId,\postString('active')==='1');$this->finishTo($result,'pricelist_rule','Pricing rule status updated.',[],'/sales/pricelists/'.(int)$id,'/sales/pricelists/'.(int)$id);}
    public function togglePricelist(string $id): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('pricelist');$result=$this->sales->setPricelistActive((int)$id,\postString('active')==='1');$this->finishTo($result,'pricelist','Pricelist status updated.',[],'/sales/pricelists/'.(int)$id,'/sales/pricelists/'.(int)$id);}
    public function storeTeam(): void
    {
        $this->authorize('sales.catalogue.manage');$this->requireCsrf('team');$input=$this->input(['name','leader_agent_id','territory_id'])+['member_ids'=>is_array($_POST['member_ids']??null)?$_POST['member_ids']:[]];$this->finish($this->sales->createSalesTeam($input,$this->actorId()),'team','Sales team created successfully.',$input);
    }
    public function updateTeam(string $id): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('team');$input=$this->input(['name','leader_agent_id','territory_id'])+['member_ids'=>is_array($_POST['member_ids']??null)?$_POST['member_ids']:[]];$result=$this->sales->updateSalesTeam((int)$id,$input);$this->finishTo($result,'team','Sales team saved.',$input,'/sales/teams/'.(int)$id,'/sales/teams/'.(int)$id);}
    public function toggleTeam(string $id): void{$this->authorize('sales.catalogue.manage');$this->requireCsrf('team');$result=$this->sales->setSalesTeamActive((int)$id,\postString('active')==='1');$this->finishTo($result,'team','Sales team status updated.',[],'/sales/teams/'.(int)$id,'/sales/teams/'.(int)$id);}

    public function recordPayment(): void
    {
        $this->authorize('sales.payments.record');
        $this->requireCsrf('payment');
        $input = $this->input([
            'order_id', 'receipt_number', 'payment_date', 'amount',
            'payment_method', 'reference_number', 'notes',
        ]);
        $this->finish(
            $this->sales->recordPayment((int) $input['order_id'], $input, $this->actorId()),
            'payment', 'Payment recorded and receivable updated.', $input
        );
    }

    public function transitionOrder(): void
    {
        $action = \postString('action');
        $permission = match ($action) {
            'submit' => 'sales.orders.submit',
            'approve' => 'sales.orders.approve',
            'confirm' => 'sales.orders.confirm',
            'fulfill' => 'sales.orders.confirm',
            'cancel' => 'sales.orders.cancel',
            default => 'sales.orders.approve',
        };
        $this->authorize($permission);
        $this->requireCsrf('order_action');
        $input = $this->input(['order_id', 'action', 'reason', 'idempotency_key', 'warehouse_id', 'source_location_id']);
        $this->finish(
            $this->sales->transitionOrder(
                (int) $input['order_id'], $input['action'],
                $input['reason'] ?: null, $this->actorId(), $input['idempotency_key'],
                (int)$input['warehouse_id']?:null,(int)$input['source_location_id']?:null
            ),
            'order_action', $action==='fulfill'?'Inventory delivery prepared successfully.':'Order status updated successfully.', $input
        );
    }

    public function storeSerialNumbers(): void
    {
        $this->authorize('sales.serials.manage');
        $this->requireCsrf('serials');
        $input = $this->input(['product_id', 'serial_numbers']);
        $this->finish(
            $this->sales->registerSerialNumbers($input, $this->actorId()),
            'serials', 'Serial numbers registered successfully.', $input
        );
    }

    public function transitionCommission(): void
    {
        $this->authorize('sales.commissions.manage');
        $this->requireCsrf('commission_action');
        $input = $this->input(['commission_id', 'action']);
        $this->finish(
            $this->sales->transitionCommission((int) $input['commission_id'], $input['action'], $this->actorId()),
            'commission_action', 'Commission status updated successfully.', $input
        );
    }

    public function export(): void
    {
        $this->authorize('sales.reports.export');
        $workspace = $this->sales->workspace();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="sales-orders-' . date('Y-m-d') . '.csv"');
        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            throw new \RuntimeException('The sales export could not be opened.');
        }
        fputcsv($stream, [
            'Order number', 'Customer', 'Order date', 'Due date',
            'Currency', 'Total', 'Paid', 'Balance', 'Status', 'DSA/DSP',
        ]);
        foreach ($workspace['orders'] as $order) {
            fputcsv($stream, [
                $order['order_number'], $order['customer_name'],
                $order['order_date'], $order['due_date'], $order['currency'],
                $order['total_amount'], $order['paid_amount'],
                $order['balance_due'], $order['status'], $order['agent_name'] ?? '',
            ]);
        }
        fclose($stream);
        exit;
    }

    private function redirectSimpleSalesUser(): void
    {
        if (
            $this->quickSales->isSimpleSalesUser(
                $this->actorId()
            )
        ) {
            \redirect('/sales/quick-sale');
        }
    }
    private function guardQuickSaleQuotation(
        int $quotationId
    ): void {
        $quickSaleId =
            $this->quickSales->quickSaleIdForQuotation(
                $quotationId
            );

        if ($quickSaleId === null) {
            return;
        }

        \flash('sales_notice', [
            'message' =>
                'This quotation belongs to Quick Sale. '
                . 'Use the Quick Sale workflow.',
        ]);

        \redirect(
            '/sales/quick-sale/' . $quickSaleId
        );
    }
    private function authorize(string $permission): void
    {
        if (
            $permission !== 'sales.view'
            && $this->quickSales->isSimpleSalesUser(
                $this->actorId()
            )
        ) {
            \redirect('/sales/quick-sale');
        }

        $this->authorization->requireModulePermission(
            'sales',
            $permission
        );
    }

    private function authorizeInventoryDelivery(): void
    {
        $this->redirectSimpleSalesUser();
        $this->authorization->requireModule('sales');
        $this->authorization->requireModulePermission(
            'inventory',
            'inventory.deliveries.validate'
        );
    }

    private function requireCsrf(string $form): void
    {
        if (!\verifyCsrfToken(\postString('_token'))) {
            \flash('sales_errors', ['form' => 'The form session expired. Please try again.']);
            \flash('sales_old', ['form' => $form]);
            \redirect($this->salesReturnPath($form));
        }
    }

    /** @param list<string> $keys @return array<string, string> */
    private function input(array $keys): array
    {
        $input = [];
        foreach ($keys as $key) {
            $input[$key] = \postString($key);
        }
        return $input;
    }

    /** @return list<array<string, string>> */
    private function orderLines(): array
    {
        $nested = $_POST['lines'] ?? null;
        if (is_array($nested)) {
            $lines = [];
            foreach (array_slice($nested, 0, 50) as $line) {
                if (!is_array($line)) continue;
                $lines[] = [
                    'product_id' => $this->scalar($line['product_id'] ?? ''),
                    'quantity' => $this->scalar($line['quantity'] ?? ''),
                    'discount_amount' => $this->scalar($line['discount_amount'] ?? '0'),
                    'tax_rate' => $this->scalar($line['tax_rate'] ?? '0'),
                ];
            }
            return $lines;
        }

        $productIds = is_array($_POST['product_id'] ?? null)
            ? $_POST['product_id'] : [];
        $quantities = is_array($_POST['quantity'] ?? null)
            ? $_POST['quantity'] : [];
        $discounts = is_array($_POST['discount_amount'] ?? null)
            ? $_POST['discount_amount'] : [];
        $taxRates = is_array($_POST['tax_rate'] ?? null)
            ? $_POST['tax_rate'] : [];
        $count = min(max(count($productIds), count($quantities)), 50);
        $lines = [];
        for ($index = 0; $index < $count; $index++) {
            $lines[] = [
                'product_id' => $this->scalar($productIds[$index] ?? ''),
                'quantity' => $this->scalar($quantities[$index] ?? ''),
                'discount_amount' => $this->scalar($discounts[$index] ?? '0'),
                'tax_rate' => $this->scalar($taxRates[$index] ?? '0'),
            ];
        }
        return $lines;
    }

    private function quotationInput(): array
    {
        return $this->input(['customer_id','agent_id','team_id','pricelist_id','quotation_date','expiration_date','payment_terms_days','currency','billing_address','delivery_address','notes'])+['lines'=>$this->orderLines()];
    }

    private function scalar(mixed $value): string
    {
        return is_string($value) || is_numeric($value)
            ? trim((string) $value)
            : '';
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $old */
    private function finish(array $result, string $form, string $message, array $old): never
    {
        if (empty($result['successful'])) {
            \flash('sales_errors', $result['errors'] ?? ['form' => 'The request could not be completed.']);
            \flash('sales_old', $old + ['form' => $form]);
        } else {
            \flash('sales_notice', ['message' => $message]);
        }
        \redirect($this->salesReturnPath($form));
    }

    private function salesReturnPath(string $form): string
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $path = (string) (parse_url($referer, PHP_URL_PATH) ?? '');
            $query = (string) (parse_url($referer, PHP_URL_QUERY) ?? '');
            $base = rtrim(\appBasePath(), '/');
            $salesPrefix = $base . '/sales';

            if ($path === $salesPrefix || str_starts_with($path, $salesPrefix . '/')) {
                $relative = substr($path, strlen($base));
                return $relative . ($query !== '' ? '?' . $query : '');
            }

            if ($path === '/sales' || str_starts_with($path, '/sales/')) {
                return $path . ($query !== '' ? '?' . $query : '');
            }
        }

        return match ($form) {
            'territory', 'agent', 'team', 'target' => '/sales/teams',
            'customer' => '/sales/customers',
            'product' => '/sales/products',
            'pricelist', 'pricelist_rule' => '/sales/pricelists',
            'quotation', 'quotation_action' => '/sales/quotations',
            'order', 'payment', 'sales_invoice' => '/sales/orders',
            default => '/sales',
        };
    }

    private function finishTo(array $result,string $form,string $message,array $old,string $failurePath,string $successPath): never
    {
        if (empty($result['successful'])) {
            \flash('sales_errors',$result['errors']??['form'=>'The request could not be completed.']);
            \flash('sales_old',$old+['form'=>$form]);
            \redirect($failurePath);
        }
        \flash('sales_notice',['message'=>$message]);
        \redirect($successPath);
    }

    private function actorId(): int
    {
        return (int) ($_SESSION['auth']['user_id'] ?? 0);
    }

    private function can(string $permission): bool
    {
        return in_array($permission, $_SESSION['auth']['permissions'] ?? [], true);
    }
}
