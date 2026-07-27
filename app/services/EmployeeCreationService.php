<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use DateTimeImmutable;
use PDOException;
use Throwable;

final class EmployeeCreationService
{
    private const EMPLOYMENT_TYPES = [
        'full_time' => 'Full Time',
        'part_time' => 'Part Time',
        'contract' => 'Contract',
        'temporary' => 'Temporary',
        'intern' => 'Intern',
    ];

    private const EMPLOYMENT_STATUSES = [
        'active' => 'Active',
        'on_leave' => 'On Leave',
        'suspended' => 'Suspended',
        'terminated' => 'Terminated',
    ];

    private Employee $employees;
    private Department $departments;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->employees = new Employee();
        $this->departments = new Department();
        $this->auditLogs = new AuditLog();
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        $managers = $this->employees
            ->managerOptions();

        foreach ($managers as &$manager) {
            $preferredName = trim((string) (
                $manager['preferred_name'] ?? ''
            ));
            $firstName = trim((string) (
                $manager['first_name'] ?? ''
            ));
            $lastName = trim((string) (
                $manager['last_name'] ?? ''
            ));
            $manager['display_name'] = trim(
                ($preferredName !== ''
                    ? $preferredName
                    : $firstName)
                . ' '
                . $lastName
            );
        }

        unset($manager);

