<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\LeaveRepository;
use App\Repositories\RepositoryFactory;
use PDOException;
use Throwable;

final class LeavePolicyService
{
    private const WORKFLOWS = [
        'none' => 'No approval',
        'manager' => 'Manager only',
        'hr' => 'HR only',
        'manager_then_hr' => 'Manager, then HR',
    ];

    private LeaveRepository $leave;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?LeaveRepository $leave = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->leave = $leave
            ?? RepositoryFactory::leave();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array{
     *     policies: list<array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         active: int,
     *         approvalRequired: int,
     *         requests: int
     *     }
     * }
     */
    public function listing(): array
    {
        $policies = $this->leave
            ->leaveTypeCatalog(
                $this->tenant->companyId()
            );
        $active = 0;
        $approvalRequired = 0;
        $requests = 0;

        foreach ($policies as &$policy) {
            $policy['leave_type_id'] = (int) (
                $policy['leave_type_id'] ?? 0
            );
            $policy['active'] =
                !empty($policy['active']);
            $policy['requires_approval'] =
                !empty($policy['requires_approval']);
            $workflow = (string) (
                $policy['approval_workflow']
                    ?? ($policy['requires_approval']
                        ? 'manager'
                        : 'none')
            );
            $policy['approval_workflow'] = $workflow;
            $policy['approvalWorkflowLabel'] =
                self::WORKFLOWS[$workflow]
                    ?? 'Manager only';
            $policy['annual_entitlement'] =
                $this->entitlement(
                    $policy['annual_entitlement']
                        ?? 0
                );
            $policy['request_count'] = (int) (
                $policy['request_count'] ?? 0
            );
            $policy['pending_request_count'] =
                (int) (
                    $policy[
                        'pending_request_count'
                    ] ?? 0
                );
            $active += $policy['active'] ? 1 : 0;
            $approvalRequired +=
                $policy['requires_approval']
                    ? 1
                    : 0;
            $requests += $policy['request_count'];
        }

        unset($policy);

        return [
            'policies' => $policies,
            'summary' => [
                'total' => count($policies),
                'active' => $active,
                'approvalRequired' =>
                    $approvalRequired,
                'requests' => $requests,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function form(int $leaveTypeId): ?array
    {
        $policy = $this->leave
            ->leaveTypeForManagement(
                $this->tenant->companyId(),
                $leaveTypeId
            );

        if ($policy === null) {
            return null;
        }

        return $this->recordValues($policy)
            + [
                'leave_type_id' => (int) (
                    $policy['leave_type_id'] ?? 0
                ),
            ];
    }

    /**
     * @return array<string, string>
     */
    public function workflowOptions(): array
    {
        return self::WORKFLOWS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function hrApprovers(): array
    {
        return $this->leave->hrApproverOptions(
            $this->tenant->companyId()
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
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

            $leaveTypeId = $this->leave
                ->createLeaveType(
                    $companyId,
                    $values,
                    $createdBy
                );
            $this->auditLogs->record(
                $createdBy,
                'CREATE_LEAVE_POLICY',
                'hr',
                'hr_leave_types',
                (string) $leaveTypeId,
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
            'leaveTypeId' => $leaveTypeId,
            'policyName' => (string) $values['name'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(
        int $leaveTypeId,
        array $input,
        int $updatedBy
    ): array {
        $companyId = $this->tenant->companyId();
        $policy = $this->leave
            ->leaveTypeForManagement(
                $companyId,
                $leaveTypeId
            );

        if ($policy === null) {
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
            $leaveTypeId
        );

        if (
            $errors === []
            && !empty($policy['active'])
            && empty($values['active'])
            && $this->leave
                ->leaveTypeHasPendingRequests(
                    $companyId,
                    $leaveTypeId
                )
        ) {
            $errors['active'] =
                'Resolve pending requests before deactivating this leave policy.';
        }

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $oldValues = $this->recordValues($policy);
        $newValues = $this->recordValues($values);

        if ($oldValues === $newValues) {
            return [
                'successful' => true,
                'errors' => [],
                'changed' => false,
                'leaveTypeId' => $leaveTypeId,
                'policyName' =>
                    (string) $values['name'],
            ];
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $updated = $this->leave
                ->updateLeaveType(
                    $companyId,
                    $leaveTypeId,
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
                'UPDATE_LEAVE_POLICY',
                'hr',
                'hr_leave_types',
                (string) $leaveTypeId,
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
            'changed' => true,
            'leaveTypeId' => $leaveTypeId,
            'policyName' => (string) $values['name'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $entitlement = trim((string) (
            $input['annual_entitlement'] ?? ''
        ));
        $workflow = strtolower(trim((string) (
            $input['approval_workflow']
                ?? (!empty($input['requires_approval'])
                    ? 'manager'
                    : 'none')
        )));
        $hrApproverUserId = $this->integer(
            $input['hr_approver_user_id'] ?? null
        );

        return [
            'code' => strtoupper(trim((string) (
                $input['code'] ?? ''
            ))),
            'name' => trim((string) (
                $input['name'] ?? ''
            )),
            'annual_entitlement' =>
                is_numeric($entitlement)
                    ? $this->entitlement(
                        $entitlement
                    )
                    : $entitlement,
            'requires_approval' =>
                $workflow !== 'none',
            'approval_workflow' => $workflow,
            'hr_approver_user_id' =>
                $hrApproverUserId > 0
                    ? $hrApproverUserId
                    : null,
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
        ?int $ignoreLeaveTypeId = null
    ): array {
        $errors = [];
        $code = (string) $values['code'];
        $name = (string) $values['name'];
        $entitlement = (string) (
            $values['annual_entitlement'] ?? ''
        );
        $workflow = (string) (
            $values['approval_workflow'] ?? ''
        );

        if (
            preg_match(
                '/^[A-Z][A-Z0-9_-]{1,29}$/',
                $code
            ) !== 1
        ) {
            $errors['code'] =
                'Code must contain 2-30 uppercase letters, numbers, hyphens or underscores and begin with a letter.';
        } elseif (
            $this->leave->leaveTypeCodeExists(
                $companyId,
                $code,
                $ignoreLeaveTypeId
            )
        ) {
            $errors['code'] =
                'That leave-policy code is already in use.';
        }

        if (
            mb_strlen($name) < 2
            || mb_strlen($name) > 100
        ) {
            $errors['name'] =
                'Policy name must contain 2-100 characters.';
        } elseif (
            $this->leave->leaveTypeNameExists(
                $companyId,
                $name,
                $ignoreLeaveTypeId
            )
        ) {
            $errors['name'] =
                'That leave-policy name is already in use.';
        }

        if (
            preg_match(
                '/^\d{1,3}(?:\.\d{1,2})?$/',
                $entitlement
            ) !== 1
            || (float) $entitlement > 366
        ) {
            $errors['annual_entitlement'] =
                'Annual entitlement must be between 0 and 366 days with no more than two decimal places.';
        }

        if (!isset(self::WORKFLOWS[$workflow])) {
            $errors['approval_workflow'] =
                'Select a valid approval workflow.';
        } elseif (in_array(
            $workflow,
            ['hr', 'manager_then_hr'],
            true
        )) {
            $hrApproverId = (int) (
                $values['hr_approver_user_id'] ?? 0
            );
            $allowedApprovers = array_map(
                static fn (array $approver): int =>
                    (int) ($approver['user_id'] ?? 0),
                $this->leave->hrApproverOptions(
                    $companyId
                )
            );

            if (
                $hrApproverId < 1
                || !in_array(
                    $hrApproverId,
                    $allowedApprovers,
                    true
                )
            ) {
                $errors['hr_approver_user_id'] =
                    'Select an active HR approver with leave-approval permission in this company.';
            }
        }

        return $errors;
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
            'annual_entitlement' =>
                $this->entitlement(
                    $record['annual_entitlement']
                        ?? 0
                ),
            'requires_approval' =>
                !empty($record['requires_approval']),
            'approval_workflow' => (string) (
                $record['approval_workflow']
                    ?? (!empty(
                        $record['requires_approval']
                    )
                        ? 'manager'
                        : 'none')
            ),
            'hr_approver_user_id' => (
                (int) (
                    $record['hr_approver_user_id']
                        ?? 0
                )
            ) > 0
                ? (int) $record[
                    'hr_approver_user_id'
                ]
                : null,
            'active' => !empty($record['active']),
        ];
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : 0;
    }

    private function entitlement(mixed $value): string
    {
        return number_format(
            (float) $value,
            2,
            '.',
            ''
        );
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
                    'A leave policy with that code or name already exists.',
            ],
        ];
    }
}
