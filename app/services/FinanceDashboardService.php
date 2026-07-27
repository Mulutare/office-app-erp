<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExpenseRequest;

final class FinanceDashboardService
{
    private const PAGE_SIZE = 20;

    private const STATUSES = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
    ];

    private ExpenseRequest $requests;
    private TenantContext $tenant;

    public function __construct()
    {
        $this->requests = new ExpenseRequest();
        $this->tenant = new TenantContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(
        string $search,
        string $status,
        int $page
    ): array {
        $search = mb_substr(
            trim($search),
            0,
            100
        );
        $status = array_key_exists(
            $status,
            self::STATUSES
        )
            ? $status
            : '';
        $filters = [
            'search' => $search,
            'status' => $status,
        ];
        $page = max(1, $page);
        $companyId = $this->tenant->companyId();
        $total = $this->requests->count(
            $companyId,
            $filters
        );
        $lastPage = max(
            1,
            (int) ceil($total / self::PAGE_SIZE)
        );
        $page = min($page, $lastPage);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $requests = $this->requests->page(
            $companyId,
            $filters,
            self::PAGE_SIZE,
            $offset
        );

        foreach ($requests as &$request) {
            $request = $this->present($request);
        }

        unset($request);

        return [
            'requests' => $requests,
            'summary' =>
                $this->requests->statusSummary(
                    $companyId
                ),
            'statusOptions' => self::STATUSES,
            'filters' => $filters,
            'pagination' => [
                'page' => $page,
                'lastPage' => $lastPage,
                'pageSize' => self::PAGE_SIZE,
                'total' => $total,
                'from' => $total === 0
                    ? 0
                    : $offset + 1,
                'to' => min(
                    $offset + self::PAGE_SIZE,
                    $total
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function present(array $request): array
    {
        $preferred = trim((string) (
            $request['preferred_name'] ?? ''
        ));
        $first = trim((string) (
            $request['first_name'] ?? ''
        ));
        $last = trim((string) (
            $request['last_name'] ?? ''
        ));
        $status = (string) (
            $request['status'] ?? ''
        );
        $amount = (float) (
            $request['amount'] ?? 0
        );
        $currency = strtoupper((string) (
            $request['currency'] ?? ''
        ));

        $request['requesterName'] = trim(
            ($preferred !== '' ? $preferred : $first)
            . ' '
            . $last
        );
        $request['statusLabel'] =
            self::STATUSES[$status]
            ?? ucwords(str_replace(
                '_',
                ' ',
                $status
            ));
        $request['statusTone'] =
            $this->statusTone($status);
        $request['amountLabel'] = trim(
            $currency
            . ' '
            . number_format($amount, 2)
        );

        return $request;
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'approved', 'paid' => 'success',
            'rejected', 'cancelled' => 'danger',
            'submitted' => 'warning',
            default => 'muted',
        };
    }
}
