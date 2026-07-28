<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Repositories\DepartmentRepository;
use App\Repositories\RepositoryFactory;
use DateTimeImmutable;

final class EmployeeRecordValidator
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
    private DepartmentRepository $departments;
    private TenantContext $tenant;

    public function __construct()
    {
        $this->employees = new Employee();
        $this->departments =
            RepositoryFactory::departments();
        $this->tenant = new TenantContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptions(
        ?int $currentEmployeeId = null
    ): array {
        $companyId = $this->tenant->companyId();
        $departments =
            $this->departments->activeOptions(
                $companyId
            );
        $currentEmployee = null;
        $currentManagerId = null;

        if ($currentEmployeeId !== null) {
            $currentEmployee = $this->employees
                ->find(
                    $companyId,
                    $currentEmployeeId
                );
            $currentManagerId = (int) (
                $currentEmployee[
                    'manager_employee_id'
                ] ?? 0
            );
            $currentDepartmentId = (int) (
                $currentEmployee['department_id']
                ?? 0
            );
            $departmentIds = array_map(
                static fn (array $department): int =>
                    (int) $department['department_id'],
                $departments
            );

            if (
                $currentDepartmentId > 0
                && !in_array(
                    $currentDepartmentId,
                    $departmentIds,
                    true
                )
            ) {
                $currentDepartment =
                    $this->departments->find(
                        $companyId,
                        $currentDepartmentId
                    );

                if ($currentDepartment !== null) {
                    $currentDepartment['name'] =
                        (string) $currentDepartment['name']
                        . ' (Inactive)';
                    $departments[] = $currentDepartment;
                }
            }
        }

        $managers = $this->employees
            ->managerOptions(
                $companyId,
                $currentEmployeeId,
                $currentManagerId > 0
                    ? $currentManagerId
                    : null
            );

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

            if (
                (int) $manager['employee_id']
                    === $currentManagerId
                && (
                    $manager['employment_status']
                    ?? null
                ) === 'terminated'
            ) {
                $manager['display_name'] .=
                    ' (Terminated)';
            }
        }

        unset($manager);

        return [
            'departments' => $departments,
            'managers' => $managers,
            'users' =>
                $this->employees->availableUserOptions(
                    $companyId,
                    $currentEmployeeId
                ),
            'employmentTypes' =>
                self::EMPLOYMENT_TYPES,
            'employmentStatuses' =>
                self::EMPLOYMENT_STATUSES,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
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
                $this->optionalId($managerIdInput),
            'manager_employee_id_supplied' =>
                $managerIdInput !== '',
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    public function validate(
        array $values,
        ?int $currentEmployeeId = null
    ): array {
        $companyId = $this->tenant->companyId();
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
            $this->employees->employeeNumberExists(
                $companyId,
                $employeeNumber,
                $currentEmployeeId
            )
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
            $this->employees->workEmailExists(
                $companyId,
                $workEmail,
                $currentEmployeeId
            )
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
        $departmentIsCurrent = false;

        if (
            $currentEmployeeId !== null
            && $departmentId > 0
        ) {
            $currentEmployee = $this->employees
                ->find(
                    $companyId,
                    $currentEmployeeId
                );
            $departmentIsCurrent =
                (int) (
                    $currentEmployee['department_id']
                    ?? 0
                ) === $departmentId;
        }

        if (
            $departmentId < 1
            || (
                !$departmentIsCurrent
                && !$this->departments
                    ->activeExists(
                        $companyId,
                        $departmentId
                    )
            )
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
        $managerIsCurrent = false;

        if (
            $currentEmployeeId !== null
            && is_int($managerId)
        ) {
            $currentEmployee = $this->employees
                ->find(
                    $companyId,
                    $currentEmployeeId
                );
            $managerIsCurrent =
                (int) (
                    $currentEmployee[
                        'manager_employee_id'
                    ] ?? 0
                ) === $managerId;
        }

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
            && !$managerIsCurrent
            && !$this->employees
                ->managerExists(
                    $companyId,
                    $managerId
                )
        ) {
            $errors['manager_employee_id'] =
                'Select a valid active manager.';
        } elseif (
            $currentEmployeeId !== null
            && is_int($managerId)
            && !$managerIsCurrent
            && $this->employees
                ->wouldCreateManagerCycle(
                    $companyId,
                    $currentEmployeeId,
                    $managerId
                )
        ) {
            $errors['manager_employee_id'] =
                'This manager assignment would create a circular reporting relationship.';
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
                ->availableUserExists(
                    $companyId,
                    $userId,
                    $currentEmployeeId
                )
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
