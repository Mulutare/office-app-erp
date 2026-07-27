<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use PDOException;
use Throwable;

final class EmployeeCreationService
{
    private Employee $employees;
    private EmployeeRecordValidator $validator;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->employees = new Employee();
        $this->validator =
            new EmployeeRecordValidator();
        $this->auditLogs = new AuditLog();
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        return $this->validator->formOptions();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     employeeId?: int,
     *     employeeName?: string
     * }
     */
    public function create(
        array $input,
        int $createdBy
    ): array {
        $values = $this->validator
            ->normalize($input);
        $errors = $this->validator
            ->validate($values);

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

            $employeeId = $this->employees->create(
                $values,
                $createdBy
            );

            $this->auditLogs->record(
                $createdBy,
                'CREATE',
                'hr',
                'hr_employees',
                (string) $employeeId,
                null,
                $this->auditValues($values)
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
                            'The employee number, work email or linked account conflicts with an existing employee.',
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
            'employeeId' => $employeeId,
            'employeeName' => trim(
                (string) $values['first_name']
                . ' '
                . (string) $values['last_name']
            ),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function auditValues(array $values): array
    {
        $fields = [
            'employee_number',
            'user_id',
            'first_name',
            'middle_name',
            'last_name',
            'preferred_name',
            'work_email',
            'work_phone',
            'department_id',
            'job_title',
            'employment_type',
            'employment_status',
            'hire_date',
            'termination_date',
            'manager_employee_id',
        ];
        $auditValues = [];

        foreach ($fields as $field) {
            $auditValues[$field] =
                $values[$field] ?? null;
        }

        return $auditValues;
    }
}
