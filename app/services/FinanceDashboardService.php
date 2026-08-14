<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Repositories\FinanceRepository;
use App\Repositories\MySql\FinanceRepository
    as MySqlFinanceRepository;

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

    private const RECEIVABLE_STATUSES = [
        'open' => 'Open',
        'overdue' => 'Overdue',
        'paid' => 'Paid',
    ];

    private ExpenseRequest $requests;
    private FinanceRepository $repository;
    private TenantContext $tenant;

    public function __construct()
    {
        $this->requests = new ExpenseRequest();
        $this->repository = new MySqlFinanceRepository();
        $this->tenant = new TenantContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(
        string $search,
        string $status,
        int $page,
        string $receivableSearch = '',
        string $receivableStatus = '',
        int $receivablePage = 1
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

        $receivableSearch = mb_substr(
            trim($receivableSearch),
            0,
            100
        );
        $receivableStatus = array_key_exists(
            $receivableStatus,
            self::RECEIVABLE_STATUSES
        )
            ? $receivableStatus
            : '';
        $receivableFilters = [
            'search' => $receivableSearch,
            'status' => $receivableStatus,
        ];
        $receivablePage = max(
            1,
            $receivablePage
        );
        $receivableTotal =
            $this->repository->countSalesReceivables(
                $companyId,
                $receivableFilters
            );
        $receivableLastPage = max(
            1,
            (int) ceil(
                $receivableTotal
                / self::PAGE_SIZE
            )
        );
        $receivablePage = min(
            $receivablePage,
            $receivableLastPage
        );
        $receivableOffset =
            ($receivablePage - 1)
            * self::PAGE_SIZE;
        $receivables =
            $this->repository->salesReceivables(
                $companyId,
                $receivableFilters,
                self::PAGE_SIZE,
                $receivableOffset
            );
        $receivableSummary =
            $this->repository->salesReceivableSummary(
                $companyId
            );
        $recentReceipts =
            $this->repository->recentSalesReceipts(
                $companyId,
                10
            );
        $recentJournals =
            $this->repository->recentJournalBatches(
                $companyId,
                10
            );

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
            'receivableSummary' => $receivableSummary,
            'receivables' => $receivables,
            'receivableTotal' => $receivableTotal,
            'receivableStatusOptions' =>
                self::RECEIVABLE_STATUSES,
            'receivableFilters' =>
                $receivableFilters,
            'receivablePagination' => [
                'page' => $receivablePage,
                'lastPage' =>
                    $receivableLastPage,
                'pageSize' =>
                    self::PAGE_SIZE,
                'total' =>
                    $receivableTotal,
                'from' =>
                    $receivableTotal === 0
                        ? 0
                        : $receivableOffset + 1,
                'to' => min(
                    $receivableOffset
                    + self::PAGE_SIZE,
                    $receivableTotal
                ),
            ],
            'recentReceipts' => $recentReceipts,
            'recentJournals' => $recentJournals,
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

    /** @return list<array<string,mixed>> */
    public function exportJournals(int $limit): array
    {
        return $this->repository->recentJournalBatches($this->tenant->companyId(),$limit);
    }

    /** @param array<string,string> $filters @return list<array<string,mixed>> */
    public function exportExpenses(array $filters,int $limit): array
    {
        $safe=['search'=>mb_substr(trim((string)($filters['search']??'')),0,100),'status'=>(string)($filters['status']??'')];
        if(!array_key_exists($safe['status'],self::STATUSES))$safe['status']='';
        $rows=$this->requests->page($this->tenant->companyId(),$safe,$limit,0);
        foreach($rows as &$row)$row=$this->present($row);unset($row);return $rows;
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
