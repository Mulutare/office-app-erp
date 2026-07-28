<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\JobTitleRepository;
use App\Repositories\RepositoryFactory;
use PDOException;
use Throwable;

final class JobTitleManagementService
{
    private JobTitleRepository $jobTitles;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?JobTitleRepository $jobTitles = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->jobTitles = $jobTitles
            ?? RepositoryFactory::jobTitles();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array{
     *     jobTitles: list<array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         active: int,
     *         families: int
     *     }
     * }
     */
    public function listing(): array
    {
        $jobTitles = $this->jobTitles
            ->listForCompany(
                $this->tenant->companyId()
            );
        $active = 0;
        $families = [];

        foreach ($jobTitles as &$jobTitle) {
            $jobTitle['job_title_id'] = (int) (
                $jobTitle['job_title_id'] ?? 0
            );
            $jobTitle['active'] =
                !empty($jobTitle['active']);
            $active += $jobTitle['active'] ? 1 : 0;
            $family = $this->nullable(
                $jobTitle['job_family'] ?? null
            );

            if ($family !== null) {
                $families[strtolower($family)] = true;
            }
        }

        unset($jobTitle);

        return [
            'jobTitles' => $jobTitles,
            'summary' => [
                'total' => count($jobTitles),
                'active' => $active,
                'families' => count($families),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function form(int $jobTitleId): ?array
    {
        $jobTitle = $this->jobTitles->find(
            $this->tenant->companyId(),
            $jobTitleId
        );

        if ($jobTitle === null) {
            return null;
        }

        return $this->recordValues($jobTitle)
            + [
                'job_title_id' => (int) (
                    $jobTitle['job_title_id'] ?? 0
                ),
            ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     jobTitleId?: int,
     *     jobTitleName?: string
     * }
     */
    public function create(
        array $input,
        int $createdBy
    ): array {
        $companyId = $this->tenant->companyId();
        $values = $this->normalize($input);
        $errors = $this->validate(
            $companyId,
            $values
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $jobTitleId = $this->jobTitles->create(
                $companyId,
                $values,
                $createdBy
            );
            $this->auditLogs->record(
                $createdBy,
                'CREATE',
                'organization',
                'organization_job_titles',
                (string) $jobTitleId,
                null,
                $this->recordValues($values),
                $companyId
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (PDOException $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            if (
                (string) $exception->getCode()
                === '23000'
            ) {
                return $this->conflictResult();
            }

            throw $exception;
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'errors' => [],
            'jobTitleId' => $jobTitleId,
            'jobTitleName' => (string) $values['name'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     notFound?: bool,
     *     errors: array<string, string>,
     *     jobTitleId?: int,
     *     jobTitleName?: string,
     *     changed?: bool
     * }
     */
    public function update(
        int $jobTitleId,
        array $input,
        int $updatedBy
    ): array {
        $companyId = $this->tenant->companyId();
        $jobTitle = $this->jobTitles->find(
            $companyId,
            $jobTitleId
        );

        if ($jobTitle === null) {
            return [
                'successful' => false,
                'notFound' => true,
                'errors' => [],
            ];
        }

        $values = $this->normalize($input);
        $errors = $this->validate(
            $companyId,
            $values,
            $jobTitleId
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $oldValues = $this->recordValues($jobTitle);
        $newValues = $this->recordValues($values);

        if ($oldValues === $newValues) {
            return [
                'successful' => true,
                'errors' => [],
                'jobTitleId' => $jobTitleId,
                'jobTitleName' =>
                    (string) $values['name'],
                'changed' => false,
            ];
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $updated = $this->jobTitles->update(
                $companyId,
                $jobTitleId,
                $values,
                $updatedBy
            );

            if (!$updated) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'notFound' => true,
                    'errors' => [],
                ];
            }

            $this->auditLogs->record(
                $updatedBy,
                'UPDATE',
                'organization',
                'organization_job_titles',
                (string) $jobTitleId,
                $oldValues,
                $newValues,
                $companyId
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (PDOException $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            if (
                (string) $exception->getCode()
                === '23000'
            ) {
                return $this->conflictResult();
            }

            throw $exception;
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'errors' => [],
            'jobTitleId' => $jobTitleId,
            'jobTitleName' => (string) $values['name'],
            'changed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        return [
            'code' => strtoupper(trim((string) (
                $input['code'] ?? ''
            ))),
            'name' => trim((string) (
                $input['name'] ?? ''
            )),
            'job_family' => $this->nullable(
                $input['job_family'] ?? null
            ),
            'grade_level' => $this->nullable(
                $input['grade_level'] ?? null
            ),
            'description' => $this->nullable(
                $input['description'] ?? null
            ),
            'active' => !empty($input['active']),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private function validate(
        int $companyId,
        array $values,
        ?int $ignoreJobTitleId = null
    ): array {
        $errors = [];
        $code = (string) $values['code'];
        $name = (string) $values['name'];

        if (
            preg_match(
                '/^[A-Z][A-Z0-9_-]{1,29}$/',
                $code
            ) !== 1
        ) {
            $errors['code'] =
                'Code must contain 2-30 uppercase letters, numbers, hyphens or underscores and begin with a letter.';
        } elseif (
            $this->jobTitles->codeExists(
                $companyId,
                $code,
                $ignoreJobTitleId
            )
        ) {
            $errors['code'] =
                'That job-title code is already in use.';
        }

        if (
            mb_strlen($name) < 2
            || mb_strlen($name) > 120
        ) {
            $errors['name'] =
                'Job-title name must contain 2-120 characters.';
        } elseif (
            $this->jobTitles->nameExists(
                $companyId,
                $name,
                $ignoreJobTitleId
            )
        ) {
            $errors['name'] =
                'That job-title name is already in use.';
        }

        $this->validateOptionalLength(
            $errors,
            'job_family',
            $values['job_family'],
            100,
            'Job family'
        );
        $this->validateOptionalLength(
            $errors,
            'grade_level',
            $values['grade_level'],
            40,
            'Grade level'
        );
        $this->validateOptionalLength(
            $errors,
            'description',
            $values['description'],
            500,
            'Description'
        );

        return $errors;
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateOptionalLength(
        array &$errors,
        string $field,
        mixed $value,
        int $maximum,
        string $label
    ): void {
        if (
            is_string($value)
            && mb_strlen($value) > $maximum
        ) {
            $errors[$field] = sprintf(
                '%s cannot exceed %d characters.',
                $label,
                $maximum
            );
        }
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function recordValues(array $record): array
    {
        return [
            'code' => (string) (
                $record['code'] ?? ''
            ),
            'name' => (string) (
                $record['name'] ?? ''
            ),
            'job_family' => $this->nullable(
                $record['job_family'] ?? null
            ),
            'grade_level' => $this->nullable(
                $record['grade_level'] ?? null
            ),
            'description' => $this->nullable(
                $record['description'] ?? null
            ),
            'active' => !empty($record['active']),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{
     *     successful: false,
     *     errors: array<string, string>
     * }
     */
    private function conflictResult(): array
    {
        return [
            'successful' => false,
            'errors' => [
                'form' =>
                    'A job title with that code or name already exists.',
            ],
        ];
    }
}
