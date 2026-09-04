<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ManagerTeamRepository;
use App\Repositories\RepositoryFactory;
use App\Repositories\SalesRepository;
use RuntimeException;
use Throwable;

final class SalesQuickSaleService
{
    private SalesRepository $sales;
    private ManagerTeamRepository $managerTeams;
    private TenantContext $tenant;
    private SalesService $salesService;
    private InventoryOperationalAccessService $operationalAccess;

    public function __construct(
        ?SalesRepository $sales = null,
        ?ManagerTeamRepository $managerTeams = null,
        ?TenantContext $tenant = null,
        ?SalesService $salesService = null,
        ?InventoryOperationalAccessService $operationalAccess = null
    ) {
        $this->sales = $sales ?? RepositoryFactory::sales();
        $this->managerTeams =
            $managerTeams ?? RepositoryFactory::managerTeams();
        $this->tenant = $tenant ?? new TenantContext();
        $this->salesService = $salesService ?? new SalesService();
        $this->operationalAccess =
            $operationalAccess ?? new InventoryOperationalAccessService();
    }

    /** @return array<string,mixed> */
    /**
     * DSA/DSP use the simplified Sales workflow regardless
     * of which optional Sales permissions they may hold.
     */
    public function isSimpleSalesUser(int $actorId): bool
    {
        if ($actorId <= 0) {
            return false;
        }

        $context = $this->managerTeams->reportingContext(
            $this->tenant->companyId(),
            $actorId
        );

        if (!is_array($context)) {
            return false;
        }

        return in_array(
            strtolower(
                trim((string) ($context['job_title'] ?? ''))
            ),
            ['dsa', 'dsp'],
            true
        );
    }
    public function quickSaleIdForQuotation(
        int $quotationId
    ): ?int {
        if ($quotationId < 1) {
            return null;
        }

        $statement = \db()->prepare(
            'SELECT quick_sale_id
             FROM sales_quick_sales
             WHERE company_id = :company_id
               AND quotation_id = :quotation_id
             LIMIT 1'
        );

        $statement->execute([
            'company_id' => $this->tenant->companyId(),
            'quotation_id' => $quotationId,
        ]);

        $quickSaleId = (int) $statement->fetchColumn();

        return $quickSaleId > 0
            ? $quickSaleId
            : null;
    }
    public function workspace(int $actorId): array
    {
        try {
            $companyId = $this->tenant->companyId();

            $context = $this->managerTeams->reportingContext(
                $companyId,
                $actorId
            );

            if (!is_array($context)) {
                throw new RuntimeException(
                    'An active company employee account is required.'
                );
            }

            $jobTitle = strtolower(
                trim((string) ($context['job_title'] ?? ''))
            );

            if ($jobTitle === 'shop manager') {
                return [
                    'eligible' => true,
                    'mode' => 'manager',
                    'actor' => $context,
                    'queue' => $this->managerQueue(
                        $companyId,
                        $actorId
                    ),
                    'waiting' => $this->managerWaitingQueue(
                        $companyId,
                        $actorId
                    ),
                    'history' => $this->quickSaleHistory(
                        $companyId,
                        $actorId,
                        true
                    ),
                    'currency' => $this->defaultCurrency(),
                ];
            }

            $actor = $this->resolveActor($companyId, $actorId);

            $salesContext = $this->resolveSalesContext(
                $companyId,
                $actorId,
                $actor
            );

            $warehouse = $this->resolveShopWarehouse(
                $companyId,
                $actorId
            );

            $products = array_values(array_filter(
                $this->sales->products($companyId),
                static fn (array $product): bool =>
                    !empty($product['active'])
            ));

            return [
                'eligible' => true,
                'mode' => 'dsa',
                'actor' => $actor,
                'agent' => $salesContext['agent'],
                'team' => $salesContext['team'],
                'manager' => [
                    'user_id' =>
                        (int) $actor['manager_user_id'],
                    'name' =>
                        (string) $actor['manager_display_name'],
                ],
                'warehouse' => $warehouse,
                'products' => $products,
                'tasks' => $this->dsaTaskQueue(
                    $companyId,
                    $actorId
                ),
                'history' => $this->quickSaleHistory(
                    $companyId,
                    $actorId,
                    false
                ),
                'currency' => $this->defaultCurrency(),
            ];
        } catch (Throwable $exception) {
            return [
                'eligible' => false,
                'mode' => 'unavailable',
                'error' => $exception->getMessage(),
                'products' => [],
                'currency' => $this->defaultCurrency(),
            ];
        }
    }
    /**
     * @param list<array<string,mixed>> $submittedLines
     * @return array<string,mixed>
     */
    public function create(
        array $submittedLines,
        int $actorId
    ): array {
        $companyId = 0;
        $quotationId = 0;

        try {
            $companyId = $this->tenant->companyId();
            $today = date('Y-m-d');

            $actor = $this->resolveActor($companyId, $actorId);
            $salesContext =
                $this->resolveSalesContext($companyId, $actorId, $actor);
            $warehouse =
                $this->resolveShopWarehouse($companyId, $actorId);

            $products = [];
            foreach ($this->sales->products($companyId) as $product) {
                if (!empty($product['active'])) {
                    $products[(int) $product['product_id']] = $product;
                }
            }

            $lines = [];
            foreach (array_slice($submittedLines, 0, 20) as $index => $line) {
                if (!is_array($line)) {
                    continue;
                }

                $productId = (int) ($line['product_id'] ?? 0);
                $quantity = (float) ($line['quantity'] ?? 0);

                if ($productId <= 0 && $quantity <= 0) {
                    continue;
                }

                if (
                    $productId <= 0
                    || !isset($products[$productId])
                    || $quantity <= 0
                ) {
                    return [
                        'successful' => false,
                        'errors' => [
                            'line_' . ($index + 1) =>
                                'Select a valid product and positive quantity.',
                        ],
                    ];
                }

                $lines[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ];
            }

            if ($lines === []) {
                return [
                    'successful' => false,
                    'errors' => [
                        'lines' => 'Add at least one product.',
                    ],
                ];
            }

            $pricelist = $this->resolveAutomaticPricelist(
                $companyId,
                $lines,
                $products,
                $today
            );

            $currency = $pricelist !== null
                ? strtoupper((string) ($pricelist['currency'] ?? 'ETB'))
                : $this->defaultCurrency();

            $customerId = $this->ensureTechnicalCustomer(
                $companyId,
                $actorId,
                $currency
            );

            $deliveryAddress =
                $this->warehouseDeliveryContact($warehouse);

            $quotationLines = array_map(
                static fn (array $line): array => [
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'discount_amount' => 0,
                    'tax_rate' => 0,
                ],
                $lines
            );

            $quotationResult = $this->salesService->createQuotation(
                [
                    'customer_id' => $customerId,
                    'agent_id' =>
                        (int) $salesContext['agent']['agent_id'],
                    'team_id' =>
                        (int) $salesContext['team']['team_id'],
                    'pricelist_id' =>
                        $pricelist === null
                            ? null
                            : (int) $pricelist['pricelist_id'],
                    'quotation_date' => $today,
                    'expiration_date' => null,
                    'payment_terms_days' => 0,
                    'currency' => $currency,
                    'billing_address' => null,
                    'delivery_address' => $deliveryAddress,
                    'notes' =>
                        'Quick Sale - awaiting Shop Manager confirmation.',
                    'lines' => $quotationLines,
                ],
                $actorId
            );

            if (empty($quotationResult['successful'])) {
                return $quotationResult;
            }

            $quotationId = (int) ($quotationResult['id'] ?? 0);
            if ($quotationId <= 0) {
                throw new RuntimeException(
                    'Quick Sale quotation was not created.'
                );
            }

            $this->recordQuickSale(
                $companyId,
                $quotationId,
                $actorId,
                (int) $salesContext['agent']['agent_id'],
                (int) $salesContext['team']['team_id'],
                (int) $actor['manager_user_id'],
                (int) $warehouse['warehouse_id']
            );

            /*
             * "sent" is the existing quotation state representing that
             * the commercial document is now waiting for confirmation.
             */
            $sent = $this->salesService->transitionQuotation(
                $quotationId,
                'send',
                $actorId
            );

            if (empty($sent['successful'])) {
                $this->setQuickSaleStatus(
                    $companyId,
                    $quotationId,
                    'cancelled'
                );

                $this->salesService->transitionQuotation(
                    $quotationId,
                    'cancel',
                    $actorId
                );

                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'The Quick Sale could not be sent to the manager.',
                    ],
                ];
            }

