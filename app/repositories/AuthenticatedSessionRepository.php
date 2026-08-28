<?php
declare(strict_types=1);
namespace App\Repositories;

interface AuthenticatedSessionRepository
{
    public function available(): bool;
    public function register(array $session): int;
    public function touch(int $companyId, int $userId, string $sessionHash, string $activityAt, string $expiresAt): bool;
    public function revoke(int $companyId, int $userId, string $sessionHash, string $revokedAt): void;
    public function countActive(int $companyId, int $userId, string $now): int;
    public function findByHash(int $companyId, int $userId, string $sessionHash): ?array;
    public function listActive(int $companyId, int $userId, string $now): array;
    public function revokeById(int $companyId, int $userId, int $authenticatedSessionId, string $revokedAt): bool;
    public function revokeAllExceptHash(int $companyId, int $userId, string $keepSessionHash, string $revokedAt): int;
    public function revokeAll(int $companyId, int $userId, string $revokedAt): int;
}
