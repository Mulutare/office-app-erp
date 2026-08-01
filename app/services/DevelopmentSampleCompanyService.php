<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * Creates one complete development tenant for manual product verification.
 */
final class DevelopmentSampleCompanyService
{
    public const COMPANY_CODE = 'sample-company';
    public const OWNER_USERNAME = 'sample.owner';
    public const EMPLOYEE_USERNAME = 'sample.employee';

    private Company $companies;
    private User $users;
    private CompanyProvisioningService $provisioning;
    private DepartmentCatalogueService $departments;
    private EmployeeCreationService $employees;
    private UserCreationService $userCreation;

    public function __construct()
    {
        $this->companies = new Company();
        $this->users = new User();
        $this->provisioning =
            new CompanyProvisioningService();
        $this->departments =
            new DepartmentCatalogueService();
        $this->employees =
            new EmployeeCreationService();
        $this->userCreation =
            new UserCreationService();
    }

    /**
     * @return array{
     *     companyId: int,
     *     companyCode: string,
     *     ownerUsername: string,
     *     ownerTemporaryPassword: string,
     *     employeeUsername: string,
     *     employeeTemporaryPassword: string
     * }
     */
    public function create(int $platformAdminId): array
    {
        $administrator = $this->users
            ->findById($platformAdminId);

        if (
            $administrator === null
            || empty(
                $administrator[
                    'is_platform_admin'
                ]
            )
            || empty($administrator['active'])
        ) {
            throw new RuntimeException(
                'An active platform administrator is required.'
            );
        }

        if ($this->companies->codeExists(
            self::COMPANY_CODE
        )) {
            throw new RuntimeException(
                'The development sample company already exists.'
            );
        }

        $connection = \db();
        $previousAuth = $_SESSION['auth'] ?? null;

        try {
            $connection->beginTransaction();
            $companyResult =
                $this->provisioning->create(
                    $this->companyInput(),
                    $platformAdminId
                );
            $this->requireSuccess(
                $companyResult,
                'The sample company could not be created.'
            );
            $companyId = (int) (
                $companyResult['companyId'] ?? 0
            );
            $approval = $this->provisioning
                ->approve(
                    $companyId,
                    $platformAdminId
                );
            $this->requireSuccess(
                $approval,
                'The sample company could not be approved.'
            );
            $owner = $this->users
                ->findForAuthentication(
                    self::OWNER_USERNAME
                );

            if ($owner === null) {
                throw new RuntimeException(
                    'The sample company owner was not created.'
                );
            }

            $ownerUserId = (int) $owner['user_id'];
            $_SESSION['auth'] = [
                'user_id' => $ownerUserId,
                'company' => [
                    'company_id' => $companyId,
                ],
            ];
            $department = $this->departments
                ->create(
                    [
                        'code' => 'GENERAL',
                        'name' =>
                            'General Operations',
                        'description' =>
                            'Default department for the development sample tenant.',
                        'active' => true,
                    ],
                    $ownerUserId
                );
            $this->requireSuccess(
                $department,
                'The sample department could not be created.'
            );
            $departmentId = (int) (
                $department['departmentId'] ?? 0
            );
            $employeeRoleId =
                $this->employeeRoleId();
            $employeeAccount =
                $this->userCreation->create(
                    [
                        'username' =>
                            self::EMPLOYEE_USERNAME,
                        'email' =>
                            'sample.employee@example.test',
                        'display_name' =>
                            'Sample Employee',
                        'active' => true,
                        'manager_user_id' =>
                            $ownerUserId,
                        'role_ids' => [
                            $employeeRoleId,
                        ],
                    ],
                    $ownerUserId
                );
            $this->requireSuccess(
                $employeeAccount,
                'The sample employee account could not be created.'
            );
            $employeeUserId = (int) (
                $employeeAccount['userId'] ?? 0
            );
            $employeeProfile =
                $this->employees->create(
                    $this->employeeInput(
                        'SAMPLE-002',
                        $employeeUserId,
                        'Sample',
                        'Employee',
                        'sample.employee@example.test',
                        'Operations Assistant',
                        $departmentId,
                        null
                    ),
                    $ownerUserId
                );
            $this->requireSuccess(
                $employeeProfile,
                'The sample employee profile could not be created.'
            );
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            $this->restoreAuth($previousAuth);

            throw $exception;
        }

        $this->restoreAuth($previousAuth);

        return [
            'companyId' => $companyId,
            'companyCode' => self::COMPANY_CODE,
            'ownerUsername' =>
                self::OWNER_USERNAME,
            'ownerTemporaryPassword' =>
                (string) $companyResult[
                    'temporaryPassword'
                ],
            'employeeUsername' =>
                self::EMPLOYEE_USERNAME,
            'employeeTemporaryPassword' =>
                (string) $employeeAccount[
                    'temporaryPassword'
                ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function companyInput(): array
    {
        return [
            'code' => self::COMPANY_CODE,
            'name' => 'Sample Company',
            'legal_name' =>
                'Sample Company Limited',
            'contact_email' =>
                'operations@sample-company.example.test',
            'contact_phone' => '+254700000000',
            'country_code' => 'KE',
            'default_currency' => 'KES',
            'timezone' => 'Africa/Nairobi',
            'subscription_status' => 'active',
            'subscription_expires_at' => '',
            'brand_primary_color' => '#2563EB',
            'module_codes' => [
                'hr',
                'attendance',
                'sales',
            ],
            'owner_display_name' =>
                'Sample Company Owner',
            'owner_username' =>
                self::OWNER_USERNAME,
            'owner_email' =>
                'sample.owner@example.test',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeInput(
        string $employeeNumber,
        int $userId,
        string $firstName,
        string $lastName,
        string $email,
        string $jobTitle,
        int $departmentId,
        ?int $managerEmployeeId
    ): array {
        return [
            'employee_number' => $employeeNumber,
            'user_id' => (string) $userId,
            'first_name' => $firstName,
            'middle_name' => '',
            'last_name' => $lastName,
            'preferred_name' => '',
            'work_email' => $email,
            'work_phone' => '',
            'department_id' =>
                (string) $departmentId,
            'job_title' => $jobTitle,
            'employment_type' => 'full_time',
            'employment_status' => 'active',
            'hire_date' => date('Y-m-d'),
            'termination_date' => '',
            'manager_employee_id' =>
                $managerEmployeeId === null
                    ? ''
                    : (string) $managerEmployeeId,
        ];
    }

    private function employeeRoleId(): int
    {
        foreach (
            $this->userCreation->roles()
            as $role
        ) {
            if (
                ($role['code'] ?? null)
                === 'employee_self_service'
            ) {
                return (int) $role['role_id'];
            }
        }

        throw new RuntimeException(
            'The Employee Self Service role is not configured.'
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function requireSuccess(
        array $result,
        string $message
    ): void {
        if (!empty($result['successful'])) {
            return;
        }

        $errors = is_array($result['errors'] ?? null)
            ? $result['errors']
            : [];
        $detail = $errors === []
            ? ''
            : ' ' . implode(
                ' ',
                array_map('strval', $errors)
            );

        throw new RuntimeException(
            $message . $detail
        );
    }

    /**
     * @param mixed $previousAuth
     */
    private function restoreAuth(mixed $previousAuth): void
    {
        if (is_array($previousAuth)) {
            $_SESSION['auth'] = $previousAuth;

            return;
        }

        unset($_SESSION['auth']);
    }
}
