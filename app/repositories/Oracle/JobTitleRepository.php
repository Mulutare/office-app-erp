<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\JobTitleRepository
    as JobTitleRepositoryContract;

final class JobTitleRepository extends OracleRepository
    implements JobTitleRepositoryContract
{
    public function listForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                job_title_id,
                code,
                name,
                job_family,
                grade_level,
                description,
                active,
                created_at,
                updated_at
             FROM organization_job_titles
             WHERE company_id = :company_id
               AND deleted_at IS NULL
             ORDER BY
                active DESC,
                job_family,
                name
             FETCH FIRST 250 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $jobTitles = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($jobTitles)
            ? $jobTitles
            : [];
    }

    public function find(
        int $companyId,
        int $jobTitleId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                job_title_id,
                code,
                name,
                job_family,
                grade_level,
                description,
                active,
                created_by,
                updated_by,
                created_at,
                updated_at
             FROM organization_job_titles
             WHERE company_id = :company_id
               AND job_title_id = :job_title_id
               AND deleted_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'job_title_id' => $jobTitleId,
        ]);
        $jobTitle = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($jobTitle)
            ? $jobTitle
            : null;
    }

    public function codeExists(
        int $companyId,
        string $code,
        ?int $ignoreJobTitleId = null
    ): bool {
        return $this->valueExists(
            $companyId,
            'code',
            $code,
            $ignoreJobTitleId
        );
    }

    public function nameExists(
        int $companyId,
        string $name,
        ?int $ignoreJobTitleId = null
    ): bool {
        return $this->valueExists(
            $companyId,
            'name',
            $name,
            $ignoreJobTitleId
        );
    }

    public function create(
        int $companyId,
        array $values,
        int $createdBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO organization_job_titles
                (
                    company_id,
                    code,
                    name,
                    job_family,
                    grade_level,
                    description,
                    active,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :code,
                    :name,
                    :job_family,
                    :grade_level,
                    :description,
                    :active,
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute(
            $this->writeParameters(
                $companyId,
                $values,
                $createdBy
            )
        );

        $lookup = $this->connection()->prepare(
            'SELECT job_title_id
             FROM organization_job_titles
             WHERE company_id = :company_id
               AND code = :code
               AND deleted_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
        );
        $lookup->execute([
            'company_id' => $companyId,
            'code' => $values['code'],
        ]);

        return (int) $lookup->fetchColumn();
    }

    public function update(
        int $companyId,
        int $jobTitleId,
        array $values,
        int $updatedBy
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE organization_job_titles
             SET code = :code,
                 name = :name,
                 job_family = :job_family,
                 grade_level = :grade_level,
                 description = :description,
                 active = :active,
                 updated_by = :updated_by,
                 updated_at = SYSTIMESTAMP
             WHERE company_id = :company_id
               AND job_title_id = :job_title_id
               AND deleted_at IS NULL'
        );
        $parameters = $this->writeParameters(
            $companyId,
            $values,
            $updatedBy
        );
        unset($parameters['created_by']);
        $parameters['job_title_id'] = $jobTitleId;
        $statement->execute($parameters);

        return $statement->rowCount() > 0;
    }

    private function valueExists(
        int $companyId,
        string $column,
        string $value,
        ?int $ignoreJobTitleId
    ): bool {
        if (!in_array(
            $column,
            ['code', 'name'],
            true
        )) {
            throw new \InvalidArgumentException(
                'Unsupported job-title uniqueness column.'
            );
        }

        $sql = 'SELECT 1
                FROM organization_job_titles
                WHERE company_id = :company_id
                  AND ' . $column . ' = :value
                  AND deleted_at IS NULL';
        $parameters = [
            'company_id' => $companyId,
            'value' => $value,
        ];

        if ($ignoreJobTitleId !== null) {
            $sql .= '
                  AND job_title_id <>
                      :ignore_job_title_id';
            $parameters['ignore_job_title_id'] =
                $ignoreJobTitleId;
        }

        $sql .= '
                FETCH FIRST 1 ROWS ONLY';
        $statement = $this->connection()->prepare(
            $sql
        );
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function writeParameters(
        int $companyId,
        array $values,
        int $actorId
    ): array {
        return [
            'company_id' => $companyId,
            'code' => $values['code'],
            'name' => $values['name'],
            'job_family' => $values['job_family'],
            'grade_level' => $values['grade_level'],
            'description' => $values['description'],
            'active' => !empty($values['active'])
                ? 1
                : 0,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ];
    }
}
