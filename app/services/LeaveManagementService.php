<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\LeaveRepository;
use App\Repositories\RepositoryFactory;
use DateInterval;
use DateTimeImmutable;
use Throwable;

final class LeaveManagementService
{
    private const STATUSES = [
        'pending' => [
            'label' => 'Pending',
            'tone' => 'warning',
        ],
        'approved' => [
            'label' => 'Approved',
            'tone' => 'success',
        ],
        'rejected' => [
            'label' => 'Rejected',
            'tone' => 'danger',
        ],
        'cancelled' => [
            'label' => 'Cancelled',
            'tone' => 'muted',
        ],
    ];

    private const WORKFLOWS = [
        'none' => 'No approval',
        'manager' => 'Manager only',
        'hr' => 'HR only',
        'manager_then_hr' => 'Manager, then HR',
    ];

    private const APPROVAL_STAGE_LABELS = [
        'manager' => 'Manager',
        'hr' => 'HR',
    ];

    private const APPROVAL_STATUS_PRESENTATION = [
        'waiting' => [
            'label' => 'Waiting',
            'tone' => 'muted',
        ],
        'pending' => [
            'label' => 'In review',
            'tone' => 'warning',
        ],
        'approved' => [
            'label' => 'Approved',
            'tone' => 'success',
        ],
        'rejected' => [
            'label' => 'Rejected',
            'tone' => 'danger',
        ],
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
     * @return array<string, mixed>
     */
    public function dashboard(
        string $status = ''
    ): array {
        if (
            $status !== ''
            && !isset(self::STATUSES[$status])
        ) {
            $status = '';
        }

        $companyId = $this->tenant->companyId();
        $allRequests = $this->leave->requests(
            $companyId
        );
        $requests = $status === ''
            ? $allRequests
            : array_values(array_filter(
                $allRequests,
                static fn (array $request): bool =>
                    ($request['request_status']
                        ?? '') === $status
            ));
        $this->attachApprovals(
            $companyId,
            $requests
        );

        foreach ($requests as &$request) {
            $this->present($request);
        }

        unset($request);

        return [
            'requests' => $requests,
            'leaveTypes' =>
                $this->leave->leaveTypes(
                    $companyId
                ),
            'employees' =>
                $this->presentEmployees(
                    $this->leave->employeeOptions(
                        $companyId
                    )
                ),
            'summary' =>
                $this->summaryFrom($allRequests),
            'statuses' => array_map(
                static fn (array $item): string =>
                    $item['label'],
                self::STATUSES
            ),
            'filterStatus' => $status,
        ];
    }

    /**
     * Build the leave workspace for one signed-in company user.
     *
     * @return array<string, mixed>
     */
    public function workspace(
        int $actorUserId,
        string $status,
        bool $canViewCompany,
        bool $canManageCompany,
        bool $canApproveCompany,
        bool $canRequestSelf,
        bool $canApproveTeam
    ): array {
        if (
            $status !== ''
            && !isset(self::STATUSES[$status])
        ) {
            $status = '';
        }

        $companyId = $this->tenant->companyId();
        $employee = $this->leave->employeeForUser(
            $companyId,
            $actorUserId
        );
        if ($employee !== null) {
            $approvalContext = $this->leave
                ->employeeApprovalContext(
                    $companyId,
                    (int) $employee['employee_id']
                );

            if ($approvalContext !== null) {
                $employee = array_merge(
                    $employee,
                    $approvalContext
                );
            }
        }
        $selfRequests = [];
        $teamRequests = [];

        if ($employee !== null) {
            $selfRequests =
                $this->leave->requestsForEmployee(
                    $companyId,
                    (int) $employee['employee_id'],
                    $status
                );
        }

        if ($canApproveTeam) {
            $teamRequests =
                $this->leave->requestsForManager(
                    $companyId,
                    $actorUserId,
                    $status
                );
        }

        $teamRequestIds = [];

        foreach ($teamRequests as $teamRequest) {
            $teamRequestIds[
                (int) (
                    $teamRequest['leave_request_id']
                    ?? 0
                )
            ] = true;
        }

        $requests = $canViewCompany
            ? $this->leave->requests(
                $companyId,
                $status
            )
            : $this->mergeRequests(
                $selfRequests,
                $teamRequests
            );
        $this->attachApprovals(
            $companyId,
            $requests
        );
        $selfEmployeeId = (int) (
            $employee['employee_id'] ?? 0
        );

        foreach ($requests as &$request) {
            $requestId = (int) (
                $request['leave_request_id'] ?? 0
            );
            $requestEmployeeId = (int) (
                $request['employee_id'] ?? 0
            );
            $isSelf = $selfEmployeeId > 0
                && $requestEmployeeId
                    === $selfEmployeeId;
            $isTeam = isset(
                $teamRequestIds[$requestId]
            );
            $currentApproverId = (int) (
                $request['currentApproverUserId']
                    ?? 0
            );
            $currentStage = (string) (
                $request['currentApprovalStage']
                    ?? ''
            );

            $request['scopeLabel'] = $isSelf
                ? 'My request'
                : ($isTeam
                    ? 'Direct report'
                    : 'Company');
            $request['canDecide'] =
                $currentApproverId === $actorUserId
                && (
                    (
                        $currentStage === 'manager'
                        && $canApproveTeam
                    )
                    || (
                        $currentStage === 'hr'
                        && $canApproveCompany
                    )
                );
            $this->present($request);
        }

        unset($request);

        if ($employee !== null) {
            $employee['displayName'] =
                $this->employeeName($employee);
        }

        return [
            'requests' => $requests,
            'leaveTypes' =>
                $this->leave->leaveTypes(
                    $companyId
                ),
            'employees' => $canManageCompany
                ? $this->presentEmployees(
                    $this->leave->employeeOptions(
                        $companyId
                    )
                )
                : [],
            'employee' => $employee,
            'summary' => $this->summaryFrom(
                $requests
            ),
            'statuses' => array_map(
                static fn (array $item): string =>
                    $item['label'],
                self::STATUSES
            ),
            'filterStatus' => $status,
            'scopeLabel' => $canViewCompany
                ? 'Company leave'
                : ($teamRequests !== []
                    ? 'My leave and direct reports'
                    : 'My leave'),
            'canManageCompany' =>
                $canManageCompany,
            'canRequestSelf' =>
                $canRequestSelf
                && $employee !== null,
            'canApprove' =>
                $canApproveCompany
                || (
                    $canApproveTeam
                    && $teamRequests !== []
                ),
            'profileRequired' =>
                !$canViewCompany
                && $employee === null,
        ];
    }

    /**
     * Resolve and validate the immutable approval route used by a request.
     *
     * @param array<string, mixed> $leaveType
     *
     * @return array{
     *     errors: array<string, string>,
     *     stages: list<array<string, mixed>>,
     *     audit: list<array<string, mixed>>
     * }
     */
    private function approvalRoute(
        int $companyId,
        int $employeeId,
        array $leaveType
    ): array {
        $workflow = (string) (
            $leaveType['approval_workflow']
                ?? (!empty(
                    $leaveType['requires_approval']
                )
                    ? 'manager'
                    : 'none')
        );
        $needsManager = in_array(
            $workflow,
            ['manager', 'manager_then_hr'],
            true
        );
        $needsHr = in_array(
            $workflow,
            ['hr', 'manager_then_hr'],
            true
        );
        $errors = [];
        $stages = [];
        $audit = [];
        $employee = $this->leave
            ->employeeApprovalContext(
                $companyId,
                $employeeId
            );

        if ($needsManager) {
            $managerUserId = (int) (
                $employee['manager_user_id'] ?? 0
            );
            $managerName = trim((string) (
                $employee['manager_name'] ?? ''
            ));

            if (
                $managerUserId < 1
                || $managerName === ''
            ) {
                $errors['approval_route'] =
                    'This employee has no active reporting manager. Assign a company manager before submitting this leave request.';
            } else {
                $stages[] = [
                    'stage' => 'manager',
                    'sequence' => 1,
                    'approverUserId' =>
                        $managerUserId,
                    'approverName' => $managerName,
                    'status' => 'pending',
                ];
                $audit[] = [
                    'stage' => 'manager',
                    'approver_user_id' =>
                        $managerUserId,
                    'approver_name' =>
                        $managerName,
                ];
            }
        }

        if ($needsHr) {
            $hrApproverId = (int) (
                $leaveType['hr_approver_user_id']
                    ?? 0
            );
            $allowedApprovers =
                $this->leave->hrApproverOptions(
                    $companyId
                );
            $hrApprover = null;

            foreach ($allowedApprovers as $candidate) {
                if (
                    (int) (
                        $candidate['user_id'] ?? 0
                    ) === $hrApproverId
                ) {
                    $hrApprover = $candidate;
                    break;
                }
            }

            if ($hrApprover === null) {
                $errors['approval_route'] =
                    'The selected leave policy has no active HR approver. Ask HR to update the policy before submitting.';
            } else {
                $hrName = (string) (
                    $hrApprover['display_name'] ?? ''
                );
                $sequence = $needsManager ? 2 : 1;
                $stages[] = [
                    'stage' => 'hr',
                    'sequence' => $sequence,
                    'approverUserId' =>
                        $hrApproverId,
                    'approverName' => $hrName,
                    'status' => $sequence === 1
                        ? 'pending'
                        : 'waiting',
                ];
                $audit[] = [
                    'stage' => 'hr',
                    'approver_user_id' =>
                        $hrApproverId,
                    'approver_name' => $hrName,
                ];
            }
        }

        return [
            'errors' => $errors,
            'stages' => $errors === []
                ? $stages
                : [],
            'audit' => $errors === []
                ? $audit
                : [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $requests
     */
    private function attachApprovals(
        int $companyId,
        array &$requests
    ): void {
        $requestIds = array_map(
            static fn (array $request): int =>
                (int) (
                    $request['leave_request_id'] ?? 0
                ),
            $requests
        );
        $rows = $this->leave
            ->approvalStagesForRequests(
                $companyId,
                $requestIds
            );
        $byRequest = [];

        foreach ($rows as $row) {
            $requestId = (int) (
                $row['leave_request_id'] ?? 0
            );
            $byRequest[$requestId][] = $row;
        }

        foreach ($requests as &$request) {
            $requestId = (int) (
                $request['leave_request_id'] ?? 0
            );
            $workflow = (string) (
                $request['approval_workflow']
                    ?? 'manager'
            );
            $stages = $byRequest[$requestId] ?? [];
            $request['approvalStages'] = $stages;
            $request['approvalWorkflowLabel'] =
                self::WORKFLOWS[$workflow]
                    ?? 'Manager only';
            $request['managerApproverName'] = null;
            $request['hrApproverName'] = null;
            $request['currentApprovalStage'] = null;
            $request['currentApproverUserId'] = null;
            $request['currentApproverName'] = null;
            $request['approvedStageCount'] = 0;
            $request['totalStageCount'] =
                count($stages);

            foreach ($stages as &$stage) {
                $stageCode = (string) (
                    $stage['approval_stage'] ?? ''
                );
                $stageStatus = (string) (
                    $stage['approval_status']
                        ?? 'waiting'
                );
                $approverName = (string) (
                    $stage['approver_name'] ?? ''
                );
                $stagePresentation =
                    self::APPROVAL_STATUS_PRESENTATION[
                        $stageStatus
                    ] ?? self::APPROVAL_STATUS_PRESENTATION[
                        'waiting'
                    ];
                $stage['stageLabel'] =
                    self::APPROVAL_STAGE_LABELS[
                        $stageCode
                    ] ?? ucfirst($stageCode);
                $stage['statusLabel'] =
                    $stagePresentation['label'];
                $stage['statusTone'] =
                    $stagePresentation['tone'];
                $stage['isCurrent'] =
                    $stageStatus === 'pending';

                if ($stageStatus === 'approved') {
                    $request['approvedStageCount']++;
                }

                if ($stageCode === 'manager') {
                    $request['managerApproverName'] =
                        $approverName;
                } elseif ($stageCode === 'hr') {
                    $request['hrApproverName'] =
                        $approverName;
                }

                if (
                    ($stage['approval_status'] ?? '')
                    === 'pending'
                ) {
                    $request['currentApprovalStage'] =
                        $stageCode;
                    $request['currentApproverUserId'] =
                        (int) (
                            $stage['approver_user_id']
                            ?? 0
                        );
                    $request['currentApproverName'] =
                        $approverName;
                }
            }

            unset($stage);
            $request['approvalStages'] = $stages;
        }

        unset($request);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function createForActor(
        array $input,
        int $actorUserId,
        bool $canManageCompany,
        bool $canRequestSelf
    ): array {
        if (!$canManageCompany) {
            if (!$canRequestSelf) {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'You are not permitted to submit leave requests.',
                    ],
                ];
            }

            $employee = $this->leave
                ->employeeForUser(
                    $this->tenant->companyId(),
                    $actorUserId
                );

            if ($employee === null) {
                return [
                    'successful' => false,
                    'errors' => [
                        'form' =>
                            'Your ERP account must be linked to an active employee profile before requesting leave.',
                    ],
                ];
            }

            $input['employee_id'] = (string) (
                $employee['employee_id'] ?? 0
            );
        }

        return $this->create(
            $input,
            $actorUserId
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function decideForActor(
        int $leaveRequestId,
        string $status,
        string $decisionNote,
        int $actorUserId,
        bool $canApproveCompany,
        bool $canApproveTeam
    ): array {
        if (!$canApproveCompany && !$canApproveTeam) {
            return [
                'successful' => false,
                'errors' => [
                    'decision' =>
                        'You are not permitted to decide leave requests.',
                ],
            ];
        }

        return $this->decide(
            $leaveRequestId,
            $status,
            $decisionNote,
            $actorUserId,
            $canApproveCompany,
            $canApproveTeam
        );
    }

    /**
     * @return array<string, int>
     */
    public function summaryForActor(
        int $actorUserId,
        bool $canViewCompany
    ): array {
        $companyId = $this->tenant->companyId();

        if ($canViewCompany) {
            return $this->summaryFrom(
                $this->leave->requests($companyId)
            );
        }

        $employee = $this->leave->employeeForUser(
            $companyId,
            $actorUserId
        );

        return $employee === null
            ? $this->summaryFrom([])
            : $this->summaryFrom(
                $this->leave->requestsForEmployee(
                    $companyId,
                    (int) $employee['employee_id']
                )
            );
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return $this->summaryFrom(
            $this->leave->requests(
                $this->tenant->companyId()
            )
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

        $leaveType = $this->leave->leaveType(
            $companyId,
            (int) $values['leave_type_id']
        );
        $workflow = (string) (
            $leaveType['approval_workflow']
                ?? (!empty(
                    $leaveType['requires_approval']
                )
                    ? 'manager'
                    : 'none')
        );
        $approvalRoute = $this->approvalRoute(
            $companyId,
            (int) $values['employee_id'],
            is_array($leaveType) ? $leaveType : []
        );

        if ($approvalRoute['errors'] !== []) {
            return [
                'successful' => false,
                'errors' => $approvalRoute['errors'],
            ];
        }

        $requiresApproval = $workflow !== 'none';
        $requestStatus = $requiresApproval
            ? 'pending'
            : 'approved';
        $values['approval_workflow'] = $workflow;
        $values['request_status'] = $requestStatus;
        $values['decision_note'] = $requiresApproval
            ? null
            : 'Automatically approved by leave policy.';
        $values['decided_by'] = $requiresApproval
            ? null
            : $createdBy;
        $values['decided_at'] = $requiresApproval
            ? null
            : date('Y-m-d H:i:s');

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $requestId = $this->leave
                ->createRequest(
                    $companyId,
                    $values,
                    $createdBy
                );

            foreach (
                $approvalRoute['stages']
                as $stage
            ) {
                $this->leave->createApprovalStage(
                    $companyId,
                    $requestId,
                    (string) $stage['stage'],
                    (int) $stage['sequence'],
                    (int) $stage['approverUserId'],
                    (string) $stage['status']
                );
            }

            $this->auditLogs->record(
                $createdBy,
                $requiresApproval
                    ? 'REQUEST_LEAVE'
                    : 'REQUEST_LEAVE_AUTO_APPROVED',
                'hr',
                'hr_leave_requests',
                (string) $requestId,
                null,
                $this->requestValues($values)
                    + [
                        'request_status' =>
                            $requestStatus,
                        'decision_note' =>
                            $values['decision_note'],
                        'approval_workflow' =>
                            $workflow,
                        'approval_route' =>
                            $approvalRoute['audit'],
                    ],
                $companyId
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
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
            'leaveRequestId' => $requestId,
            'status' => $requestStatus,
            'approvalWorkflow' => $workflow,
            'approvalRouteLabel' =>
                self::WORKFLOWS[$workflow]
                    ?? 'Manager only',
            'approvers' => $approvalRoute['audit'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decide(
        int $leaveRequestId,
        string $status,
        string $decisionNote,
        int $decidedBy,
        bool $canApproveCompany,
        bool $canApproveTeam
    ): array {
        $companyId = $this->tenant->companyId();
        $status = strtolower(trim($status));
        $decisionNote = trim($decisionNote);
        $errors = [];

        if (!in_array(
            $status,
            ['approved', 'rejected'],
            true
        )) {
            $errors['decision'] =
                'Select Approve or Reject.';
        }

        if (
            $status === 'rejected'
            && mb_strlen($decisionNote) < 3
        ) {
            $errors['decision_note'] =
                'Provide a reason when rejecting leave.';
        } elseif (mb_strlen($decisionNote) > 500) {
            $errors['decision_note'] =
                'Decision note cannot exceed 500 characters.';
        }

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

            $request = $this->leave->findRequest(
                $companyId,
                $leaveRequestId,
                true
            );

            if ($request === null) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'notFound' => true,
                    'errors' => [],
                ];
            }

            if (
                (string) (
                    $request['request_status'] ?? ''
                ) !== 'pending'
            ) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'errors' => [
                        'decision' =>
                            'Only pending leave requests can be decided.',
                    ],
                ];
            }

            $approval = $this->leave
                ->pendingApprovalForActor(
                    $companyId,
                    $leaveRequestId,
                    $decidedBy,
                    true
                );

            if ($approval === null) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'notFound' => true,
                    'errors' => [],
                ];
            }

            $approvalStage = (string) (
                $approval['approval_stage'] ?? ''
            );
            $hasStagePermission =
                (
                    $approvalStage === 'manager'
                    && $canApproveTeam
                )
                || (
                    $approvalStage === 'hr'
                    && $canApproveCompany
                );

            if (!$hasStagePermission) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'notFound' => true,
                    'errors' => [],
                ];
            }

            $note = $decisionNote === ''
                ? null
                : $decisionNote;
            $stageChanged = $this->leave
                ->decideApprovalStage(
                    $companyId,
                    (int) $approval['approval_id'],
                    $status,
                    $note
                );

            if (!$stageChanged) {
                throw new \RuntimeException(
                    'The approval-stage decision could not be saved.'
                );
            }

            $nextApproval = null;
            $finalStatus = $status;
            $finalized = $status === 'rejected';

            if ($status === 'approved') {
                $nextApproval = $this->leave
                    ->nextWaitingApproval(
                        $companyId,
                        $leaveRequestId,
                        true
                    );
                $finalized = $nextApproval === null;
                $finalStatus = $finalized
                    ? 'approved'
                    : 'pending';
            }

            if ($nextApproval !== null) {
                $activated = $this->leave
                    ->activateApprovalStage(
                        $companyId,
                        (int) $nextApproval[
                            'approval_id'
                        ]
                    );

                if (!$activated) {
                    throw new \RuntimeException(
                        'The next leave approval stage could not be activated.'
                    );
                }
            }

            $changed = true;

            if ($finalized) {
                $changed = $this->leave->decide(
                    $companyId,
                    $leaveRequestId,
                    $finalStatus,
                    $note,
                    $decidedBy
                );
            }

            if (!$changed) {
                throw new \RuntimeException(
                    'The leave decision could not be saved.'
                );
            }

            $this->auditLogs->record(
                $decidedBy,
                strtoupper($status)
                    . '_LEAVE_'
                    . strtoupper($approvalStage)
                    . '_STAGE',
                'hr',
                'hr_leave_request_approvals',
                (string) $approval['approval_id'],
                [
                    'approval_status' => 'pending',
                    'decision_note' => null,
                ],
                [
                    'approval_status' => $status,
                    'decision_note' => $note,
                    'leave_request_id' =>
                        $leaveRequestId,
                ],
                $companyId
            );

            if ($finalized) {
                $this->auditLogs->record(
                    $decidedBy,
                    strtoupper($finalStatus)
                        . '_LEAVE',
                    'hr',
                    'hr_leave_requests',
                    (string) $leaveRequestId,
                    [
                        'request_status' => 'pending',
                        'decision_note' => null,
                    ],
                    [
                        'request_status' =>
                            $finalStatus,
                        'decision_note' => $note,
                        'final_approval_stage' =>
                            $approvalStage,
                    ],
                    $companyId
                );
            }

            if ($ownsTransaction) {
                $connection->commit();
            }
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
            'status' => $finalStatus,
            'stageStatus' => $status,
            'stage' => $approvalStage,
            'finalized' => $finalized,
            'nextStage' => is_array($nextApproval)
                ? (string) (
                    $nextApproval['approval_stage']
                        ?? ''
                )
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $startDate = trim((string) (
            $input['start_date'] ?? ''
        ));
        $endDate = trim((string) (
            $input['end_date'] ?? ''
        ));

        return [
            'employee_id' => $this->integer(
                $input['employee_id'] ?? null
            ),
            'leave_type_id' => $this->integer(
                $input['leave_type_id'] ?? null
            ),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'requested_days' =>
                $this->businessDays(
                    $startDate,
                    $endDate
                ),
            'reason' => $this->nullable(
                $input['reason'] ?? null
            ),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private function validate(
        int $companyId,
        array $values
    ): array {
        $errors = [];
        $employeeId = (int) (
            $values['employee_id'] ?? 0
        );
        $leaveTypeId = (int) (
            $values['leave_type_id'] ?? 0
        );
        $startDate = (string) (
            $values['start_date'] ?? ''
        );
        $endDate = (string) (
            $values['end_date'] ?? ''
        );

        if (
            $employeeId < 1
            || !$this->leave->employeeExists(
                $companyId,
                $employeeId
            )
        ) {
            $errors['employee_id'] =
                'Select an active employee from the current company.';
        }

        if (
            $leaveTypeId < 1
            || $this->leave->leaveType(
                $companyId,
                $leaveTypeId
            ) === null
        ) {
            $errors['leave_type_id'] =
                'Select an active leave type.';
        }

        if (!$this->validDate($startDate)) {
            $errors['start_date'] =
                'Enter a valid start date.';
        }

        if (!$this->validDate($endDate)) {
            $errors['end_date'] =
                'Enter a valid end date.';
        }

        if (
            !isset($errors['start_date'])
            && !isset($errors['end_date'])
        ) {
            $start = new DateTimeImmutable($startDate);
            $end = new DateTimeImmutable($endDate);
            $rangeDays = (int) $start->diff($end)
                ->format('%r%a');

            if ($rangeDays < 0) {
                $errors['end_date'] =
                    'End date cannot be earlier than start date.';
            } elseif ($rangeDays > 365) {
                $errors['end_date'] =
                    'A leave request cannot exceed one year.';
            } elseif (
                (float) (
                    $values['requested_days'] ?? 0
                ) <= 0
            ) {
                $errors['end_date'] =
                    'The selected range has no working days.';
            } elseif (
                $employeeId > 0
                && $this->leave->overlaps(
                    $companyId,
                    $employeeId,
                    $startDate,
                    $endDate
                )
            ) {
                $errors['form'] =
                    'This employee already has overlapping pending or approved leave.';
            }
        }

        if (
            is_string($values['reason'])
            && mb_strlen($values['reason']) > 500
        ) {
            $errors['reason'] =
                'Reason cannot exceed 500 characters.';
        }

        return $errors;
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return array<string, int>
     */
    private function summaryFrom(
        array $requests
    ): array {
        $today = date('Y-m-d');
        $summary = [
            'pending' => 0,
            'approved' => 0,
            'onLeaveToday' => 0,
            'upcoming' => 0,
        ];

        foreach ($requests as $request) {
            $status = (string) (
                $request['request_status'] ?? ''
            );
            $start = substr(
                (string) (
                    $request['start_date'] ?? ''
                ),
                0,
                10
            );
            $end = substr(
                (string) (
                    $request['end_date'] ?? ''
                ),
                0,
                10
            );

            if ($status === 'pending') {
                $summary['pending']++;
            }

            if ($status === 'approved') {
                $summary['approved']++;

                if (
                    $start <= $today
                    && $end >= $today
                ) {
                    $summary['onLeaveToday']++;
                } elseif ($start > $today) {
                    $summary['upcoming']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @param list<array<string, mixed>> $first
     * @param list<array<string, mixed>> $second
     *
     * @return list<array<string, mixed>>
     */
    private function mergeRequests(
        array $first,
        array $second
    ): array {
        $merged = [];

        foreach (
            array_merge($first, $second)
            as $request
        ) {
            $requestId = (int) (
                $request['leave_request_id'] ?? 0
            );

            if ($requestId > 0) {
                $merged[$requestId] = $request;
            }
        }

        return array_values($merged);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function present(array &$request): void
    {
        $status = (string) (
            $request['request_status'] ?? 'pending'
        );

        if (!isset(self::STATUSES[$status])) {
            $status = 'pending';
        }

        $request['statusLabel'] =
            self::STATUSES[$status]['label'];
        $request['statusTone'] =
            self::STATUSES[$status]['tone'];
        $this->presentApprovalProgress(
            $request,
            $status
        );
        $request['employeeName'] =
            $this->employeeName($request);
    }

    /**
     * Present the exact approval position without changing
     * the persisted request status.
     *
     * @param array<string, mixed> $request
     */
    private function presentApprovalProgress(
        array &$request,
        string $requestStatus
    ): void {
        $currentStage = (string) (
            $request['currentApprovalStage'] ?? ''
        );
        $currentApprover = trim((string) (
            $request['currentApproverName'] ?? ''
        ));
        $stages = is_array(
            $request['approvalStages'] ?? null
        )
            ? $request['approvalStages']
            : [];
        $managerApproved = false;
        $rejectedStage = '';

        foreach ($stages as $stage) {
            $stageCode = (string) (
                $stage['approval_stage'] ?? ''
            );
            $stageStatus = (string) (
                $stage['approval_status'] ?? ''
            );

            if (
                $stageCode === 'manager'
                && $stageStatus === 'approved'
            ) {
                $managerApproved = true;
            }

            if ($stageStatus === 'rejected') {
                $rejectedStage = (string) (
                    $stage['stageLabel']
                    ?? self::APPROVAL_STAGE_LABELS[
                        $stageCode
                    ]
                    ?? ucfirst($stageCode)
                );
            }
        }

        if ($requestStatus === 'approved') {
            $request['approvalProgressLabel'] =
                'Fully approved';
            $request['approvalProgressDetail'] =
                $stages === []
                    ? 'No approval was required.'
                    : 'Every required approval stage is complete.';
            $request['approvalProgressTone'] =
                'success';

            return;
        }

        if ($requestStatus === 'rejected') {
            $request['approvalProgressLabel'] =
                $rejectedStage !== ''
                    ? 'Rejected by '
                        . $rejectedStage
                    : 'Rejected';
            $request['approvalProgressDetail'] =
                'The approval workflow has ended.';
            $request['approvalProgressTone'] =
                'danger';

            return;
        }

        if ($requestStatus === 'cancelled') {
            $request['approvalProgressLabel'] =
                'Cancelled';
            $request['approvalProgressDetail'] =
                'This request is no longer active.';
            $request['approvalProgressTone'] =
                'muted';

            return;
        }

        if ($currentStage === 'hr') {
            $request['approvalProgressLabel'] =
                $managerApproved
                    ? 'Manager approved — waiting for HR'
                    : 'Waiting for HR approval';
            $request['approvalProgressDetail'] =
                $currentApprover !== ''
                    ? 'Current approver: '
                        . $currentApprover
                    : 'An HR approver must complete this stage.';
            $request['approvalProgressTone'] =
                'warning';

            return;
        }

        if ($currentStage === 'manager') {
            $request['approvalProgressLabel'] =
                'Waiting for manager approval';
            $request['approvalProgressDetail'] =
                $currentApprover !== ''
                    ? 'Current approver: '
                        . $currentApprover
                    : 'A reporting manager must complete this stage.';
            $request['approvalProgressTone'] =
                'warning';

            return;
        }

        $request['approvalProgressLabel'] =
            'Approval route needs attention';
        $request['approvalProgressDetail'] =
            'No active approver stage could be resolved.';
        $request['approvalProgressTone'] =
            'danger';
    }

    /**
     * @param list<array<string, mixed>> $employees
     *
     * @return list<array<string, mixed>>
     */
    private function presentEmployees(
        array $employees
    ): array {
        foreach ($employees as &$employee) {
            $employee['displayName'] =
                $this->employeeName($employee);
        }

        unset($employee);

        return $employees;
    }

    private function employeeName(
        array $record
    ): string {
        $preferred = trim((string) (
            $record['preferred_name'] ?? ''
        ));
        $first = $preferred !== ''
            ? $preferred
            : trim((string) (
                $record['first_name'] ?? ''
            ));

        return trim(
            $first . ' ' . (string) (
                $record['last_name'] ?? ''
            )
        );
    }

    private function businessDays(
        string $startDate,
        string $endDate
    ): string {
        if (
            !$this->validDate($startDate)
            || !$this->validDate($endDate)
        ) {
            return '0.00';
        }

        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);

        if ($end < $start) {
            return '0.00';
        }

        $days = 0;
        $date = $start;

        while ($date <= $end) {
            $weekday = (int) $date->format('N');

            if ($weekday <= 5) {
                $days++;
            }

            $date = $date->add(
                new DateInterval('P1D')
            );

            if ($days > 366) {
                break;
            }
        }

        return number_format(
            $days,
            2,
            '.',
            ''
        );
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date
        );

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function requestValues(
        array $values
    ): array {
        return [
            'employee_id' => (int) (
                $values['employee_id'] ?? 0
            ),
            'leave_type_id' => (int) (
                $values['leave_type_id'] ?? 0
            ),
            'start_date' => (string) (
                $values['start_date'] ?? ''
            ),
            'end_date' => (string) (
                $values['end_date'] ?? ''
            ),
            'requested_days' => (string) (
                $values['requested_days'] ?? '0.00'
            ),
            'reason' => $this->nullable(
                $values['reason'] ?? null
            ),
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

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
