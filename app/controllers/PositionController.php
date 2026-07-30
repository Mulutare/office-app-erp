<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\PositionManagementService;

final class PositionController
{
    private AuthorizationService $authorization;
    private PositionManagementService $positions;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->positions =
            new PositionManagementService();
    }

    public function index(): void
    {
        $this->authorization
            ->requireTenantPermission(
                'organization.positions.view'
            );
        $listing = $this->positions->listing();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Position Catalogue',
            'pageDescription' =>
                'Plan approved workforce positions across company departments and locations.',
            'contentView' =>
                'organization.positions.index',
            'user' => $_SESSION['auth'],
            'positions' => $listing['positions'],
            'summary' => $listing['summary'],
            'canManage' => $this->canManage(),
            'notice' => \getFlash(
                'position_notice'
            ),
        ]);
    }

    public function create(): void
    {
        $this->requireManagement();
        $assignEmployeeId =
            $this->queryInteger(
                'assign_employee_id'
            );
        $old = \getFlash('position_create_old');

        if (!is_array($old)) {
            $old = [
                'approved_headcount' => 1,
                'status' =>
                    $assignEmployeeId > 0
                        ? 'open'
                        : 'planned',
            ];
        }

        $this->renderForm(
            'create',
            0,
            $old,
            \getFlash(
                'position_create_errors',
                []
            ),
            $assignEmployeeId
        );
    }

    public function store(): void
    {
        $this->requireManagement();
        $assignEmployeeId =
            $this->postInteger(
                'assign_employee_id'
            );

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('position_create_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect(
                $this->createPath(
                    $assignEmployeeId
                )
            );
        }

        $input = $this->positionInput();
        $result = $this->positions->create(
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!$result['successful']) {
            \flash(
                'position_create_errors',
                $result['errors']
            );
            \flash('position_create_old', $input);
            \redirect(
                $this->createPath(
                    $assignEmployeeId
                )
            );
        }

        if ($assignEmployeeId > 0) {
            \flash(
                'employee_position_notice',
                [
                    'type' => 'success',
                    'message' => sprintf(
                        'Position %s was created as open. Complete the employee assignment below.',
                        (string) $result[
                            'positionName'
                        ]
                    ),
                ]
            );
            \redirect(
                '/hr/employees/position?id='
                . $assignEmployeeId
                . '&position_id='
                . (int) $result['positionId']
            );
        }

        \flash('position_notice', [
            'type' => 'success',
            'message' => sprintf(
                'Position %s was created successfully.',
                (string) $result['positionName']
            ),
        ]);
        \redirect('/organization/positions');
    }

    public function edit(): void
    {
        $this->requireManagement();
        $positionId = $this->queryInteger('id');
        $position = $this->positions->form(
            $positionId
        );

        if ($position === null) {
            $this->notFound();
        }

        $old = \getFlash('position_update_old');

        if (!is_array($old)) {
            $old = $position;
        }

        $this->renderForm(
            'edit',
            $positionId,
            $old,
            \getFlash(
                'position_update_errors',
                []
            )
        );
    }

    public function update(): void
    {
        $this->requireManagement();
        $positionId = $this->postInteger(
            'position_id'
        );

        if ($positionId < 1) {
            $this->notFound();
        }

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('position_update_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect(
                '/organization/positions/edit?id='
                . $positionId
            );
        }

        $input = $this->positionInput();
        $result = $this->positions->update(
            $positionId,
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'position_update_errors',
                $result['errors']
            );
            \flash('position_update_old', $input);
            \redirect(
                '/organization/positions/edit?id='
                . $positionId
            );
        }

        \flash('position_notice', [
            'type' => 'success',
            'message' => !empty($result['changed'])
                ? sprintf(
                    'Position %s was updated successfully.',
                    (string) $result['positionName']
                )
                : 'No position changes were required.',
        ]);
        \redirect('/organization/positions');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(
        string $mode,
        int $positionId,
        array $old,
        array $errors,
        int $assignEmployeeId = 0
    ): void {
        $isEdit = $mode === 'edit';
        $options = $this->positions->options();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => $isEdit
                ? 'Edit Position'
                : 'Create Position',
            'pageDescription' => $isEdit
                ? 'Update workforce planning, organization placement and operational status.'
                : 'Create an approved workforce position without assigning an employee.',
            'contentView' =>
                'organization.positions.form',
            'user' => $_SESSION['auth'],
            'formMode' => $mode,
            'positionId' => $positionId,
            'old' => $old,
            'errors' => $errors,
            'branches' => $options['branches'],
            'departments' =>
                $options['departments'],
            'jobTitles' => $options['jobTitles'],
            'statuses' => $options['statuses'],
            'assignEmployeeId' =>
                $isEdit ? 0 : $assignEmployeeId,
        ]);
    }

    private function requireManagement(): void
    {
        $this->authorization
            ->requireTenantPermission(
                'organization.positions.manage'
            );
    }

    private function canManage(): bool
    {
        return in_array(
            'organization.positions.manage',
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function positionInput(): array
    {
        return [
            'code' => \postString('code'),
            'name' => \postString('name'),
            'branch_id' =>
                \postString('branch_id'),
            'department_id' =>
                \postString('department_id'),
            'job_title_id' =>
                \postString('job_title_id'),
            'approved_headcount' =>
                \postString('approved_headcount'),
            'status' => \postString('status'),
            'description' =>
                \postString('description'),
        ];
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

    private function createPath(
        int $assignEmployeeId
    ): string {
        if ($assignEmployeeId < 1) {
            return '/organization/positions/create';
        }

        return '/organization/positions/create'
            . '?assign_employee_id='
            . $assignEmployeeId;
    }

    private function notFound(): never
    {
        http_response_code(404);

        \view('errors.position-404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}