            return [
                'successful' => true,
                'id' => $quotationId,
                'number' => $quotationResult['number'] ?? null,
            ];
        } catch (Throwable $exception) {
            /*
             * createQuotation() owns its own transaction.
             * If a later Quick Sale step fails, compensate so
             * an invisible orphan quotation is not left active.
             */
            if ($companyId > 0 && $quotationId > 0) {
                try {
                    $this->setQuickSaleStatus(
                        $companyId,
                        $quotationId,
                        'cancelled'
                    );
                } catch (Throwable) {
                    // Metadata may not have been inserted yet.
                }

                try {
                    $this->salesService->transitionQuotation(
                        $quotationId,
                        'cancel',
                        $actorId
                    );
                } catch (Throwable) {
                    // Preserve the original failure for the user.
                }
            }

            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * Record the DSA/DSP field-sales result only.
     *
     * This step intentionally DOES NOT complete Inventory delivery,
     * release stock, create/post Finance invoices or close the sale.
     * Those authoritative operations occur only after Shop Manager
     * confirmation of the submitted report.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    public function submitReport(
        int $quickSaleId,
        int $actorId,
        array $input,
        array $file
    ): array {
        $upload = new PrivateUploadService();
        $stored = null;
        $connection = \db();

        try {
            if ($quickSaleId < 1 || $actorId < 1) {
                throw new RuntimeException(
                    'Quick Sale report is not available.'
                );
            }

            $companyId = $this->tenant->companyId();

            $context = $this->managerTeams->reportingContext(
                $companyId,
                $actorId
            );

            $jobTitle = is_array($context)
                ? strtolower(
                    trim((string) ($context['job_title'] ?? ''))
                )
                : '';

            if (!in_array($jobTitle, ['dsa', 'dsp'], true)) {
                throw new RuntimeException(
                    'Only the assigned DSA/DSP can submit this sales report.'
                );
            }

            $invoiceReference = trim(
                (string) ($input['invoice_reference'] ?? '')
            );

            $paymentMethod = strtolower(
                trim((string) ($input['payment_method'] ?? ''))
            );

            $paymentReference = trim(
                (string) ($input['payment_reference'] ?? '')
            );

            $reportNote = trim(
                (string) ($input['report_note'] ?? '')
            );

            if (mb_strlen($invoiceReference) > 120) {
                throw new RuntimeException(
                    'Invoice / receipt number is too long.'
                );
            }

            if (mb_strlen($paymentReference) > 120) {
                throw new RuntimeException(
                    'Payment reference is too long.'
                );
            }

            if (mb_strlen($reportNote) > 5000) {
                throw new RuntimeException(
                    'Report note is too long.'
                );
            }

            $allowedPaymentMethods = [
                'cash',
                'bank_transfer',
                'mobile_money',
                'card',
                'other',
            ];

            $connection->beginTransaction();

            $headerStatement = $connection->prepare(
                "SELECT
                    qs.quick_sale_id,
                    qs.user_id,
                    qs.status,
                    q.sales_order_id
                 FROM sales_quick_sales qs
                 INNER JOIN sales_quotations q
                   ON q.company_id = qs.company_id
                  AND q.quotation_id = qs.quotation_id
                 WHERE qs.company_id = :company_id
                   AND qs.quick_sale_id = :quick_sale_id
                 FOR UPDATE"
            );

            $headerStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
            ]);

            $header = $headerStatement->fetch(\PDO::FETCH_ASSOC);

            if (
                !is_array($header)
                || (int) $header['user_id'] !== $actorId
            ) {
                throw new RuntimeException(
                    'Quick Sale is not available for reporting.'
                );
            }

            /*
             * A reported Quick Sale normally has a submitted report
             * waiting for manager review. Repeated browser submission
             * reuses that report.
             *
             * When the latest report was explicitly returned by the
             * assigned Shop Manager as correction_required, the DSA/DSP
             * may submit a NEW report. The rejected report and evidence
             * remain immutable for audit.
             */
            $headerStatus = (string) $header['status'];
            $correctionReport = null;

            if ($headerStatus === 'reported') {
                $priorStatement = $connection->prepare(
                    "SELECT
                        report_id,
                        status,
                        review_note
                     FROM sales_quick_sale_reports
                     WHERE company_id = :company_id
                       AND quick_sale_id = :quick_sale_id
                       AND reported_by_user_id = :actor_id
                     ORDER BY report_id DESC
                     LIMIT 1
                     FOR UPDATE"
                );

                $priorStatement->execute([
                    'company_id' => $companyId,
                    'quick_sale_id' => $quickSaleId,
                    'actor_id' => $actorId,
                ]);

                $priorReport =
                    $priorStatement->fetch(\PDO::FETCH_ASSOC);

                if (
                    is_array($priorReport)
                    && (string) $priorReport['status'] === 'submitted'
                ) {
                    $connection->commit();

                    return [
                        'successful' => true,
                        'reportId' =>
                            (int) $priorReport['report_id'],
                        'replayed' => true,
                    ];
                }

                if (
                    is_array($priorReport)
                    && (string) $priorReport['status']
                        === 'correction_required'
                ) {
                    $correctionReport = $priorReport;
                }
            }

            if (
                $headerStatus !== 'allocated'
                && !(
                    $headerStatus === 'reported'
                    && is_array($correctionReport)
                )
            ) {
                throw new RuntimeException(
                    'This Quick Sale is not available for sales reporting.'
                );
            }

            $orderId = (int) ($header['sales_order_id'] ?? 0);

            if ($orderId < 1) {
                throw new RuntimeException(
                    'The allocated Sales Order could not be found.'
                );
            }

            $lineStatement = $connection->prepare(
                "SELECT
                    p.picking_id,
                    pl.picking_line_id,
                    pl.product_id,
                    pl.reserved_quantity,
                    pl.completed_quantity
                 FROM inventory_pickings p
                 INNER JOIN inventory_picking_lines pl
                   ON pl.company_id = p.company_id
                  AND pl.picking_id = p.picking_id
                 WHERE p.company_id = :company_id
                   AND p.sales_order_id = :order_id
                   AND p.picking_type = 'delivery'
                   AND p.status = 'ready'
                   AND pl.status <> 'cancelled'
                 ORDER BY pl.picking_line_id
                 FOR UPDATE"
            );

            $lineStatement->execute([
                'company_id' => $companyId,
                'order_id' => $orderId,
            ]);

            $authoritativeLines =
                $lineStatement->fetchAll(\PDO::FETCH_ASSOC);

            if ($authoritativeLines === []) {
                throw new RuntimeException(
                    'No ready allocated delivery lines were found.'
                );
            }

            $submittedLines = is_array($input['lines'] ?? null)
                ? $input['lines']
                : [];

            $allowedLineIds = [];
            $validatedLines = [];
            $totalSold = 0.0;

            foreach ($authoritativeLines as $line) {
                $lineId = (int) $line['picking_line_id'];
                $allowedLineIds[$lineId] = true;

                $submitted = $submittedLines[$lineId] ?? null;

                if (!is_array($submitted)) {
                    throw new RuntimeException(
                        'Enter sold and returned quantity for every allocated product.'
                    );
                }

                $soldRaw = $submitted['sold_quantity'] ?? null;
                $returnedRaw =
                    $submitted['returned_quantity'] ?? null;

                if (
                    !is_numeric($soldRaw)
                    || !is_numeric($returnedRaw)
                ) {
                    throw new RuntimeException(
                        'Sold and returned quantities must be numeric.'
                    );
                }

                $sold = round((float) $soldRaw, 3);
                $returned = round((float) $returnedRaw, 3);

                $allocated = round(
                    (float) $line['reserved_quantity']
                    - (float) $line['completed_quantity'],
                    3
                );

                if (
                    $allocated <= 0.0005
                    || $sold < 0
                    || $returned < 0
                ) {
                    throw new RuntimeException(
                        'Reported quantities are invalid.'
                    );
                }

                if (
                    abs(
                        $allocated
                        - ($sold + $returned)
                    ) > 0.0005
                ) {
                    throw new RuntimeException(
                        'For every product, Sold + Returned must equal the allocated quantity.'
                    );
                }

                $validatedLines[] = [
                    'picking_line_id' => $lineId,
                    'product_id' => (int) $line['product_id'],
                    'allocated_quantity' => $allocated,
                    'sold_quantity' => $sold,
                    'returned_quantity' => $returned,
                ];

                $totalSold += $sold;
            }

            foreach (array_keys($submittedLines) as $submittedLineId) {
                if (
                    !isset(
                        $allowedLineIds[(int) $submittedLineId]
                    )
                ) {
                    throw new RuntimeException(
                        'The report contains a product line that is not part of this allocation.'
                    );
                }
            }

            /*
             * If anything was actually sold, invoice/receipt evidence
             * is mandatory. An all-return report legitimately has no
             * customer invoice.
             */
            if ($totalSold > 0.0005) {
                if ($invoiceReference === '') {
                    throw new RuntimeException(
                        'Enter the invoice / receipt number.'
                    );
                }

                if (
                    !in_array(
                        $paymentMethod,
                        $allowedPaymentMethods,
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Select how the customer paid.'
                    );
                }

                if (
                    $paymentMethod !== 'cash'
                    && $paymentReference === ''
                ) {
                    throw new RuntimeException(
                        'Enter the payment reference for non-cash sales.'
                    );
                }

                $stored = $upload->storeQuickSaleInvoice(
                    $companyId,
                    $file
                );
            } else {
                $invoiceReference = '';
                $paymentMethod = '';
                $paymentReference = '';
            }

            $reportStatement = $connection->prepare(
                "INSERT INTO sales_quick_sale_reports (
                    company_id,
                    quick_sale_id,
                    reported_by_user_id,
                    status,
                    invoice_reference,
                    payment_method,
                    payment_reference,
                    report_note,
                    evidence_path,
                    evidence_original_name,
                    evidence_mime,
                    evidence_size,
                    evidence_sha256
                 ) VALUES (
                    :company_id,
                    :quick_sale_id,
                    :reported_by_user_id,
                    'submitted',
                    :invoice_reference,
                    :payment_method,
                    :payment_reference,
                    :report_note,
                    :evidence_path,
                    :evidence_original_name,
                    :evidence_mime,
                    :evidence_size,
                    :evidence_sha256
                 )"
            );

            $reportStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
                'reported_by_user_id' => $actorId,
                'invoice_reference' =>
                    $invoiceReference !== ''
                        ? $invoiceReference
                        : null,
                'payment_method' =>
                    $paymentMethod !== ''
                        ? $paymentMethod
                        : null,
                'payment_reference' =>
                    $paymentReference !== ''
                        ? $paymentReference
                        : null,
                'report_note' =>
                    $reportNote !== ''
                        ? $reportNote
                        : null,
                'evidence_path' =>
                    is_array($stored)
                        ? $stored['evidence_path']
                        : null,
                'evidence_original_name' =>
                    is_array($stored)
                        ? $stored['evidence_original_name']
                        : null,
                'evidence_mime' =>
                    is_array($stored)
                        ? $stored['evidence_mime']
                        : null,
                'evidence_size' =>
                    is_array($stored)
                        ? $stored['evidence_size']
                        : null,
                'evidence_sha256' =>
                    is_array($stored)
                        ? $stored['evidence_sha256']
                        : null,
            ]);

            $reportId = (int) $connection->lastInsertId();

            if ($reportId < 1) {
                throw new RuntimeException(
                    'Sales report could not be created.'
                );
            }

            $lineInsert = $connection->prepare(
                "INSERT INTO sales_quick_sale_report_lines (
                    company_id,
                    report_id,
                    picking_line_id,
                    product_id,
                    allocated_quantity,
                    sold_quantity,
                    returned_quantity
                 ) VALUES (
                    :company_id,
                    :report_id,
                    :picking_line_id,
                    :product_id,
                    :allocated_quantity,
                    :sold_quantity,
                    :returned_quantity
                 )"
            );

            foreach ($validatedLines as $line) {
                $lineInsert->execute([
                    'company_id' => $companyId,
                    'report_id' => $reportId,
                    'picking_line_id' =>
                        $line['picking_line_id'],
                    'product_id' =>
                        $line['product_id'],
                    'allocated_quantity' =>
                        number_format(
                            $line['allocated_quantity'],
                            3,
                            '.',
                            ''
                        ),
                    'sold_quantity' =>
                        number_format(
                            $line['sold_quantity'],
                            3,
                            '.',
                            ''
                        ),
                    'returned_quantity' =>
                        number_format(
                            $line['returned_quantity'],
                            3,
                            '.',
                            ''
                        ),
                ]);
            }

            if ($headerStatus === 'allocated') {
                $statusStatement = $connection->prepare(
                    "UPDATE sales_quick_sales
                     SET status = 'reported',
                         updated_at = CURRENT_TIMESTAMP
                     WHERE company_id = :company_id
                       AND quick_sale_id = :quick_sale_id
                       AND user_id = :actor_id
                       AND status = 'allocated'"
                );

                $statusStatement->execute([
                    'company_id' => $companyId,
                    'quick_sale_id' => $quickSaleId,
                    'actor_id' => $actorId,
                ]);

                if ($statusStatement->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Quick Sale status changed while the report was being submitted.'
                    );
                }
            }

            $connection->commit();

            return [
                'successful' => true,
                'reportId' => $reportId,
                'replayed' => false,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            if (is_array($stored)) {
                $upload->remove(
                    (string) $stored['evidence_path']
                );
            }

            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
        }
    }

    /** @return array<string,mixed> */
    public function confirmReport(
        int $quickSaleId,
        int $reportId,
        int $actorId
    ): array {
        $connection = \db();
        $lockName =
            'quick-sale-report-confirm:'
            . $this->tenant->companyId()
            . ':'
            . $reportId;

        $lockHeld = false;

        try {
            if (
                $quickSaleId < 1
                || $reportId < 1
                || $actorId < 1
            ) {
                throw new RuntimeException(
                    'The sales report is not available for confirmation.'
                );
            }

            $companyId = $this->tenant->companyId();

            $context = $this->managerTeams->reportingContext(
                $companyId,
                $actorId
            );

            $jobTitle = is_array($context)
                ? strtolower(
                    trim((string) ($context['job_title'] ?? ''))
                )
                : '';

            if ($jobTitle !== 'shop manager') {
                throw new RuntimeException(
                    'Only the assigned Shop Manager may confirm this sales report.'
                );
            }

            /*
             * Serialize the whole cross-module workflow.
             * Inventory and Finance own their own transactions,
             * so we deliberately do NOT wrap those calls inside
             * one outer database transaction.
             */
            $lockStatement = $connection->prepare(
                'SELECT GET_LOCK(:lock_name, 10)'
            );

            $lockStatement->execute([
                'lock_name' => $lockName,
            ]);

            if ((int) $lockStatement->fetchColumn() !== 1) {
                throw new RuntimeException(
                    'This sales report is already being confirmed. Please try again.'
                );
            }

            $lockHeld = true;

            $headerStatement = $connection->prepare(
                "SELECT
                    qs.manager_user_id,
                    qs.status,
                    q.sales_order_id
                 FROM sales_quick_sales qs
                 INNER JOIN sales_quotations q
                   ON q.company_id = qs.company_id
                  AND q.quotation_id = qs.quotation_id
                 WHERE qs.company_id = :company_id
                   AND qs.quick_sale_id = :quick_sale_id
                 LIMIT 1"
            );

            $headerStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
            ]);

            $header = $headerStatement->fetch(\PDO::FETCH_ASSOC);

            if (
                !is_array($header)
                || (int) $header['manager_user_id'] !== $actorId
            ) {
                throw new RuntimeException(
                    'You are not the Shop Manager assigned to this Quick Sale.'
                );
            }

            $reportStatement = $connection->prepare(
                "SELECT *
                 FROM sales_quick_sale_reports
                 WHERE company_id = :company_id
                   AND quick_sale_id = :quick_sale_id
                   AND report_id = :report_id
                 LIMIT 1"
            );

            $reportStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
                'report_id' => $reportId,
            ]);

            $report = $reportStatement->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($report)) {
                throw new RuntimeException(
                    'The sales report could not be found.'
                );
            }

            /*
             * Completed confirmation replay.
             */
            if (
                (string) $header['status'] === 'closed'
                && (string) $report['status'] === 'confirmed'
            ) {
                return [
                    'successful' => true,
                    'reportId' => $reportId,
                    'invoiceId' =>
                        (int) ($report['finance_invoice_id'] ?? 0),
                    'replayed' => true,
                ];
            }

            if ((string) $header['status'] !== 'reported') {
                throw new RuntimeException(
                    'This Quick Sale is not waiting for report confirmation.'
                );
            }

            if ((string) $report['status'] !== 'submitted') {
                throw new RuntimeException(
                    'Only the latest submitted report can be confirmed.'
                );
            }

            $latestStatement = $connection->prepare(
                "SELECT report_id
                 FROM sales_quick_sale_reports
                 WHERE company_id = :company_id
                   AND quick_sale_id = :quick_sale_id
                 ORDER BY report_id DESC
                 LIMIT 1"
            );

            $latestStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
            ]);

            if ((int) $latestStatement->fetchColumn() !== $reportId) {
                throw new RuntimeException(
                    'A newer sales report exists. Open the latest report before confirming.'
                );
            }

            $orderId = (int) ($header['sales_order_id'] ?? 0);

            if ($orderId < 1) {
                throw new RuntimeException(
                    'The Quick Sale Sales Order could not be found.'
                );
            }

            /*
             * Read the immutable submitted quantities and reconnect
             * them to their authoritative delivery picking lines.
             */
            $lineStatement = $connection->prepare(
                "SELECT
                    rl.picking_line_id,
                    rl.product_id,
                    rl.allocated_quantity,
                    rl.sold_quantity,
                    rl.returned_quantity,
                    pl.picking_id,
                    pl.reserved_quantity,
                    pl.completed_quantity,
                    pl.status AS picking_line_status,
                    p.status AS picking_status
                 FROM sales_quick_sale_report_lines rl
                 INNER JOIN inventory_picking_lines pl
                   ON pl.company_id = rl.company_id
                  AND pl.picking_line_id = rl.picking_line_id
                 INNER JOIN inventory_pickings p
                   ON p.company_id = pl.company_id
                  AND p.picking_id = pl.picking_id
                 WHERE rl.company_id = :company_id
                   AND rl.report_id = :report_id
                   AND p.sales_order_id = :order_id
                   AND p.picking_type = 'delivery'
                 ORDER BY
                    pl.picking_id,
                    pl.picking_line_id"
            );

            $lineStatement->execute([
                'company_id' => $companyId,
                'report_id' => $reportId,
                'order_id' => $orderId,
            ]);

            $reportLines =
                $lineStatement->fetchAll(\PDO::FETCH_ASSOC);

            if ($reportLines === []) {
                throw new RuntimeException(
                    'The submitted report has no delivery lines to confirm.'
                );
            }

            $pickings = [];
            $totalSold = 0.0;

            foreach ($reportLines as $line) {
                $allocated = round(
                    (float) $line['allocated_quantity'],
                    3
                );

                $sold = round(
                    (float) $line['sold_quantity'],
                    3
                );

                $returned = round(
                    (float) $line['returned_quantity'],
                    3
                );

                if (
                    $allocated < 0
                    || $sold < 0
                    || $returned < 0
                    || abs(
                        $allocated
                        - ($sold + $returned)
                    ) > 0.0005
                ) {
                    throw new RuntimeException(
                        'The submitted report quantities are inconsistent.'
                    );
                }

                $pickingId = (int) $line['picking_id'];
                $lineId = (int) $line['picking_line_id'];

                if ($pickingId < 1 || $lineId < 1) {
                    throw new RuntimeException(
                        'A delivery reference is missing from the report.'
                    );
                }

                if (!isset($pickings[$pickingId])) {
                    $pickings[$pickingId] = [
                        'sold' => 0.0,
                        'quantities' => [],
                        'lines' => [],
                        'completion_key' =>
                            'quick-sale-report-'
                            . $reportId
                            . '-picking-'
                            . $pickingId,
                    ];
                }

                $pickings[$pickingId]['sold'] += $sold;
                $pickings[$pickingId]['quantities'][$lineId] = $sold;
                $pickings[$pickingId]['lines'][] = $line;

                $totalSold += $sold;
            }

            $totalSold = round($totalSold, 3);

            /*
             * Recovery guard:
             * a delivered invoice may already exist only when this
             * report's own picking completion keys already exist.
             * This handles a retry after an earlier request created
             * the invoice but failed before closing the Quick Sale.
             */
            $existingInvoiceStatement = $connection->prepare(
                "SELECT invoice_id
                 FROM finance_invoices
                 WHERE company_id = :company_id
                   AND sales_order_id = :order_id
                   AND document_type = 'customer_invoice'
                   AND invoice_policy = 'delivered'
                   AND status <> 'cancelled'
                 ORDER BY invoice_id"
            );

            $existingInvoiceStatement->execute([
                'company_id' => $companyId,
                'order_id' => $orderId,
            ]);

            $existingInvoiceIds =
                $existingInvoiceStatement->fetchAll(
                    \PDO::FETCH_COLUMN
                );

            $completionStatement = $connection->prepare(
                "SELECT 1
                 FROM inventory_picking_completions
                 WHERE company_id = :company_id
                   AND idempotency_key = :idempotency_key
                 LIMIT 1"
            );

            $allSoldPickingsPreviouslyCompleted = true;
            $hasSoldPicking = false;

            foreach ($pickings as $picking) {
                if ((float) $picking['sold'] <= 0.0005) {
                    continue;
                }

                $hasSoldPicking = true;

                $completionStatement->execute([
                    'company_id' => $companyId,
                    'idempotency_key' =>
                        $picking['completion_key'],
                ]);

                if ($completionStatement->fetchColumn() === false) {
                    $allSoldPickingsPreviouslyCompleted = false;
                }
            }

            if (!$hasSoldPicking) {
                $allSoldPickingsPreviouslyCompleted = false;
            }

            $invoiceId = 0;

            if ($existingInvoiceIds !== []) {
                if ($totalSold <= 0.0005) {
                    throw new RuntimeException(
                        'A delivered customer invoice already exists for an all-return Quick Sale.'
                    );
                }

                if (count($existingInvoiceIds) !== 1) {
                    throw new RuntimeException(
                        'Multiple delivered customer invoices already exist for this Quick Sale. Finance review is required.'
                    );
                }

                if (!$allSoldPickingsPreviouslyCompleted) {
                    throw new RuntimeException(
                        'A delivered customer invoice already exists before this Quick Sale report was completed.'
                    );
                }

                $invoiceId = (int) $existingInvoiceIds[0];
            }

            $inventory = RepositoryFactory::inventory();

            /*
             * Authoritative inventory finalization.
             *
             * Sold > 0:
             *   complete sold quantity with createBackorder=false.
             *   Inventory releases the unsold reserved remainder.
             *
             * Sold = 0:
             *   cancel the still-ready picking and release all reservation.
             */
            foreach ($pickings as $pickingId => $picking) {
                $pickingId = (int) $pickingId;
                $pickingSold = round(
                    (float) $picking['sold'],
                    3
                );

                if ($pickingSold > 0.0005) {
                    $completionStatement->execute([
                        'company_id' => $companyId,
                        'idempotency_key' =>
                            $picking['completion_key'],
                    ]);

                    $alreadyCompleted =
                        $completionStatement->fetchColumn() !== false;

                    if (!$alreadyCompleted) {
                        foreach ($picking['lines'] as $line) {
                            if (
                                (string) $line['picking_status']
                                !== 'ready'
                            ) {
                                throw new RuntimeException(
                                    'The delivery is no longer ready for Quick Sale confirmation.'
                                );
                            }

                            $currentAllocated = round(
                                (float) $line['reserved_quantity']
                                - (float) $line['completed_quantity'],
                                3
                            );

                            if (
                                abs(
                                    $currentAllocated
                                    - (float) $line['allocated_quantity']
                                ) > 0.0005
                            ) {
                                throw new RuntimeException(
                                    'The reserved delivery quantity changed after the DSA/DSP submitted the report.'
                                );
                            }
                        }
                    }

                    $inventory->completePicking(
                        $companyId,
                        $pickingId,
                        $picking['quantities'],
                        false,
                        $picking['completion_key'],
                        $actorId,
                        date('Y-m-d H:i:s')
                    );

                    continue;
                }

                $statusStatement = $connection->prepare(
                    "SELECT status
                     FROM inventory_pickings
                     WHERE company_id = :company_id
                       AND picking_id = :picking_id
                     LIMIT 1"
                );

                $statusStatement->execute([
                    'company_id' => $companyId,
                    'picking_id' => $pickingId,
                ]);

                $pickingStatus =
                    (string) $statusStatement->fetchColumn();

                if ($pickingStatus === 'cancelled') {
                    continue;
                }

                if ($pickingStatus !== 'ready') {
                    throw new RuntimeException(
                        'The all-return delivery is no longer available for cancellation.'
                    );
                }

                $inventory->cancelPicking(
                    $companyId,
                    $pickingId,
                    'Quick Sale report '
                        . $reportId
                        . ': all allocated quantity returned unsold.',
                    $actorId,
                    date('Y-m-d H:i:s')
                );
            }

            /*
             * Create Finance invoice only for sold quantity.
             * On recovery, the invoice discovered before inventory
             * finalization is reused instead of creating another.
             */
            if ($totalSold > 0.0005 && $invoiceId < 1) {
                $invoiceResult =
                    $this->salesService->createInvoice(
                        $orderId,
                        'delivered',
                        $actorId
                    );

                if (empty($invoiceResult['successful'])) {
                    $message =
                        (string) (
                            $invoiceResult['errors']['form']
                            ?? 'Customer invoice could not be created.'
                        );

                    throw new RuntimeException($message);
                }

                $invoiceId =
                    (int) ($invoiceResult['invoiceId'] ?? 0);

                if ($invoiceId < 1) {
                    throw new RuntimeException(
                        'Customer invoice could not be resolved.'
                    );
                }
            }

            /*
             * Final Quick Sale state change is one small atomic write.
             * If this fails, the next request recovers Inventory and
             * Finance through the keys / invoice lookup above.
             */
            $connection->beginTransaction();

            $finalSaleStatement = $connection->prepare(
                "SELECT
                    manager_user_id,
                    status
                 FROM sales_quick_sales
                 WHERE company_id = :company_id
                   AND quick_sale_id = :quick_sale_id
                 FOR UPDATE"
            );

            $finalSaleStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
            ]);

            $finalSale =
                $finalSaleStatement->fetch(\PDO::FETCH_ASSOC);

            $finalReportStatement = $connection->prepare(
                "SELECT
                    report_id,
                    status,
                    finance_invoice_id
                 FROM sales_quick_sale_reports
                 WHERE company_id = :company_id
                   AND quick_sale_id = :quick_sale_id
                   AND report_id = :report_id
                 FOR UPDATE"
            );

            $finalReportStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
                'report_id' => $reportId,
            ]);

            $finalReport =
                $finalReportStatement->fetch(\PDO::FETCH_ASSOC);

            if (
                is_array($finalSale)
                && is_array($finalReport)
                && (string) $finalSale['status'] === 'closed'
                && (string) $finalReport['status'] === 'confirmed'
            ) {
                $connection->commit();

                return [
                    'successful' => true,
                    'reportId' => $reportId,
                    'invoiceId' =>
                        (int) (
                            $finalReport['finance_invoice_id']
                            ?? $invoiceId
                        ),
                    'replayed' => true,
                ];
            }

            if (
                !is_array($finalSale)
                || (int) $finalSale['manager_user_id'] !== $actorId
                || (string) $finalSale['status'] !== 'reported'
            ) {
                throw new RuntimeException(
                    'Quick Sale status changed before confirmation completed.'
                );
            }

            if (
                !is_array($finalReport)
                || (string) $finalReport['status'] !== 'submitted'
            ) {
                throw new RuntimeException(
                    'Sales report status changed before confirmation completed.'
                );
            }

            $latestFinalStatement = $connection->prepare(
                "SELECT report_id
                 FROM sales_quick_sale_reports
                 WHERE company_id = :company_id
                   AND quick_sale_id = :quick_sale_id
                 ORDER BY report_id DESC
                 LIMIT 1
                 FOR UPDATE"
            );

            $latestFinalStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
            ]);

            if (
                (int) $latestFinalStatement->fetchColumn()
                !== $reportId
            ) {
                throw new RuntimeException(
                    'A newer sales report was submitted during confirmation.'
                );
            }

            $confirmReportStatement = $connection->prepare(
                "UPDATE sales_quick_sale_reports
                 SET status = 'confirmed',
                     reviewed_by_user_id = :actor_id,
                     reviewed_at = CURRENT_TIMESTAMP,
                     review_note = NULL,
                     finance_invoice_id = :invoice_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE company_id = :company_id
                   AND quick_sale_id = :quick_sale_id
                   AND report_id = :report_id
                   AND status = 'submitted'"
            );

            $confirmReportStatement->execute([
                'actor_id' => $actorId,
                'invoice_id' =>
                    $invoiceId > 0 ? $invoiceId : null,
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
                'report_id' => $reportId,
            ]);

            if ($confirmReportStatement->rowCount() !== 1) {
                throw new RuntimeException(
                    'Sales report could not be marked confirmed.'
                );
            }

            $closeStatement = $connection->prepare(
                "UPDATE sales_quick_sales
                 SET status = 'closed',
                     updated_at = CURRENT_TIMESTAMP
                 WHERE company_id = :company_id
                   AND quick_sale_id = :quick_sale_id
                   AND manager_user_id = :actor_id
                   AND status = 'reported'"
            );

            $closeStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
                'actor_id' => $actorId,
            ]);

            if ($closeStatement->rowCount() !== 1) {
                throw new RuntimeException(
                    'Quick Sale could not be closed.'
                );
            }

            $connection->commit();

            return [
                'successful' => true,
                'reportId' => $reportId,
                'invoiceId' => $invoiceId,
                'replayed' => false,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
        } finally {
            if ($lockHeld) {
                try {
                    $release = $connection->prepare(
                        'SELECT RELEASE_LOCK(:lock_name)'
                    );

                    $release->execute([
                        'lock_name' => $lockName,
                    ]);
                } catch (Throwable) {
                    /*
                     * Connection-level advisory locks are also released
                     * automatically when the connection closes.
                     */
                }
            }
        }
    }

    /** @return array<string,mixed> */
    public function requestReportCorrection(
        int $quickSaleId,
        int $reportId,
        int $actorId,
        string $reason
    ): array {
        $connection = \db();

        try {
            if (
                $quickSaleId < 1
                || $reportId < 1
                || $actorId < 1
            ) {
                throw new RuntimeException(
                    'The sales report is not available for review.'
                );
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw new RuntimeException(
                    'Enter the reason the DSA/DSP must correct the report.'
                );
            }

            if (mb_strlen($reason) > 5000) {
                throw new RuntimeException(
                    'Correction reason is too long.'
                );
            }

            $companyId = $this->tenant->companyId();

            $context = $this->managerTeams->reportingContext(
                $companyId,
                $actorId
            );

            $jobTitle = is_array($context)
                ? strtolower(
                    trim((string) ($context['job_title'] ?? ''))
                )
                : '';

            if ($jobTitle !== 'shop manager') {
                throw new RuntimeException(
                    'Only the assigned Shop Manager may return this report.'
                );
            }

            $connection->beginTransaction();

            $saleStatement = $connection->prepare(
                "SELECT
                    manager_user_id,
                    status
                 FROM sales_quick_sales
                 WHERE company_id = :company_id
                   AND quick_sale_id = :quick_sale_id
                 FOR UPDATE"
            );

            $saleStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
            ]);

            $sale = $saleStatement->fetch(\PDO::FETCH_ASSOC);

            if (
                !is_array($sale)
                || (int) $sale['manager_user_id'] !== $actorId
            ) {
                throw new RuntimeException(
                    'You are not the Shop Manager assigned to this Quick Sale.'
                );
            }

            if ((string) $sale['status'] !== 'reported') {
                throw new RuntimeException(
                    'This Quick Sale is not waiting for report review.'
                );
            }

            $reportStatement = $connection->prepare(
                "SELECT
                    report_id,
                    status
                 FROM sales_quick_sale_reports
                 WHERE company_id = :company_id
                   AND quick_sale_id = :quick_sale_id
                   AND report_id = :report_id
                 FOR UPDATE"
            );

            $reportStatement->execute([
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
                'report_id' => $reportId,
            ]);

            $report = $reportStatement->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($report)) {
                throw new RuntimeException(
                    'The submitted sales report could not be found.'
                );
            }

            if ((string) $report['status'] === 'correction_required') {
                $connection->commit();

                return [
                    'successful' => true,
                    'reportId' => $reportId,
                    'replayed' => true,
                ];
            }

            if ((string) $report['status'] !== 'submitted') {
                throw new RuntimeException(
                    'This report can no longer be returned for correction.'
                );
            }

            $update = $connection->prepare(
                "UPDATE sales_quick_sale_reports
                 SET status = 'correction_required',
                     reviewed_by_user_id = :actor_id,
                     reviewed_at = CURRENT_TIMESTAMP,
                     review_note = :review_note,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE company_id = :company_id
                   AND quick_sale_id = :quick_sale_id
                   AND report_id = :report_id
                   AND status = 'submitted'"
            );

            $update->execute([
                'actor_id' => $actorId,
                'review_note' => $reason,
                'company_id' => $companyId,
                'quick_sale_id' => $quickSaleId,
                'report_id' => $reportId,
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'The report changed while it was being reviewed.'
                );
            }

            $connection->commit();

            return [
                'successful' => true,
                'reportId' => $reportId,
                'replayed' => false,
            ];
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
        }
    }

    /** @return array<string,mixed>|null */
    public function reportEvidence(
        int $quickSaleId,
        int $reportId,
        int $actorId,
        bool $privilegedReviewer = false
    ): ?array {
        if ($quickSaleId < 1 || $reportId < 1 || $actorId < 1) {
            return null;
        }

        /*
         * Reuse Quick Sale detail authorization so evidence is visible
         * only to the DSA/DSP owner or the assigned Shop Manager.
         */
        $detail = $this->detail(
            $quickSaleId,
            $actorId,
            $privilegedReviewer
        );

        if (empty($detail['successful'])) {
            return null;
        }

        if (
            empty($detail['isOwner'])
            && empty($detail['isManager'])
            && empty($detail['isAuthorizedReviewer'])
        ) {
            return null;
        }

        $companyId = $this->tenant->companyId();

        $statement = \db()->prepare(
            "SELECT
                report_id,
                evidence_path,
                evidence_original_name,
                evidence_mime,
                evidence_size
             FROM sales_quick_sale_reports
             WHERE company_id = :company_id
               AND quick_sale_id = :quick_sale_id
               AND report_id = :report_id
               AND evidence_path IS NOT NULL
               AND evidence_path <> ''
             LIMIT 1"
        );

        $statement->execute([
            'company_id' => $companyId,
            'quick_sale_id' => $quickSaleId,
            'report_id' => $reportId,
        ]);

        $evidence = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($evidence)) {
            return null;
        }

        $path = (string) ($evidence['evidence_path'] ?? '');

        if ($path === '' || !is_file($path)) {
            return null;
        }

        return $evidence;
    }

    /** @return array<string,mixed> */
    public function detail(
        int $quickSaleId,
        int $actorId,
        bool $privilegedReviewer = false
    ): array {
        try {
            $companyId = $this->tenant->companyId();

            $row = $this->quickSaleRecord(
                $companyId,
                $quickSaleId
            );

            if ($row === null) {
                return [
                    'successful' => false,
                    'notFound' => true,
                ];
            }

            $context = $this->managerTeams->reportingContext(
                $companyId,
                $actorId
            );

            if (!is_array($context)) {
                return [
                    'successful' => false,
                    'notFound' => true,
                ];
            }

            $jobTitle = strtolower(
                trim((string) ($context['job_title'] ?? ''))
            );

            $isOwner =
                (int) $row['user_id'] === $actorId
                && in_array($jobTitle, ['dsa', 'dsp'], true);

            $isManager =
                (int) $row['manager_user_id'] === $actorId
                && $jobTitle === 'shop manager';

            if (
                !$isOwner
                && !$isManager
                && !$privilegedReviewer
            ) {
                return [
                    'successful' => false,
                    'notFound' => true,
                ];
            }

            $quotation = $this->sales->quotation(
                $companyId,
                (int) $row['quotation_id']
            );

            if (!is_array($quotation)) {
                return [
                    'successful' => false,
                    'notFound' => true,
                ];
            }

            $locations = [];
            $availability = [];

            if ($isManager && $row['status'] === 'submitted') {
                foreach (
                    $this->operationalAccess->locationsForUser(
                        $companyId,
                        $actorId
                    ) as $location
                ) {
                    if (
                        (int) ($location['warehouse_id'] ?? 0)
                        === (int) $row['warehouse_id']
                    ) {
                        $locations[] = $location;
                    }
                }

                $productIds = array_values(array_unique(
                    array_map(
                        static fn (array $line): int =>
                            (int) ($line['product_id'] ?? 0),
                        (array) ($quotation['lines'] ?? [])
                    )
                ));

                foreach ($locations as $location) {
                    $rows = $this->operationalAccess->availability(
                        $companyId,
                        $actorId,
                        (int) $row['warehouse_id'],
                        (int) $location['location_id'],
                        $productIds
                    );

                    foreach ($rows as $stock) {
                        $availability[] = $stock + [
                            'warehouse_id' =>
                                (int) $row['warehouse_id'],
                            'location_id' =>
                                (int) $location['location_id'],
                            'location_name' =>
                                (string) (
                                    $location['name']
                                    ?? $location['code']
                                    ?? ''
                                ),
                        ];
                    }
                }
            }

            $reportLines = [];
            $managerReport = null;
            $managerReportLines = [];

            if (
                $isOwner
                && in_array(
                    (string) $row['status'],
                    ['allocated', 'reported'],
                    true
                )
            ) {
                $orderId = (int) ($row['sales_order_id'] ?? 0);

                if ($orderId > 0) {
                    $reportStatement = \db()->prepare(
                        "SELECT
                            p.picking_id,
                            p.picking_number,
                            pl.picking_line_id,
                            pl.product_id,
                            sp.sku,
                            sp.name AS product_name,
                            pl.requested_quantity,
                            pl.reserved_quantity,
                            pl.completed_quantity,
                            pl.returned_quantity
                         FROM inventory_pickings p
                         INNER JOIN inventory_picking_lines pl
                           ON pl.company_id = p.company_id
                          AND pl.picking_id = p.picking_id
                         INNER JOIN sales_products sp
                           ON sp.company_id = pl.company_id
                          AND sp.product_id = pl.product_id
                         WHERE p.company_id = :company_id
                           AND p.sales_order_id = :order_id
                           AND p.picking_type = 'delivery'
                           AND p.status = 'ready'
                           AND pl.status <> 'cancelled'
                         ORDER BY pl.picking_line_id"
                    );

                    $reportStatement->execute([
                        'company_id' => $companyId,
                        'order_id' => $orderId,
                    ]);

                    $reportLines =
                        $reportStatement->fetchAll(\PDO::FETCH_ASSOC);
                }
            }

            /*
             * A reported Quick Sale is reviewed only by its
             * assigned Shop Manager. Report quantities are read
             * from the immutable submitted report rows rather than
             * from browser data or a reconstructed client state.
             */
            if (
                (
                    $isOwner
                    || $isManager
                    || $privilegedReviewer
                )
                && (string) $row['status'] === 'reported'
            ) {
                $managerReportStatement = \db()->prepare(
                    "SELECT
                        r.*,
                        reporter.display_name AS reported_by_name
                     FROM sales_quick_sale_reports r
                     LEFT JOIN users reporter
                       ON reporter.user_id = r.reported_by_user_id
                     WHERE r.company_id = :company_id
                       AND r.quick_sale_id = :quick_sale_id
                     ORDER BY r.report_id DESC
                     LIMIT 1"
                );

                $managerReportStatement->execute([
                    'company_id' => $companyId,
                    'quick_sale_id' => $quickSaleId,
                ]);

                $reportRow =
                    $managerReportStatement->fetch(\PDO::FETCH_ASSOC);

                if (is_array($reportRow)) {
                    $managerReport = $reportRow;

                    $managerLineStatement = \db()->prepare(
                        "SELECT
                            rl.report_line_id,
                            rl.picking_line_id,
                            rl.product_id,
                            rl.allocated_quantity,
                            rl.sold_quantity,
                            rl.returned_quantity,
                            sp.sku,
                            sp.name AS product_name
                         FROM sales_quick_sale_report_lines rl
                         INNER JOIN sales_products sp
                           ON sp.company_id = rl.company_id
                          AND sp.product_id = rl.product_id
                         WHERE rl.company_id = :company_id
                           AND rl.report_id = :report_id
                         ORDER BY rl.report_line_id"
                    );

                    $managerLineStatement->execute([
                        'company_id' => $companyId,
                        'report_id' =>
                            (int) $managerReport['report_id'],
                    ]);

                    $managerReportLines =
                        $managerLineStatement->fetchAll(
                            \PDO::FETCH_ASSOC
                        );
                }
            }
            return [
                'successful' => true,
                'quickSale' => $row,
                'quotation' => $quotation,
                'actor' => $context,
                'isOwner' => $isOwner,
                'isManager' => $isManager,
                'isAuthorizedReviewer' => $privilegedReviewer,
                'canConfirm' =>
                    $isManager
                    && $row['status'] === 'submitted',
                'canReport' =>
                    $isOwner
                    && $reportLines !== []
                    && (
                        $row['status'] === 'allocated'
                        || (
                            $row['status'] === 'reported'
                            && is_array($managerReport)
                            && (string) (
                                $managerReport['status']
                                ?? ''
                            ) === 'correction_required'
                        )
                    ),
                'reportLines' => $reportLines,
                'canViewReport' =>
                    (
                        $isManager
                        || $privilegedReviewer
                    )
                    && $row['status'] === 'reported'
                    && is_array($managerReport)
                    && $managerReportLines !== [],
                'canReviewReport' =>
                    $isManager
                    && $row['status'] === 'reported'
                    && is_array($managerReport)
                    && (string) (
                        $managerReport['status']
                        ?? ''
                    ) === 'submitted'
                    && $managerReportLines !== [],
                'managerReport' => $managerReport,
                'managerReportLines' => $managerReportLines,
                'locations' => $locations,
                'availability' => $availability,
            ];
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function confirm(
        int $quickSaleId,
        int $actorId,
        array $input
    ): array {
        try {
            $companyId = $this->tenant->companyId();

            $row = $this->quickSaleRecord(
                $companyId,
                $quickSaleId
            );

            if ($row === null) {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' => 'Quick Sale was not found.',
                    ],
                ];
            }

            $context = $this->managerTeams->reportingContext(
                $companyId,
                $actorId
            );

            if (!is_array($context)) {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'An active employee account is required.',
                    ],
                ];
            }

            $jobTitle = strtolower(
                trim((string) ($context['job_title'] ?? ''))
            );

            if ($jobTitle !== 'shop manager') {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'Only the assigned Shop Manager may confirm this Quick Sale.',
                    ],
                ];
            }

            if ((int) $row['manager_user_id'] !== $actorId) {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'You are not the Shop Manager for this Quick Sale.',
                    ],
                ];
            }

            if ((string) $row['status'] === 'allocated') {
                return [
                    'successful' => true,
                    'id' => $quickSaleId,
                    'quotationId' =>
                        (int) $row['quotation_id'],
                    'orderId' =>
                        (int) ($row['sales_order_id'] ?? 0),
                    'replayed' => true,
                ];
            }

            if ((string) $row['status'] !== 'submitted') {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'This Quick Sale is no longer waiting for confirmation.',
                    ],
                ];
            }

            $quotation = $this->sales->quotation(
                $companyId,
                (int) $row['quotation_id']
            );

            if (!is_array($quotation)) {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'The linked quotation could not be found.',
                    ],
                ];
            }

            $sourceLocationId =
                (int) ($input['source_location_id'] ?? 0);

            if ($sourceLocationId <= 0) {
                return [
                    'successful' => false,
                    'errors' => [
                        'source_location_id' =>
                            'Select the shop stock location.',
                    ],
                ];
            }

            $warehouseId =
                (int) $row['warehouse_id'];

            /*
             * Warehouse is authoritative Quick Sale metadata.
             * It is never accepted from browser POST data.
             */
            $this->operationalAccess->assertAuthorizedSource(
                $companyId,
                $actorId,
                $warehouseId,
                $sourceLocationId
            );

            $quotationStatus =
                (string) ($quotation['status'] ?? '');

            $orderId =
                (int) (
                    $quotation['sales_order_id']
                    ?? $row['sales_order_id']
                    ?? 0
                );

            /*
             * A retry may arrive after quotation confirmation but
             * before order allocation completed. In that case,
             * pricing is already final and we resume from the order.
             */
            if (in_array(
                $quotationStatus,
                ['draft', 'sent'],
                true
            )) {
                $discounts = is_array(
                    $input['discount_amount'] ?? null
                )
                    ? $input['discount_amount']
                    : [];

                $taxRates = is_array(
                    $input['tax_rate'] ?? null
                )
                    ? $input['tax_rate']
                    : [];

                $lines = [];

                foreach (
                    (array) ($quotation['lines'] ?? [])
                    as $index => $line
                ) {
                    $discountRaw =
                        $discounts[$index] ?? '0';

                    $taxRaw =
                        $taxRates[$index] ?? '0';

                    if (!is_numeric($discountRaw)) {
                        return [
                            'successful' => false,
                            'errors' => [
                                'line_' . ($index + 1) =>
                                    'Enter a valid discount.',
                            ],
                        ];
                    }

                    if (!is_numeric($taxRaw)) {
                        return [
                            'successful' => false,
                            'errors' => [
                                'line_' . ($index + 1) =>
                                    'Enter a valid tax value.',
                            ],
                        ];
                    }

                    $discount = round(
                        (float) $discountRaw,
                        2
                    );

                    $taxRate = round(
                        (float) $taxRaw,
                        4
                    );

                    $gross =
                        (float) ($line['quantity'] ?? 0)
                        * (float) ($line['unit_price'] ?? 0);

                    if ($discount < 0 || $discount > $gross) {
                        return [
                            'successful' => false,
                            'errors' => [
                                'line_' . ($index + 1) =>
                                    'Discount is outside the allowed range.',
                            ],
                        ];
                    }

                    if ($taxRate < 0 || $taxRate > 100) {
                        return [
                            'successful' => false,
                            'errors' => [
                                'line_' . ($index + 1) =>
                                    'Tax is outside the allowed range.',
                            ],
                        ];
                    }

                    /*
                     * Product and quantity are never trusted from
                     * manager POST data.
                     */
                    $lines[] = [
                        'product_id' =>
                            (int) $line['product_id'],
                        'quantity' =>
                            (float) $line['quantity'],
                        'discount_amount' => $discount,
                        'tax_rate' => $taxRate,
                    ];
                }

                $updateResult =
                    $this->salesService
                        ->updateQuickSaleQuotation(
                            (int) $row['quotation_id'],
                            [
                                'customer_id' =>
                                    (int) $quotation['customer_id'],
                                'agent_id' =>
                                    $quotation['agent_id'],
                                'team_id' =>
                                    $quotation['team_id'],
                                'pricelist_id' =>
                                    $quotation['pricelist_id'],
                                'quotation_date' =>
                                    $quotation['quotation_date'],
                                'expiration_date' =>
                                    $quotation['expiration_date'],
                                'payment_terms_days' =>
                                    (int) $quotation['payment_terms_days'],
                                'currency' =>
                                    $quotation['currency'],
                                'billing_address' =>
                                    $quotation['billing_address'],
                                'delivery_address' =>
                                    $quotation['delivery_address'],
                                'notes' =>
                                    $quotation['notes'],
                                'lines' => $lines,
                            ],
                            $actorId
                        );

                if (empty($updateResult['successful'])) {
                    return $updateResult;
                }

                $quotationConfirm =
                    $this->salesService->transitionQuotation(
                        (int) $row['quotation_id'],
                        'confirm',
                        $actorId,
                        [
                            'warehouse_id' => $warehouseId,
                            'source_location_id' =>
                                $sourceLocationId,
                        ]
                    );

                if (empty($quotationConfirm['successful'])) {
                    return $quotationConfirm;
                }

                $orderId =
                    (int) ($quotationConfirm['orderId'] ?? 0);
            } elseif ($quotationStatus !== 'confirmed') {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'The linked quotation is not in a confirmable state.',
                    ],
                ];
            }

            if ($orderId <= 0) {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'The Quick Sale Sales Order was not created.',
                    ],
                ];
            }

            $allocation =
                $this->allocateQuickSaleOrder(
                    $companyId,
                    $quickSaleId,
                    $orderId,
                    $actorId,
                    $warehouseId,
                    $sourceLocationId
                );

            if (empty($allocation['successful'])) {
                return $allocation;
            }

            /*
             * This is deliberately last. If anything before this
             * fails, Quick Sale remains submitted and a retry can
             * resume safely from the persisted quotation/order state.
             */
            $this->setQuickSaleStatusById(
                $companyId,
                $quickSaleId,
                'allocated'
            );

            return [
                'successful' => true,
                'id' => $quickSaleId,
                'quotationId' =>
                    (int) $row['quotation_id'],
                'orderId' => $orderId,
                'inventoryReserved' =>
                    $allocation['inventoryReserved']
                    ?? false,
                'pickingId' =>
                    $allocation['pickingId']
                    ?? null,
            ];
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * Complete the existing Sales Order lifecycle behind the
     * manager's single Quick Sale confirmation button.
     *
     * Normal Sales keeps its normal submit/approve/confirm UI.
     *
     * @return array<string,mixed>
     */
    private function allocateQuickSaleOrder(
        int $companyId,
        int $quickSaleId,
        int $orderId,
        int $actorId,
        int $warehouseId,
        int $sourceLocationId
    ): array {
        $order = $this->sales->orderDetail(
            $companyId,
            $orderId
        );

        if (!is_array($order)) {
            return [
                'successful' => false,
                'errors' => [
                    'form' => 'The Quick Sale Sales Order was not found.',
                ],
            ];
        }

        $status = (string) ($order['status'] ?? '');

        if ($status === 'submitted') {
            $approve =
                $this->salesService->transitionOrder(
                    $orderId,
                    'approve',
                    null,
                    $actorId,
                    'quick-sale-' . $quickSaleId . '-approve'
                );

            if (empty($approve['successful'])) {
                return $approve;
            }

            $status = 'approved';
        }

        /*
         * A confirmed order means the existing confirm transaction
         * completed successfully, including reservation/delivery
         * preparation. This supports retry after only the final
         * Quick Sale status write failed.
         */
        if ($status === 'confirmed') {
            return [
                'successful' => true,
                'orderId' => $orderId,
                'replayed' => true,
            ];
        }

        if ($status !== 'approved') {
            return [
                'successful' => false,
                'errors' => [
                    'form' =>
                        'The Quick Sale Sales Order is not ready for allocation.',
                ],
            ];
        }

        return $this->salesService->transitionOrder(
            $orderId,
            'confirm',
            null,
            $actorId,
            'quick-sale-' . $quickSaleId . '-confirm',
            $warehouseId,
            $sourceLocationId
        );
    }
    /** @return list<array<string,mixed>> */
    private function dsaTaskQueue(
        int $companyId,
        int $userId
    ): array {
        $statement = \db()->prepare(
            'SELECT
                qs.quick_sale_id,
                qs.quotation_id,
                qs.status,
                qs.created_at,
                qs.updated_at,
                q.quotation_number,
                q.total_amount,
                q.currency
             FROM sales_quick_sales qs
             INNER JOIN sales_quotations q
               ON q.company_id = qs.company_id
              AND q.quotation_id = qs.quotation_id
             WHERE qs.company_id = :company_id
               AND qs.user_id = :user_id
               AND qs.status IN (
                   \'submitted\',
                   \'allocated\',
                   \'reported\',
                   \'return_requested\'
               )
             ORDER BY
                qs.updated_at DESC,
                qs.quick_sale_id DESC
             LIMIT 20'
        );

        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows)
            ? array_values($rows)
            : [];
    }
    /** @return list<array<string,mixed>> */
    private function quickSaleHistory(
        int $companyId,
        int $actorId,
        bool $managerMode
    ): array {
        $scope =
            $managerMode
                ? 'qs.manager_user_id = :actor_id'
                : 'qs.user_id = :actor_id';

        $statement = \db()->prepare(
            "SELECT
                qs.quick_sale_id,
                qs.status,
                qs.updated_at AS closed_at,
                q.quotation_number,
                q.currency,
                a.name AS agent_name,
                t.name AS team_name,
                w.name AS warehouse_name,
                manager.display_name AS manager_name,
                r.report_id,
                r.invoice_reference,
                r.reviewed_at,
                r.finance_invoice_id,
                COALESCE(
                    SUM(rl.sold_quantity),
                    0
                ) AS sold_quantity,
                COALESCE(
                    SUM(rl.returned_quantity),
                    0
                ) AS returned_quantity
             FROM sales_quick_sales qs
             INNER JOIN sales_quotations q
               ON q.company_id = qs.company_id
              AND q.quotation_id = qs.quotation_id
             INNER JOIN sales_agents a
               ON a.company_id = qs.company_id
              AND a.agent_id = qs.agent_id
             INNER JOIN sales_teams t
               ON t.company_id = qs.company_id
              AND t.team_id = qs.team_id
             INNER JOIN inventory_warehouses w
               ON w.company_id = qs.company_id
              AND w.warehouse_id = qs.warehouse_id
             INNER JOIN users manager
               ON manager.user_id = qs.manager_user_id
             LEFT JOIN sales_quick_sale_reports r
               ON r.company_id = qs.company_id
              AND r.quick_sale_id = qs.quick_sale_id
              AND r.status = 'confirmed'
             LEFT JOIN sales_quick_sale_report_lines rl
               ON rl.company_id = r.company_id
              AND rl.report_id = r.report_id
             WHERE qs.company_id = :company_id
               AND {$scope}
               AND qs.status = 'closed'
             GROUP BY
                qs.quick_sale_id,
                qs.status,
                qs.updated_at,
                q.quotation_number,
                q.currency,
                a.name,
                t.name,
                w.name,
                manager.display_name,
                r.report_id,
                r.invoice_reference,
                r.reviewed_at,
                r.finance_invoice_id
             ORDER BY
                COALESCE(r.reviewed_at, qs.updated_at) DESC,
                qs.quick_sale_id DESC
             LIMIT 30"
        );

        $statement->execute([
            'company_id' => $companyId,
            'actor_id' => $actorId,
        ]);

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows)
            ? array_values($rows)
            : [];
    }

    /** @return list<array<string,mixed>> */
    private function managerWaitingQueue(
        int $companyId,
        int $managerUserId
    ): array {
        $statement = \db()->prepare(
            "SELECT
                qs.quick_sale_id,
                qs.quotation_id,
                qs.status,
                qs.updated_at,
                q.quotation_number,
                q.total_amount,
                q.currency,
                a.name AS agent_name,
                t.name AS team_name,
                w.name AS warehouse_name,
                latest_report.report_id,
                latest_report.review_note,
                latest_report.reviewed_at
             FROM sales_quick_sales qs
             INNER JOIN sales_quotations q
               ON q.company_id = qs.company_id
              AND q.quotation_id = qs.quotation_id
             INNER JOIN sales_agents a
               ON a.company_id = qs.company_id
              AND a.agent_id = qs.agent_id
             INNER JOIN sales_teams t
               ON t.company_id = qs.company_id
              AND t.team_id = qs.team_id
             INNER JOIN inventory_warehouses w
               ON w.company_id = qs.company_id
              AND w.warehouse_id = qs.warehouse_id
             INNER JOIN sales_quick_sale_reports latest_report
               ON latest_report.company_id = qs.company_id
              AND latest_report.quick_sale_id = qs.quick_sale_id
              AND latest_report.report_id = (
                  SELECT MAX(report_scan.report_id)
                  FROM sales_quick_sale_reports report_scan
                  WHERE report_scan.company_id = qs.company_id
                    AND report_scan.quick_sale_id = qs.quick_sale_id
              )
             WHERE qs.company_id = :company_id
               AND qs.manager_user_id = :manager_user_id
               AND qs.status = 'reported'
               AND latest_report.status = 'correction_required'
             ORDER BY
                latest_report.reviewed_at DESC,
                qs.quick_sale_id DESC"
        );

        $statement->execute([
            'company_id' => $companyId,
            'manager_user_id' => $managerUserId,
        ]);

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows)
            ? array_values($rows)
            : [];
    }

    /** @return list<array<string,mixed>> */
    private function managerQueue(
        int $companyId,
        int $managerUserId
    ): array {
        $statement = \db()->prepare(
            'SELECT
                qs.quick_sale_id,
                qs.quotation_id,
                qs.status,
                qs.created_at,
                q.quotation_number,
                q.total_amount,
                q.currency,
                a.name AS agent_name,
                t.name AS team_name,
                w.name AS warehouse_name
             FROM sales_quick_sales qs
             INNER JOIN sales_quotations q
               ON q.company_id = qs.company_id
              AND q.quotation_id = qs.quotation_id
             INNER JOIN sales_agents a
               ON a.company_id = qs.company_id
              AND a.agent_id = qs.agent_id
             INNER JOIN sales_teams t
               ON t.company_id = qs.company_id
              AND t.team_id = qs.team_id
             INNER JOIN inventory_warehouses w
               ON w.company_id = qs.company_id
              AND w.warehouse_id = qs.warehouse_id
             WHERE qs.company_id = :company_id
               AND qs.manager_user_id = :manager_user_id
               AND (
                    qs.status = \'submitted\'
                    OR (
                        qs.status = \'reported\'
                        AND EXISTS (
                            SELECT 1
                            FROM sales_quick_sale_reports latest_report
                            WHERE latest_report.company_id = qs.company_id
                              AND latest_report.quick_sale_id = qs.quick_sale_id
                              AND latest_report.report_id = (
                                  SELECT MAX(report_scan.report_id)
                                  FROM sales_quick_sale_reports report_scan
                                  WHERE report_scan.company_id = qs.company_id
                                    AND report_scan.quick_sale_id = qs.quick_sale_id
                              )
                              AND latest_report.status = \'submitted\'
                        )
                    )
               )
             ORDER BY
                CASE WHEN qs.status = \'submitted\' THEN 0 ELSE 1 END,
                qs.created_at DESC,
                qs.quick_sale_id DESC'
        );

        $statement->execute([
            'company_id' => $companyId,
            'manager_user_id' => $managerUserId,
        ]);

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows)
            ? $rows
            : [];
    }

    /** @return array<string,mixed>|null */
    private function quickSaleRecord(
        int $companyId,
        int $quickSaleId
    ): ?array {
        $statement = \db()->prepare(
            'SELECT
                qs.*,
                q.quotation_number,
                q.sales_order_id,
                q.currency,
                q.total_amount,
                a.name AS agent_name,
                t.name AS team_name,
                w.code AS warehouse_code,
                w.name AS warehouse_name,
                w.address AS warehouse_address,
                w.phone AS warehouse_phone,
                w.email AS warehouse_email,
                manager.display_name AS manager_name
             FROM sales_quick_sales qs
             INNER JOIN sales_quotations q
               ON q.company_id = qs.company_id
              AND q.quotation_id = qs.quotation_id
             INNER JOIN sales_agents a
               ON a.company_id = qs.company_id
              AND a.agent_id = qs.agent_id
             INNER JOIN sales_teams t
               ON t.company_id = qs.company_id
              AND t.team_id = qs.team_id
             INNER JOIN inventory_warehouses w
               ON w.company_id = qs.company_id
              AND w.warehouse_id = qs.warehouse_id
             INNER JOIN users manager
               ON manager.user_id = qs.manager_user_id
             WHERE qs.company_id = :company_id
               AND qs.quick_sale_id = :quick_sale_id
             LIMIT 1'
        );

        $statement->execute([
            'company_id' => $companyId,
            'quick_sale_id' => $quickSaleId,
        ]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row)
            ? $row
            : null;
    }

    private function setQuickSaleStatusById(
        int $companyId,
        int $quickSaleId,
        string $status
    ): void {
        $statement = \db()->prepare(
            'UPDATE sales_quick_sales
             SET status = :status
             WHERE company_id = :company_id
               AND quick_sale_id = :quick_sale_id'
        );

        $statement->execute([
            'status' => $status,
            'company_id' => $companyId,
            'quick_sale_id' => $quickSaleId,
        ]);
    }
    /** @return array<string,mixed> */
    private function resolveActor(
        int $companyId,
        int $actorId
    ): array {
        $context =
            $this->managerTeams->reportingContext($companyId, $actorId);

        if (!is_array($context)) {
            throw new RuntimeException(
                'An active company employee account is required.'
            );
        }

        $jobTitle = strtolower(
            trim((string) ($context['job_title'] ?? ''))
        );

        if (!in_array($jobTitle, ['dsa', 'dsp'], true)) {
            throw new RuntimeException(
                'Quick Sale is available only to DSA/DSP users.'
            );
        }

        if ((int) ($context['employee_id'] ?? 0) <= 0) {
            throw new RuntimeException(
                'Your employee profile is not linked correctly.'
            );
        }

        if ((int) ($context['manager_user_id'] ?? 0) <= 0) {
            throw new RuntimeException(
                'Your Shop Manager is not assigned.'
            );
        }

        if (
            strtolower(
                trim((string) ($context['manager_job_title'] ?? ''))
            ) !== 'shop manager'
        ) {
            throw new RuntimeException(
                'Your reporting manager must be a Shop Manager.'
            );
        }

        return $context;
    }

    /**
     * @param array<string,mixed> $actor
     * @return array{
     *   agent:array<string,mixed>,
     *   team:array<string,mixed>
     * }
     */
    private function resolveSalesContext(
        int $companyId,
        int $actorId,
        array $actor
    ): array {
        $employeeId = (int) $actor['employee_id'];
        $matches = [];

        foreach ($this->sales->teams($companyId) as $team) {
            if (empty($team['active'])) {
                continue;
            }

            $teamId = (int) ($team['team_id'] ?? 0);
            if ($teamId <= 0) {
                continue;
            }

            $detail = $this->sales->team($companyId, $teamId);
            if (!is_array($detail)) {
                continue;
            }

            foreach ((array) ($detail['members'] ?? []) as $member) {
                $memberUserId = (int) ($member['user_id'] ?? 0);
                $memberEmployeeId = (int) ($member['employee_id'] ?? 0);

                if (
                    $memberUserId === $actorId
                    || $memberEmployeeId === $employeeId
                ) {
                    $matches[] = [
                        'agent' => $member,
                        'team' => $detail,
                    ];
                    break;
                }
            }
        }

        if (count($matches) !== 1) {
            throw new RuntimeException(
                count($matches) === 0
                    ? 'You are not assigned to an active Sales Team.'
                    : 'You are assigned to more than one Sales Team.'
            );
        }

        $agentType = strtoupper(
            trim((string) ($matches[0]['agent']['agent_type'] ?? ''))
        );

        if (!in_array($agentType, ['DSA', 'DSP'], true)) {
            throw new RuntimeException(
                'Your Sales Agent record is not a DSA/DSP.'
            );
        }

        return $matches[0];
    }

    /** @return array<string,mixed> */
    private function resolveShopWarehouse(
        int $companyId,
        int $actorId
    ): array {
        $statement = \db()->prepare(
            'SELECT DISTINCT
                w.warehouse_id,
                w.code,
                w.name,
                w.address,
                w.phone,
                w.email
             FROM inventory_user_warehouse_access access
             INNER JOIN inventory_warehouses w
               ON w.company_id = access.company_id
              AND w.warehouse_id = access.warehouse_id
             WHERE access.company_id = :company_id
               AND access.user_id = :user_id
               AND access.active = TRUE
               AND w.active = TRUE
               AND w.deleted_at IS NULL
             ORDER BY w.warehouse_id'
        );

        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $actorId,
        ]);

        $warehouses = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if (count($warehouses) !== 1) {
            throw new RuntimeException(
                count($warehouses) === 0
                    ? 'Your shop warehouse is not assigned.'
                    : 'Your account has more than one active shop warehouse. Quick Sale will not guess which shop to use.'
            );
        }

        return $warehouses[0];
    }

    /**
     * @param list<array{product_id:int,quantity:float}> $lines
     * @param array<int,array<string,mixed>> $products
     * @return array<string,mixed>|null
     */
    private function resolveAutomaticPricelist(
        int $companyId,
        array $lines,
        array $products,
        string $date
    ): ?array {
        $best = null;

        foreach ($this->sales->pricelists($companyId) as $summary) {
            if (empty($summary['active'])) {
                continue;
            }

            if (
                !empty($summary['valid_from'])
                && (string) $summary['valid_from'] > $date
            ) {
                continue;
            }

            if (
                !empty($summary['valid_to'])
                && (string) $summary['valid_to'] < $date
            ) {
                continue;
            }

            $pricelistId = (int) ($summary['pricelist_id'] ?? 0);
            if ($pricelistId <= 0) {
                continue;
            }

            $detail = $this->sales->pricelist(
                $companyId,
                $pricelistId
            );

            if (!is_array($detail)) {
                continue;
            }

            $coverage = 0;
            $specificity = 0;
            $minimumQuantityScore = 0.0;
            $priorityScore = 0;

            foreach ($lines as $line) {
                $product =
                    $products[(int) $line['product_id']] ?? null;

                if (!is_array($product)) {
                    continue;
                }

                $rule = $this->bestRuleForProduct(
                    (array) ($detail['rules'] ?? []),
                    $product,
                    (float) $line['quantity'],
                    $date
                );

                if ($rule === null) {
                    continue;
                }

                /*
                 * Quick Sale must never automatically select a
                 * pricelist rule that resolves the product to a
                 * zero or negative selling price. In that case the
                 * product's normal base price remains authoritative.
                 */
                $resolvedPrice = $this->sales->resolvePrice(
                    $companyId,
                    $pricelistId,
                    (int) $line['product_id'],
                    (float) $line['quantity'],
                    $date,
                    (float) ($product['unit_price'] ?? 0)
                );

                if ($resolvedPrice <= 0) {
                    continue;
                }

                $coverage++;
                $specificity += (int) $rule['_specificity'];
                $minimumQuantityScore +=
                    (float) ($rule['minimum_quantity'] ?? 0);
                $priorityScore +=
                    (int) ($rule['priority'] ?? 100);
            }

            if ($coverage === 0) {
                continue;
            }

            $candidate = [
                'pricelist_id' => $pricelistId,
                'currency' => $detail['currency'] ?? 'ETB',
                '_coverage' => $coverage,
                '_specificity' => $specificity,
                '_minimum' => $minimumQuantityScore,
                '_priority' => $priorityScore,
            ];

            if (
                $best === null
                || $this->isBetterPricelist($candidate, $best)
            ) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @param array<string,mixed> $product
     * @return array<string,mixed>|null
     */
    private function bestRuleForProduct(
        array $rules,
        array $product,
        float $quantity,
        string $date
    ): ?array {
        $best = null;
        $productId = (int) ($product['product_id'] ?? 0);
        $category = trim((string) ($product['category'] ?? ''));

        foreach ($rules as $rule) {
            if (!is_array($rule) || empty($rule['active'])) {
                continue;
            }

            if ((float) ($rule['minimum_quantity'] ?? 1) > $quantity) {
                continue;
            }

            if (
                !empty($rule['valid_from'])
                && (string) $rule['valid_from'] > $date
            ) {
                continue;
            }

            if (
                !empty($rule['valid_to'])
                && (string) $rule['valid_to'] < $date
            ) {
                continue;
            }

            $ruleProductId = (int) ($rule['product_id'] ?? 0);
            $ruleCategory =
                trim((string) ($rule['category'] ?? ''));

            if ($ruleProductId > 0) {
                if ($ruleProductId !== $productId) {
                    continue;
                }

                $specificity = 3;
            } elseif ($ruleCategory !== '') {
                if (strcasecmp($ruleCategory, $category) !== 0) {
                    continue;
                }

                $specificity = 2;
            } else {
                $specificity = 1;
            }

            $candidate = $rule;
            $candidate['_specificity'] = $specificity;

            if (
                $best === null
                || $this->isBetterRule($candidate, $best)
            ) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $current
     */
    private function isBetterRule(
        array $candidate,
        array $current
    ): bool {
        if (
            (int) $candidate['_specificity']
            !== (int) $current['_specificity']
        ) {
            return (int) $candidate['_specificity']
                > (int) $current['_specificity'];
        }

        if (
            (float) $candidate['minimum_quantity']
            !== (float) $current['minimum_quantity']
        ) {
            return (float) $candidate['minimum_quantity']
                > (float) $current['minimum_quantity'];
        }

        if (
            (int) $candidate['priority']
            !== (int) $current['priority']
        ) {
            return (int) $candidate['priority']
                < (int) $current['priority'];
        }

        return (int) $candidate['rule_id']
            < (int) $current['rule_id'];
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $current
     */
    private function isBetterPricelist(
        array $candidate,
        array $current
    ): bool {
        foreach (
            [
                '_coverage',
                '_specificity',
                '_minimum',
            ] as $descending
        ) {
            if ($candidate[$descending] !== $current[$descending]) {
                return $candidate[$descending]
                    > $current[$descending];
            }
        }

        if (
            (int) $candidate['_priority']
            !== (int) $current['_priority']
        ) {
            return (int) $candidate['_priority']
                < (int) $current['_priority'];
        }

        return (int) $candidate['pricelist_id']
            < (int) $current['pricelist_id'];
    }

    private function ensureTechnicalCustomer(
        int $companyId,
        int $actorId,
        string $currency
    ): int {
        foreach ($this->sales->customers($companyId) as $customer) {
            if (
                strtoupper(
                    trim((string) ($customer['customer_number'] ?? ''))
                ) === 'QUICK-SALE'
            ) {
                if (empty($customer['active'])) {
                    throw new RuntimeException(
                        'The reserved Quick Sale customer is inactive.'
                    );
                }

                return (int) $customer['customer_id'];
            }
        }

        $values = [
            'territory_id' => null,
            'agent_id' => null,
            'pricelist_id' => null,
            'team_id' => null,
            'customer_number' => 'QUICK-SALE',
            'name' => 'Quick Sale / Walk-in Customer',
            'legal_name' => null,
            'customer_type' => 'individual',
            'tax_number' => null,
            'email' => null,
            'phone' => null,
            'mobile' => null,
            'address' => null,
            'street' => null,
            'street2' => null,
            'city' => null,
            'state_region' => null,
            'postal_code' => null,
            'country' => null,
            'preferred_currency' => $currency,
            'credit_mode' => 'unlimited',
            'credit_limit' => 0,
            'credit_status' => 'active',
            'payment_terms_days' => 0,
        ];

        try {
            return $this->sales->createCustomer(
                $companyId,
                $values,
                $actorId
            );
        } catch (Throwable $exception) {
            /*
             * Handles a concurrent first Quick Sale safely:
             * another request may have created QUICK-SALE first.
             */
            foreach ($this->sales->customers($companyId) as $customer) {
                if (
                    strtoupper(
                        trim(
                            (string) (
                                $customer['customer_number'] ?? ''
                            )
                        )
                    ) === 'QUICK-SALE'
                ) {
                    return (int) $customer['customer_id'];
                }
            }

            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $warehouse
     */
    private function warehouseDeliveryContact(
        array $warehouse
    ): string {
        $parts = [];

        $name = trim((string) ($warehouse['name'] ?? ''));
        $address = trim((string) ($warehouse['address'] ?? ''));
        $phone = trim((string) ($warehouse['phone'] ?? ''));
        $email = trim((string) ($warehouse['email'] ?? ''));

        if ($name !== '') {
            $parts[] = $name;
        }

        if ($address !== '') {
            $parts[] = $address;
        }

        if ($phone !== '') {
            $parts[] = 'Phone: ' . $phone;
        }

        if ($email !== '') {
            $parts[] = 'Email: ' . $email;
        }

        return implode("\n", $parts);
    }

    private function recordQuickSale(
        int $companyId,
        int $quotationId,
        int $userId,
        int $agentId,
        int $teamId,
        int $managerUserId,
        int $warehouseId
    ): void {
        $statement = \db()->prepare(
            'INSERT INTO sales_quick_sales
                (
                    company_id,
                    quotation_id,
                    user_id,
                    agent_id,
                    team_id,
                    manager_user_id,
                    warehouse_id,
                    status
                )
             VALUES
                (
                    :company_id,
                    :quotation_id,
                    :user_id,
                    :agent_id,
                    :team_id,
                    :manager_user_id,
                    :warehouse_id,
                    \'submitted\'
                )'
        );

        $statement->execute([
            'company_id' => $companyId,
            'quotation_id' => $quotationId,
            'user_id' => $userId,
            'agent_id' => $agentId,
            'team_id' => $teamId,
            'manager_user_id' => $managerUserId,
            'warehouse_id' => $warehouseId,
        ]);
    }

    private function setQuickSaleStatus(
        int $companyId,
        int $quotationId,
        string $status
    ): void {
        $statement = \db()->prepare(
            'UPDATE sales_quick_sales
             SET status = :status
             WHERE company_id = :company_id
               AND quotation_id = :quotation_id'
        );

        $statement->execute([
            'status' => $status,
            'company_id' => $companyId,
            'quotation_id' => $quotationId,
        ]);
    }

    private function defaultCurrency(): string
    {
        $currency = strtoupper(
            trim(
                (string) (
                    $_SESSION['auth']['company']['default_currency']
                    ?? 'ETB'
                )
            )
        );

        return preg_match('/^[A-Z]{3}$/', $currency) === 1
            ? $currency
            : 'ETB';
    }
}