        return [
            'departments' =>
                $this->departments->activeOptions(),
            'managers' => $managers,
            'users' =>
                $this->employees
                    ->availableUserOptions(),
            'employmentTypes' =>
                self::EMPLOYMENT_TYPES,
            'employmentStatuses' =>
                self::EMPLOYMENT_STATUSES,
        ];
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
        $values = $this->normalize($input);
        $errors = $this->validate($values);

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
                [
                    'employee_number' =>
                        $values['employee_number'],
                    'user_id' => $values['user_id'],
                    'first_name' =>
                        $values['first_name'],
                    'middle_name' =>
                        $values['middle_name'],
                    'last_name' =>
                        $values['last_name'],
                    'preferred_name' =>
                        $values['preferred_name'],
                    'work_email' =>
                        $values['work_email'],
                    'work_phone' =>
                        $values['work_phone'],
                    'department_id' =>
                        $values['department_id'],
                    'job_title' =>
                        $values['job_title'],
                    'employment_type' =>
                        $values['employment_type'],
                    'employment_status' =>
                        $values['employment_status'],
                    'hire_date' =>
                        $values['hire_date'],
                    'termination_date' =>
                        $values['termination_date'],
                    'manager_employee_id' =>
                        $values[
                            'manager_employee_id'
                        ],
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
                $values['first_name']
                . ' '
                . $values['last_name']
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $userIdInput = trim((string) (
            $input['user_id'] ?? ''
        ));
        $managerIdInput = trim((string) (
            $input['manager_employee_id'] ?? ''
        ));

        return [
            'employee_number' => strtoupper(trim(
                (string) (
                    $input['employee_number'] ?? ''
                )
            )),
            'user_id' => $this->optionalId(
                $userIdInput
            ),
            'user_id_supplied' =>
                $userIdInput !== '',
            'first_name' => trim((string) (
                $input['first_name'] ?? ''
            )),
            'middle_name' => $this->optionalString(
                $input['middle_name'] ?? null
            ),
            'last_name' => trim((string) (
                $input['last_name'] ?? ''
            )),
            'preferred_name' =>
                $this->optionalString(
                    $input['preferred_name'] ?? null
                ),
            'work_email' => strtolower(trim(
                (string) (
                    $input['work_email'] ?? ''
                )
            )),
            'work_phone' => $this->optionalString(
                $input['work_phone'] ?? null
            ),
            'department_id' => $this->optionalId(
                $input['department_id'] ?? null
            ),
            'job_title' => trim((string) (
                $input['job_title'] ?? ''
            )),
            'employment_type' => trim((string) (
                $input['employment_type'] ?? ''
            )),
            'employment_status' => trim((string) (
                $input['employment_status'] ?? ''
            )),
            'hire_date' => trim((string) (
                $input['hire_date'] ?? ''
            )),
            'termination_date' =>
                $this->optionalString(
                    $input['termination_date'] ?? null
                ),
            'manager_employee_id' =>
                $this->optionalId(
                    $managerIdInput
                ),
            'manager_employee_id_supplied' =>
                $managerIdInput !== '',
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private function validate(array $values): array
    {
        $errors = [];
        $employeeNumber = (string) (
            $values['employee_number'] ?? ''
        );

        if (
            preg_match(
                '/^[A-Z0-9][A-Z0-9._\\/-]{1,49}$/',
                $employeeNumber
            ) !== 1
        ) {
            $errors['employee_number'] =
                'Employee number must contain 2-50 letters, numbers, dots, slashes, hyphens or underscores.';
        } elseif (
            $this->employees
                ->employeeNumberExists($employeeNumber)
        ) {
            $errors['employee_number'] =
                'That employee number is already in use.';
        }

        foreach (
            [
                'first_name' => 'First name',
                'last_name' => 'Last name',
            ] as $field => $label
        ) {
            $this->validateName(
                $values[$field] ?? null,
                $field,
                $label,
                true,
                $errors
            );
        }

        foreach (
            [
                'middle_name' => 'Middle name',
                'preferred_name' =>
                    'Preferred name',
            ] as $field => $label
        ) {
            $this->validateName(
                $values[$field] ?? null,
                $field,
                $label,
                false,
                $errors
            );
        }

        $workEmail = (string) (
            $values['work_email'] ?? ''
        );

        if (
            filter_var(
                $workEmail,
                FILTER_VALIDATE_EMAIL
            ) === false
            || strlen($workEmail) > 190
        ) {
            $errors['work_email'] =
                'Enter a valid work email address.';
        } elseif (
            $this->employees
                ->workEmailExists($workEmail)
        ) {
            $errors['work_email'] =
                'That work email is already in use.';
        }

        $workPhone = $values['work_phone'] ?? null;

        if (
            is_string($workPhone)
            && (
                mb_strlen($workPhone) > 40
                || preg_match(
                    '/^[0-9+() .-]+$/',
                    $workPhone
                ) !== 1
            )
        ) {
            $errors['work_phone'] =
                'Enter a valid phone number containing at most 40 characters.';
        }

        $departmentId = (int) (
            $values['department_id'] ?? 0
        );

        if (
            $departmentId < 1
            || !$this->departments
                ->activeExists($departmentId)
        ) {
            $errors['department_id'] =
                'Select a valid active department.';
        }

        $jobTitle = (string) (
            $values['job_title'] ?? ''
        );
        $jobTitleLength = mb_strlen($jobTitle);

        if (
            $jobTitleLength < 2
            || $jobTitleLength > 120
        ) {
            $errors['job_title'] =
                'Job title must contain 2-120 characters.';
        }

        if (!array_key_exists(
            (string) $values['employment_type'],
            self::EMPLOYMENT_TYPES
        )) {
            $errors['employment_type'] =
                'Select a valid employment type.';
        }

        $status = (string) (
            $values['employment_status'] ?? ''
        );

        if (!array_key_exists(
            $status,
            self::EMPLOYMENT_STATUSES
        )) {
            $errors['employment_status'] =
                'Select a valid employment status.';
        }

        $hireDate = (string) (
            $values['hire_date'] ?? ''
        );

        if (!$this->validDate($hireDate)) {
            $errors['hire_date'] =
                'Enter a valid hire date.';
        }

        $terminationDate =
            $values['termination_date'] ?? null;

        if ($status === 'terminated') {
            if (
                !is_string($terminationDate)
                || !$this->validDate($terminationDate)
            ) {
                $errors['termination_date'] =
                    'A valid termination date is required for a terminated employee.';
            } elseif (
                $this->validDate($hireDate)
                && $terminationDate < $hireDate
            ) {
                $errors['termination_date'] =
                    'Termination date cannot be earlier than the hire date.';
            }
        } elseif ($terminationDate !== null) {
            $errors['termination_date'] =
                'Termination date is only allowed when employment status is Terminated.';
        }

        $managerId = $values[
            'manager_employee_id'
        ] ?? null;

        if (
            !empty(
                $values[
                    'manager_employee_id_supplied'
                ]
            )
            && !is_int($managerId)
        ) {
            $errors['manager_employee_id'] =
                'Select a valid active manager.';
        } elseif (
            is_int($managerId)
            && !$this->employees
                ->managerExists($managerId)
        ) {
            $errors['manager_employee_id'] =
                'Select a valid active manager.';
        }

        $userId = $values['user_id'] ?? null;

        if (
            !empty($values['user_id_supplied'])
            && !is_int($userId)
        ) {
            $errors['user_id'] =
                'Select an active ERP account that is not already linked.';
        } elseif (
            is_int($userId)
            && !$this->employees
                ->availableUserExists($userId)
        ) {
            $errors['user_id'] =
                'Select an active ERP account that is not already linked.';
        }

        return $errors;
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateName(
        mixed $value,
        string $field,
        string $label,
        bool $required,
        array &$errors
    ): void {
        if ($value === null || $value === '') {
            if ($required) {
                $errors[$field] =
                    $label . ' is required.';
            }

            return;
        }

        if (
            !is_string($value)
            || mb_strlen($value) > 80
            || preg_match(
                '/^[\\p{L}\\p{M}][\\p{L}\\p{M} .\'-]*$/u',
                $value
            ) !== 1
        ) {
            $errors[$field] =
                $label
                . ' contains unsupported characters or exceeds 80 characters.';
        }
    }

    private function validDate(string $value): bool
    {
        if (
            preg_match(
                '/^\\d{4}-\\d{2}-\\d{2}$/',
                $value
            ) !== 1
        ) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && (
                $errors === false
                || (
                    $errors['warning_count'] === 0
                    && $errors['error_count'] === 0
                )
            )
            && $date->format('Y-m-d') === $value;
    }

    private function optionalId(mixed $value): ?int
    {
        if (
            is_int($value)
            && $value > 0
        ) {
            return $value;
        }

        if (
            is_string($value)
            && ctype_digit($value)
            && (int) $value > 0
        ) {
            return (int) $value;
        }

        return null;
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
