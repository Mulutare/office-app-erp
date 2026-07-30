<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\EmployeePositionAssignmentService;

final class EmployeePositionController
{
    private AuthorizationService $authorization;
    private EmployeePositionAssignmentService
        $assignments;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->assignments =
            new EmployeePositionAssignmentService();
    }

    public function edit(): void
    {
        $this->requireManagement();
        $employeeId = $this->queryInteger('id');
        $form = $this->assignments->form(
            $employeeId
        );

        if ($form === null) {
            $this->notFound();
        }

        $old = \getFlash(
            'employee_position_old'
        );

        if (!is_array($old)) {
            $old = [
                'effective_from' =>
                    date('Y-m-d'),
            ];

            $requestedPositionId =
                $this->queryInteger('position_id');
            $availablePositionIds = array_map(
                static fn (array $position): int =>
                    !empty($position['available'])
                        ? (int) (
                            $position['position_id']
                            ?? 0
                        )
                        : 0,
                $form['positions']
            );

            if (
                $requestedPositionId > 0
                && in_array(
                    $requestedPositionId,
                    $availablePositionIds,
                    true
                )
            ) {
                $old['position_id'] =
                    (string) $requestedPositionId;
            }
        }

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => empty($form['current'])
                ? 'Assign Position'
                : 'Change Position',
            'pageDescription' =>
                'Place an employee into approved headcount while preserving assignment history.',
            'contentView' =>
                'hr.employees.position',
            'user' => $_SESSION['auth'],
            'employee' => $form['employee'],
            'current' => $form['current'],
            'positions' => $form['positions'],
            'canManagePositions' =>
                $this->canManagePositions(),
            'notice' => \getFlash(
                'employee_position_notice'
            ),
            'old' => $old,
            'errors' => \getFlash(
                'employee_position_errors',
                []
            ),
        ]);
    }

    public function update(): void
    {
        $this->requireManagement();
        $employeeId = $this->postInteger(
            'employee_id'
        );

        if ($employeeId < 1) {
            $this->notFound();
        }

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('employee_position_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect(
                '/hr/employees/position?id='
                . $employeeId
            );
        }

        $input = [
            'position_id' =>
                \postString('position_id'),
            'effective_from' =>
                \postString('effective_from'),
            'notes' => \postString('notes'),
        ];
        $result = $this->assignments->assign(
            $employeeId,
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'employee_position_errors',
                $result['errors']
            );
            \flash('employee_position_old', $input);
            \redirect(
                '/hr/employees/position?id='
                . $employeeId
            );
        }

        \flash('hr_notice', [
            'type' => 'success',
            'message' => sprintf(
                '%s is now assigned to %s.',
                (string) $result['employeeName'],
                (string) $result['positionName']
            ),
        ]);
        \redirect(
            '/hr/employees/view?id='
            . $employeeId
        );
    }

    private function requireManagement(): void
    {
        $this->authorization
            ->requireModule('hr');
        $this->authorization
            ->requireTenantPermission(
                'hr.records.manage'
            );
    }

    private function canManagePositions(): bool
    {
        return in_array(
            'organization.positions.manage',
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    private function queryInteger(string $key): int
    {
        $value = $_GET[$key] ?? null;

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : (is_int($value) ? $value : 0);
    }

    private function postInteger(string $key): int
    {
        $value = $_POST[$key] ?? null;

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : (is_int($value) ? $value : 0);
    }

    private function notFound(): never
    {
        http_response_code(404);

        \view('errors.employee-404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}
