<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Throwable;

/**
 * Executes explicit, vendor-only company lifecycle transitions.
 */
final class CompanyLifecycleService
{
    private Company $companies;
    private AuditLog $auditLogs;
    private User $users;

    public function __construct()
    {
        $this->companies = new Company();
        $this->auditLogs = new AuditLog();
        $this->users = new User();
    }

    /**
     * @return array{
     *     successful: bool,
     *     notFound: bool,
     *     changed: bool,
     *     errors: array<string, string>
     * }
     */
    public function change(
        int $companyId,
        string $action,
        string $reason,
        int $changedBy
    ): array {
        $action = strtolower(trim($action));
        $reason = trim($reason);

        if (!$this->isPlatformAdministrator(
            $changedBy
        )) {
            return $this->failure(
                'Only a platform administrator can change a company lifecycle state.'
            );
        }

        if (!in_array(
            $action,
            ['suspend', 'reactivate'],
            true
        )) {
            return $this->failure(
                'The requested lifecycle action is invalid.'
            );
        }

        if (
            $action === 'suspend'
            && (
                mb_strlen($reason) < 10
                || mb_strlen($reason) > 500
            )
        ) {
            return $this->failure(
                'Provide a suspension reason of 10-500 characters.',
                'reason'
            );
        }

        if ($companyId < 1 || $changedBy < 1) {
            return [
                'successful' => false,
                'notFound' => true,
                'changed' => false,
                'errors' => [],
            ];
        }

        try {
            \db()->beginTransaction();
            $company = $this->companies
                ->lockForAdministration($companyId);

            if ($company === null) {
                \db()->rollBack();

                return [
                    'successful' => false,
                    'notFound' => true,
                    'changed' => false,
                    'errors' => [],
                ];
            }

            if (
                (string) ($company['code'] ?? '')
                === 'default'
            ) {
                \db()->rollBack();

                return $this->failure(
                    'The vendor platform workspace cannot be suspended.'
                );
            }

            if (
                (string) (
                    $company['approval_status'] ?? ''
                ) !== 'approved'
                || empty($company['active'])
            ) {
                \db()->rollBack();

                return $this->failure(
                    'Only an approved, active customer company can change lifecycle state.'
                );
            }

            $beforeStatus = (string) (
                $company['subscription_status']
                ?? ''
            );

            if ($action === 'suspend') {
                if (!in_array(
                    $beforeStatus,
                    ['active', 'trial'],
                    true
                )) {
                    \db()->rollBack();

                    return $this->failure(
                        'Only an active or trial subscription can be suspended.'
                    );
                }

                $changed = $this->companies
                    ->suspend($companyId);
                $afterStatus = 'suspended';
                $auditAction = 'SUSPEND_COMPANY';
            } else {
                if ($beforeStatus !== 'suspended') {
                    \db()->rollBack();

                    return $this->failure(
                        'Only a suspended company can be reactivated.'
                    );
                }

                $expiresAt = $company[
                    'subscription_expires_at'
                ] ?? null;
                $expiryTimestamp = is_string(
                    $expiresAt
                )
                    ? strtotime($expiresAt)
                    : false;

                if (
                    $expiryTimestamp !== false
                    && $expiryTimestamp <= time()
                ) {
                    \db()->rollBack();

                    return $this->failure(
                        'Extend the subscription expiry before reactivating this company.'
                    );
                }

                $afterStatus = $this->companies
                    ->preferredResumeStatus(
                        $companyId
                    );
                $changed = $this->companies
                    ->reactivate(
                        $companyId,
                        $afterStatus
                    );
                $auditAction =
                    'REACTIVATE_COMPANY';
            }

            if (!$changed) {
                throw new \RuntimeException(
                    'The company lifecycle state changed concurrently.'
                );
            }

            $this->auditLogs->record(
                $changedBy,
                $auditAction,
                'administration',
                'companies',
                (string) $companyId,
                [
                    'subscription_status' =>
                        $beforeStatus,
                ],
                [
                    'subscription_status' =>
                        $afterStatus,
                    'reason' => $action === 'suspend'
                        ? $reason
                        : null,
                ],
                $companyId
            );
            \db()->commit();
        } catch (Throwable $exception) {
            if (\db()->inTransaction()) {
                \db()->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'notFound' => false,
            'changed' => true,
            'errors' => [],
        ];
    }

    /**
     * @return array{
     *     successful: false,
     *     notFound: false,
     *     changed: false,
     *     errors: array<string, string>
     * }
     */
    private function failure(
        string $message,
        string $key = 'form'
    ): array {
        return [
            'successful' => false,
            'notFound' => false,
            'changed' => false,
            'errors' => [
                $key => $message,
            ],
        ];
    }

    private function isPlatformAdministrator(
        int $userId
    ): bool {
        if ($userId < 1) {
            return false;
        }

        $user = $this->users->findById($userId);

        return is_array($user)
            && !empty($user['active'])
            && !empty($user['is_platform_admin']);
    }
}
