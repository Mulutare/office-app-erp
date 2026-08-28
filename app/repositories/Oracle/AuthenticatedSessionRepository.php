<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\AuthenticatedSessionRepository as Contract;
use RuntimeException;

final class AuthenticatedSessionRepository
    implements Contract
{
    public function available(): bool
    {
        return false;
    }

    public function register(array $session): int
    {
        $this->unavailable();
    }

    public function touch(
        int $companyId,
        int $userId,
        string $sessionHash,
        string $activityAt,
        string $expiresAt
    ): bool {
        $this->unavailable();
    }

    public function revoke(
        int $companyId,
        int $userId,
        string $sessionHash,
        string $revokedAt
    ): void {
        $this->unavailable();
    }

    public function countActive(
        int $companyId,
        int $userId,
        string $now
    ): int {
        $this->unavailable();
    }

    public function findByHash(
        int $companyId,
        int $userId,
        string $sessionHash
    ): ?array {
        $this->unavailable();
    }

    public function listActive(
        int $companyId,
        int $userId,
        string $now
    ): array {
        $this->unavailable();
    }

    public function revokeById(
        int $companyId,
        int $userId,
        int $authenticatedSessionId,
        string $revokedAt
    ): bool {
        $this->unavailable();
    }

    public function revokeAllExceptHash(
        int $companyId,
        int $userId,
        string $keepSessionHash,
        string $revokedAt
    ): int {
        $this->unavailable();
    }

    public function revokeAll(
        int $companyId,
        int $userId,
        string $revokedAt
    ): int {
        $this->unavailable();
    }

    private function unavailable(): never
    {
        throw new RuntimeException(
            'The Oracle authenticated-session repository is not implemented.'
        );
    }
}
