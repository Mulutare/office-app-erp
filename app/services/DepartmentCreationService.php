<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Department;
use PDOException;
use Throwable;

final class DepartmentCreationService
{
    private Department $departments;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->departments = new Department();
        $this->auditLogs = new AuditLog();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     departmentId?: int,
     *     departmentName?: string
     * }
     */
    public function create(
        array $input,
        int $createdBy
    ): array {
        $code = strtoupper(trim((string) (
            $input['code'] ?? ''
        )));
        $name = trim((string) (
            $input['name'] ?? ''
        ));
        $description = trim((string) (
            $input['description'] ?? ''
        ));
        $active = !empty($input['active']);
        $errors = $this->validate(
            $code,
            $name,
            $description
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

            $departmentId =
                $this->departments->create(
                    $code,
                    $name,
                    $description,
                    $active,
                    $createdBy
                );

            $this->auditLogs->record(
                $createdBy,
                'CREATE',
                'hr',
                'hr_departments',
                (string) $departmentId,
                null,
                [
                    'code' => $code,
                    'name' => $name,
                    'description' =>
                        $description === ''
                            ? null
                            : $description,
                    'active' => $active,
                ]
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
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'A department with that code or name already exists.',
                    ],
                ];
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
            'departmentId' => $departmentId,
            'departmentName' => $name,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validate(
        string $code,
        string $name,
        string $description
    ): array {
        $errors = [];

        if (
            preg_match(
                '/^[A-Z][A-Z0-9_-]{1,29}$/',
                $code
            ) !== 1
        ) {
            $errors['code'] =
                'Code must contain 2-30 uppercase letters, numbers, hyphens or underscores and begin with a letter.';
        } elseif (
            $this->departments->codeExists($code)
        ) {
            $errors['code'] =
                'That department code is already in use.';
        }

        $nameLength = mb_strlen($name);

        if (
            $nameLength < 2
            || $nameLength > 100
        ) {
            $errors['name'] =
                'Department name must contain 2-100 characters.';
        } elseif (
            $this->departments->nameExists($name)
        ) {
            $errors['name'] =
                'That department name is already in use.';
        }

        if (mb_strlen($description) > 255) {
            $errors['description'] =
                'Description cannot exceed 255 characters.';
        }

        return $errors;
    }
}
