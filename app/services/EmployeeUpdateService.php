<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use PDOException;
use Throwable;

final class EmployeeUpdateService
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
     * @return array<string, mixed>|null
     */
    public function form(int $employeeId): ?array
    {
        $employee = $this->employees->find(
            $employeeId
        );

        if ($employee === null) {
            return null;
        }

        return [
            'employee' => $employee,
            'values' => $this->recordValues(
                $employee
            ),
            'options' =>
                $this->validator->formOptions(
                    $employeeId
                ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     employeeId?: int,
     *     employeeName?: string,
     *     changed?: bool
     * }
     */
    public function update(
        int $employeeId,
        array $input,
        int $updatedBy
    ): array {
        $employee = $this->employees->find(
            $employeeId
        );

        if ($employee === null) {
            return [
                'successful' => false,
                'errors' => [
                    'form' =>
                        'The employee record no longer exists.',
                ],
            ];
        }

        $values = $this->validator
            ->normalize($input);
        $errors = $this->validator->validate(
            $values,
            $employeeId
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $oldValues = $this->recordValues($employee);
        $newValues = $this->recordValues($values);

        if ($oldValues === $newValues) {
            return [
                'successful' => true,
                'errors' => [],
                'employeeId' => $employeeId,
                'employeeName' => trim(
                    (string) $values['first_name']
                    . ' '
                    . (string) $values['last_name']
                ),
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

            $this->employees->update(
                $employeeId,
                $values,
                $updatedBy
            );
            $this->auditLogs->record(
                $updatedBy,
                'UPDATE',
                'hr',
                'hr_employees',
                (string) $employeeId,
                $oldValues,
                $newValues
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
            'changed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function recordValues(array $record): array
    {
        return [
            'employee_number' => (string) (
                $record['employee_number'] ?? ''
            ),
            'user_id' => $this->nullableInteger(
                $record['user_id'] ?? null
            ),
            'first_name' => (string) (
                $record['first_name'] ?? ''
            ),
            'middle_name' => $this->nullableString(
                $record['middle_name'] ?? null
            ),
            'last_name' => (string) (
                $record['last_name'] ?? ''
            ),
            'preferred_name' =>
                $this->nullableString(
                    $record['preferred_name'] ?? null
                ),
            'work_email' => (string) (
                $record['work_email'] ?? ''
            ),
            'work_phone' => $this->nullableString(
                $record['work_phone'] ?? null
            ),
            'department_id' => $this->nullableInteger(
                $record['department_id'] ?? null
            ),
            'job_title' => (string) (
                $record['job_title'] ?? ''
            ),
            'employment_type' => (string) (
                $record['employment_type'] ?? ''
            ),
            'employment_status' => (string) (
                $record['employment_status'] ?? ''
            ),
            'hire_date' => (string) (
                $record['hire_date'] ?? ''
            ),
            'termination_date' =>
                $this->nullableString(
                    $record['termination_date']
                    ?? null
                ),
            'manager_employee_id' =>
                $this->nullableInteger(
                    $record[
                        'manager_employee_id'
                    ] ?? null
                ),
        ];
    }

    private function nullableInteger(
        mixed $value
    ): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(
        mixed $value
    ): ?string {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
