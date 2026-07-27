<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLogQuery;
use DateTimeImmutable;

final class AuditLogAdministrationService
{
    private const PAGE_SIZE = 25;

    private AuditLogQuery $auditLogs;
    private AuditChangePresenter $changes;

    public function __construct()
    {
        $this->auditLogs = new AuditLogQuery();
        $this->changes =
            new AuditChangePresenter();
    }

    /**
     * @return array<string, mixed>
     */
    public function listing(
        string $search,
        string $module,
        string $action,
        string $actor,
        string $dateFrom,
        string $dateTo,
        int $page
    ): array {
        $options = $this->auditLogs
            ->filterOptions();
        $search = mb_substr(
            trim($search),
            0,
            100
        );
        $module = in_array(
            $module,
            $options['modules'],
            true
        )
            ? $module
            : '';
        $action = in_array(
            $action,
            $options['actions'],
            true
        )
            ? $action
            : '';
        $actor = $this->validatedActor(
            $actor,
            $options['actors']
        );
        $fromDate = $this->parseDate($dateFrom);
        $toDate = $this->parseDate($dateTo);

        if (
            $fromDate !== null
            && $toDate !== null
            && $fromDate > $toDate
        ) {
            [
                $fromDate,
                $toDate,
            ] = [
                $toDate,
                $fromDate,
            ];
        }

        $dateFrom = $fromDate?->format('Y-m-d')
            ?? '';
        $dateTo = $toDate?->format('Y-m-d')
            ?? '';
        $queryFilters = [
            'search' => $search,
            'module' => $module,
            'action' => $action,
            'actor' => $actor,
            'dateFromSql' => $fromDate?->format(
                'Y-m-d 00:00:00'
            ) ?? '',
            'dateToExclusiveSql' => $toDate
                ? $toDate->modify('+1 day')->format(
                    'Y-m-d 00:00:00'
                )
                : '',
        ];
        $page = max(1, $page);
        $total = $this->auditLogs->count(
            $queryFilters
        );
        $lastPage = max(
            1,
            (int) ceil($total / self::PAGE_SIZE)
        );
        $page = min($page, $lastPage);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $logs = $this->auditLogs->page(
            $queryFilters,
            self::PAGE_SIZE,
            $offset
        );

        foreach ($logs as &$log) {
            $log = $this->present($log);
        }

        unset($log);

        return [
            'logs' => $logs,
            'options' => $options,
            'filters' => [
                'search' => $search,
                'module' => $module,
                'action' => $action,
                'actor' => $actor,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
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
     * @return array<string, mixed>|null
     */
    public function details(
        int $auditLogId
    ): ?array {
        if ($auditLogId < 1) {
            return null;
        }

        $log = $this->auditLogs->find(
            $auditLogId
        );

        if ($log === null) {
            return null;
        }

        $log = $this->present($log);
        $log['changes'] = $this->changes->changes(
            $log['old_values'] ?? null,
            $log['new_values'] ?? null
        );
        $log['oldSnapshot'] =
            $this->changes->snapshot(
                $log['old_values'] ?? null
            );
        $log['newSnapshot'] =
            $this->changes->snapshot(
                $log['new_values'] ?? null
            );

        unset(
            $log['old_values'],
            $log['new_values']
        );

        return $log;
    }

    /**
     * @param array<string, mixed> $log
     *
     * @return array<string, mixed>
     */
    private function present(array $log): array
    {
        $action = (string) (
            $log['action'] ?? ''
        );
        $actorName = trim((string) (
            $log['actor_name']
            ?? $log['actor_username']
            ?? ''
        ));
        $table = trim((string) (
            $log['table_name'] ?? ''
        ));
        $recordId = trim((string) (
            $log['record_id'] ?? ''
        ));

        $log['actionLabel'] =
            $this->actionLabel($action);
        $log['tone'] = $this->tone($action);
        $log['actorLabel'] = $actorName !== ''
            ? $actorName
            : 'System / deleted actor';
        $log['targetLabel'] = $table === ''
            ? 'No record target'
            : $table . (
                $recordId === ''
                    ? ''
                    : ' #' . $recordId
            );

        return $log;
    }

    private function actionLabel(string $action): string
    {
        $labels = [
            'CREATE' => 'Created record',
            'UPDATE' => 'Updated record',
            'ENABLE' => 'Enabled account',
            'DISABLE' => 'Disabled account',
            'UNLOCK' => 'Unlocked account',
            'PASSWORD_RESET' =>
                'Reset user password',
            'PASSWORD_CHANGE' =>
                'Changed password',
            'LOGIN' => 'Signed in',
            'LOGOUT' => 'Signed out',
            'UPDATE_PERMISSIONS' =>
                'Updated role permissions',
        ];

        return $labels[$action]
            ?? ucwords(strtolower(str_replace(
                '_',
                ' ',
                $action
            )));
    }

    private function tone(string $action): string
    {
        if (
            $action === 'CREATE'
            || $action === 'ENABLE'
            || $action === 'UNLOCK'
            || $action === 'LOGIN'
        ) {
            return 'success';
        }

        if ($action === 'DISABLE') {
            return 'danger';
        }

        if (
            $action === 'PASSWORD_RESET'
            || $action === 'PASSWORD_CHANGE'
        ) {
            return 'warning';
        }

        return 'information';
    }

    /**
     * @param list<array<string, mixed>> $actors
     */
    private function validatedActor(
        string $actor,
        array $actors
    ): string {
        if ($actor === 'system') {
            return $actor;
        }

        if (!ctype_digit($actor)) {
            return '';
        }

        $actorId = (int) $actor;

        foreach ($actors as $option) {
            if (
                (int) ($option['user_id'] ?? 0)
                === $actorId
            ) {
                return (string) $actorId;
            }
        }

        return '';
    }

    private function parseDate(
        string $value
    ): ?DateTimeImmutable {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );

        return $date !== false
            && $date->format('Y-m-d') === $value
                ? $date
                : null;
    }
}
