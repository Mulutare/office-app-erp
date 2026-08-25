<?php
declare(strict_types=1);
namespace App\Repositories;

interface AuthenticatedSessionRepository
{
    public function available(): bool;
    public function register(array $session): void;
    public function touch(int $companyId, int $userId, string $sessionHash, string $activityAt, string $expiresAt): bool;
    public function revoke(int $companyId, int $userId, string $sessionHash, string $revokedAt): void;
    public function countActive(int $companyId, int $userId, string $now): int;
    public function findByHash(int $companyId, int $userId, string $sessionHash): ?array;
    public function listActive(int $companyId, int $userId, string $now): array;
}
