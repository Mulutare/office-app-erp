<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeActivity;

final class EmployeeActivityService
{
    private const PAGE_SIZE = 15;

    private Employee $employees;
    private EmployeeActivity $activity;
    private AuditChangePresenter $changes;

    public function __construct()
    {
        $this->employees = new Employee();
        $this->activity = new EmployeeActivity();
        $this->changes =
            new AuditChangePresenter();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function listing(
        int $employeeId,
        int $page
    ): ?array {
        if ($employeeId < 1) {
            return null;
        }

        $employee = $this->employees->find(
            $employeeId
        );

        if ($employee === null) {
            return null;
        }

        $page = max(1, $page);
        $total = $this->activity
            ->countForEmployee($employeeId);
        $lastPage = max(
            1,
            (int) ceil($total / self::PAGE_SIZE)
        );
        $page = min($page, $lastPage);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $events = $this->activity
            ->pageForEmployee(
                $employeeId,
                self::PAGE_SIZE,
                $offset
            );

        foreach ($events as &$event) {
            $event = $this->presentEvent($event);
        }

        unset($event);
        $employee['displayName'] =
            $this->displayName($employee);

        return [
            'employee' => $employee,
            'events' => $events,
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
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function presentEvent(array $event): array
    {
        $action = strtoupper((string) (
            $event['action'] ?? ''
        ));
        $actor = trim((string) (
            $event['actor_name']
            ?? $event['actor_username']
            ?? ''
        ));

        if ($actor === '') {
            $actor = 'System';
        }

        $labels = [
            'CREATE' => 'Employee record created',
            'UPDATE' => 'Employee record updated',
            'ENABLE' => 'Employee record activated',
            'DISABLE' => 'Employee record deactivated',
            'ARCHIVE' => 'Employee record archived',
            'RESTORE' => 'Employee record restored',
        ];
        $event['label'] = $labels[$action]
            ?? ucwords(strtolower(str_replace(
                '_',
                ' ',
                $action
            )));
        $event['actor_label'] = $actor;
        $event['description'] = sprintf(
            '%s performed this action on the employee record.',
            $actor
        );
        $event['tone'] = match ($action) {
            'CREATE', 'ENABLE', 'RESTORE' =>
                'success',
            'DISABLE', 'ARCHIVE', 'DELETE' =>
                'danger',
            default => 'information',
        };
        $event['changes'] = $this->changes
            ->changes(
                $event['old_values'] ?? null,
                $event['new_values'] ?? null
            );

        unset(
            $event['old_values'],
            $event['new_values']
        );

        return $event;
    }

    /**
     * @param array<string, mixed> $employee
     */
    private function displayName(
        array $employee
    ): string {
        $preferred = trim((string) (
            $employee['preferred_name'] ?? ''
        ));
        $first = trim((string) (
            $employee['first_name'] ?? ''
        ));
        $last = trim((string) (
            $employee['last_name'] ?? ''
        ));

        return trim(
            ($preferred !== '' ? $preferred : $first)
            . ' '
            . $last
        );
    }
}
