<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\JobTitleManagementService;

final class JobTitleController
{
    private AuthorizationService $authorization;
    private JobTitleManagementService $jobTitles;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->jobTitles =
            new JobTitleManagementService();
    }

    public function index(): void
    {
        $this->authorization
            ->requireTenantPermission(
                'organization.job_titles.view'
            );
        $listing = $this->jobTitles->listing();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Job Title Catalogue',
            'pageDescription' =>
                'Maintain standardized job titles, families and grade references.',
            'contentView' =>
                'organization.job-titles.index',
            'user' => $_SESSION['auth'],
            'jobTitles' => $listing['jobTitles'],
            'summary' => $listing['summary'],
            'canManage' => $this->canManage(),
            'notice' => \getFlash(
                'job_title_notice'
            ),
        ]);
    }

    public function create(): void
    {
        $this->requireManagement();
        $old = \getFlash('job_title_create_old');

        if (!is_array($old)) {
            $old = ['active' => true];
        }

        $this->renderForm(
            'create',
            0,
            $old,
            \getFlash(
                'job_title_create_errors',
                []
            )
        );
    }

    public function store(): void
    {
        $this->requireManagement();

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('job_title_create_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect(
                '/organization/job-titles/create'
            );
        }

        $input = $this->jobTitleInput();
        $result = $this->jobTitles->create(
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!$result['successful']) {
            \flash(
                'job_title_create_errors',
                $result['errors']
            );
            \flash('job_title_create_old', $input);
            \redirect(
                '/organization/job-titles/create'
            );
        }

        \flash('job_title_notice', [
            'type' => 'success',
            'message' => sprintf(
                'Job title %s was created successfully.',
                (string) $result['jobTitleName']
            ),
        ]);
        \redirect('/organization/job-titles');
    }

    public function edit(): void
    {
        $this->requireManagement();
        $jobTitleId = $this->queryInteger('id');
        $jobTitle = $this->jobTitles->form(
            $jobTitleId
        );

        if ($jobTitle === null) {
            $this->notFound();
        }

        $old = \getFlash('job_title_update_old');

        if (!is_array($old)) {
            $old = $jobTitle;
        }

        $this->renderForm(
            'edit',
            $jobTitleId,
            $old,
            \getFlash(
                'job_title_update_errors',
                []
            )
        );
    }

    public function update(): void
    {
        $this->requireManagement();
        $jobTitleId = $this->postInteger(
            'job_title_id'
        );

        if ($jobTitleId < 1) {
            $this->notFound();
        }

        if (
            !\verifyCsrfToken(
                \postString('_token')
            )
        ) {
            \flash('job_title_update_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);
            \redirect(
                '/organization/job-titles/edit?id='
                . $jobTitleId
            );
        }

        $input = $this->jobTitleInput();
        $result = $this->jobTitles->update(
            $jobTitleId,
            $input,
            (int) $_SESSION['auth']['user_id']
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'job_title_update_errors',
                $result['errors']
            );
            \flash('job_title_update_old', $input);
            \redirect(
                '/organization/job-titles/edit?id='
                . $jobTitleId
            );
        }

        \flash('job_title_notice', [
            'type' => 'success',
            'message' => !empty($result['changed'])
                ? sprintf(
                    'Job title %s was updated successfully.',
                    (string) $result['jobTitleName']
                )
                : 'No job-title changes were required.',
        ]);
        \redirect('/organization/job-titles');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(
        string $mode,
        int $jobTitleId,
        array $old,
        array $errors
    ): void {
        $isEdit = $mode === 'edit';

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
                ? 'Edit Job Title'
                : 'Create Job Title',
            'pageDescription' => $isEdit
                ? 'Update job-title identity, classification and availability.'
                : 'Add standardized role terminology for future workforce planning.',
            'contentView' =>
                'organization.job-titles.form',
            'user' => $_SESSION['auth'],
            'formMode' => $mode,
            'jobTitleId' => $jobTitleId,
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    private function requireManagement(): void
    {
        $this->authorization
            ->requireTenantPermission(
                'organization.job_titles.manage'
            );
    }

    private function canManage(): bool
    {
        return in_array(
            'organization.job_titles.manage',
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jobTitleInput(): array
    {
        return [
            'code' => \postString('code'),
            'name' => \postString('name'),
            'job_family' =>
                \postString('job_family'),
            'grade_level' =>
                \postString('grade_level'),
            'description' =>
                \postString('description'),
            'active' => isset($_POST['active']),
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

    private function notFound(): never
    {
        http_response_code(404);

        \view('errors.job-title-404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
}
