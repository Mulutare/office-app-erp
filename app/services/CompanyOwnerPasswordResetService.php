<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Throwable;

/**
 * Vendor-owned recovery for a customer company's primary owner account.
 *
 * Tenant administrators deliberately cannot reset the primary owner. This
 * service is the corresponding platform-administrator recovery boundary.
 */
final class CompanyOwnerPasswordResetService
{
    private Company $companies;
    private User $users;
    private AuditLog $auditLogs;
    private TemporaryPasswordGenerator $passwords;

    public function __construct()
    {
        $this->companies = new Company();
        $this->users = new User();
        $this->auditLogs = new AuditLog();
        $this->passwords =
            new TemporaryPasswordGenerator();
    }

    /**
     * @return array{
     *     company: array<string, mixed>,
     *     owner: array<string, mixed>
     * }|null
     */
    public function target(
        int $companyId,
        int $requestedBy
    ): ?array {
        if (
            $companyId < 1
            || !$this->isPlatformAdministrator(
                $requestedBy
            )
        ) {
            return null;
        }

        $company = $this->companies
            ->findForAdministration($companyId);

        if (
            $company === null
            || (string) ($company['code'] ?? '')
                === 'default'
        ) {
            return null;
        }

        $ownerUserId = (int) (
            $company['owner_user_id'] ?? 0
        );
        $owner = $ownerUserId > 0
            ? $this->users->findByIdInCompany(
                $ownerUserId,
                $companyId
            )
            : null;

        if (
            $owner === null
            || !empty($owner['is_platform_admin'])
        ) {
            return null;
        }

        return [
            'company' => $company,
            'owner' => $owner,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reset(
        int $companyId,
        int $resetBy
    ): array {
        if (!$this->isPlatformAdministrator(
            $resetBy
        )) {
            return $this->failure(
                'Only a platform administrator can reset a company owner password.'
            );
        }

        if ($companyId < 1) {
            return $this->notFound();
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $company = $this->companies
                ->lockForAdministration($companyId);

            if (
                $company === null
                || (string) (
                    $company['code'] ?? ''
                ) === 'default'
            ) {
                if (
                    $ownsTransaction
                    && $connection->inTransaction()
                ) {
                    $connection->rollBack();
                }

                return $this->notFound();
            }

            $ownerUserId = (int) (
                $company['owner_user_id'] ?? 0
            );
            $owner = $ownerUserId > 0
                ? $this->users->findByIdInCompany(
                    $ownerUserId,
                    $companyId
                )
                : null;

            if ($owner === null) {
                if (
                    $ownsTransaction
                    && $connection->inTransaction()
                ) {
                    $connection->rollBack();
                }

                return $this->failure(
                    'This company does not have a recoverable primary owner account.'
                );
            }

            if (!empty($owner['is_platform_admin'])) {
                if (
                    $ownsTransaction
                    && $connection->inTransaction()
                ) {
                    $connection->rollBack();
                }

                return $this->failure(
                    'Platform administrator credentials cannot be reset through a customer company.'
                );
            }

            $temporaryPassword =
                $this->passwords->generate();
            $passwordHash = password_hash(
                $temporaryPassword,
                PASSWORD_DEFAULT
            );

            if (!is_string($passwordHash)) {
                throw new \RuntimeException(
                    'Unable to securely hash the temporary password.'
                );
            }

            $this->users
                ->resetAdministrationPassword(
                    $ownerUserId,
                    $passwordHash
                );

            $this->auditLogs->record(
                $resetBy,
                'RESET_COMPANY_OWNER_PASSWORD',
                'administration',
                'users',
                (string) $ownerUserId,
                [
                    'company_id' => $companyId,
                    'must_change_password' =>
                        (bool) (
                            $owner[
                                'must_change_password'
                            ] ?? false
                        ),
                    'failed_login_count' =>
                        (int) (
                            $owner[
                                'failed_login_count'
                            ] ?? 0
                        ),
                    'locked_until' =>
                        $owner['locked_until'] ?? null,
                ],
                [
                    'company_id' => $companyId,
                    'must_change_password' => true,
                    'failed_login_count' => 0,
                    'locked_until' => null,
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
            'notFound' => false,
            'errors' => [],
            'username' => (string) (
                $owner['username'] ?? ''
            ),
            'temporaryPassword' =>
                $temporaryPassword,
            'ownerUserId' => $ownerUserId,
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

    /**
     * @return array<string, mixed>
     */
    private function failure(string $message): array
    {
        return [
            'successful' => false,
            'notFound' => false,
            'errors' => [
                'form' => $message,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(): array
    {
        return [
            'successful' => false,
            'notFound' => true,
            'errors' => [],
        ];
    }
}
