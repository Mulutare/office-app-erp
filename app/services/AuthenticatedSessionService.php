<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuthenticatedSessionRepository;
use App\Repositories\RepositoryFactory;
use DateTimeImmutable;
use RuntimeException;

final class AuthenticatedSessionService
{
    public function __construct(
        private ?AuthenticatedSessionRepository $sessions = null
    ) {
        $this->sessions ??=
            RepositoryFactory::authenticatedSessions();
    }

    public function hashCurrent(): string
    {
        $sessionId = session_id();

        if ($sessionId === '') {
            return '';
        }

        return hash('sha256', $sessionId);
    }

    public function register(
        int $companyId,
        int $userId,
        ?int $signedInTimestamp = null
    ): void {
        if (
            session_id() === ''
            || !$this->sessions->available()
        ) {
            return;
        }

        $now = new DateTimeImmutable();

        $signedIn = $signedInTimestamp === null
            ? $now
            : (new DateTimeImmutable())
                ->setTimestamp($signedInTimestamp);

        $authenticatedSessionId = $this->sessions->register([
            'company_id' => $companyId,
            'user_id' => $userId,
            'session_hash' => $this->hashCurrent(),
            'signed_in_at' =>
                $signedIn->format('Y-m-d H:i:s'),
            'last_activity_at' =>
                $now->format('Y-m-d H:i:s'),
            'expires_at' =>
                $now
                    ->modify(
                        '+' . $this->lifetime() . ' seconds'
                    )
                    ->format('Y-m-d H:i:s'),
            'ip_address' => \requestIp(),
            'user_agent' => \requestUserAgent(),
        ]);

        $_SESSION['auth'][
            'session_activity_persisted_at'
        ] = $now->getTimestamp();
        $_SESSION['auth'][
            'authenticated_session_registry_id'
        ] = $authenticatedSessionId;
    }

    public function touchOrRegister(
        int $companyId,
        int $userId
    ): bool {
        if (
            session_id() === ''
            || !$this->sessions->available()
        ) {
            return true;
        }

        $now = time();
        $nowFormatted = date('Y-m-d H:i:s', $now);
        $sessionHash = $this->hashCurrent();
        $registeredId = (int) (
            $_SESSION['auth'][
                'authenticated_session_registry_id'
            ] ?? 0
        );
        $registered = $this->sessions->findByHash(
            $companyId,
            $userId,
            $sessionHash
        );

        if ($registeredId > 0) {
            if (
                $registered === null
                || (int) ($registered[
                    'authenticated_user_session_id'
                ] ?? 0) !== $registeredId
                || !$this->isActive($registered, $nowFormatted)
            ) {
                return false;
            }
        } elseif ($registered !== null) {
            if (!$this->isActive($registered, $nowFormatted)) {
                return false;
            }

            $_SESSION['auth'][
                'authenticated_session_registry_id'
            ] = (int) $registered[
                'authenticated_user_session_id'
            ];
        }

        $lastPersisted = (int) (
            $_SESSION['auth'][
                'session_activity_persisted_at'
            ] ?? 0
        );

        if (
            $lastPersisted > 0
            && ($now - $lastPersisted) < $this->throttle()
        ) {
            return true;
        }

        $activityAt = date('Y-m-d H:i:s', $now);

        $expiresAt = date(
            'Y-m-d H:i:s',
            $now + $this->lifetime()
        );

        $updated = $this->sessions->touch(
            $companyId,
            $userId,
            $sessionHash,
            $activityAt,
            $expiresAt
        );

        if (!$updated) {
            if ($registered !== null) {
                return false;
            }

            $this->register(
                $companyId,
                $userId,
                (int) (
                    $_SESSION['auth']['authenticated_at']
                    ?? $now
                )
            );

            return true;
        }

        $_SESSION['auth'][
            'session_activity_persisted_at'
        ] = $now;

        return true;
    }

    public function revoke(
        int $companyId,
        int $userId
    ): void {
        if (
            session_id() === ''
            || !$this->sessions->available()
        ) {
            return;
        }

        $this->sessions->revoke(
            $companyId,
            $userId,
            $this->hashCurrent(),
            date('Y-m-d H:i:s')
        );
    }

    public function count(
        int $companyId,
        int $userId
    ): int {
        if (!$this->sessions->available()) {
            return session_id() === '' ? 0 : 1;
        }

        return $this->sessions->countActive(
            $companyId,
            $userId,
            date('Y-m-d H:i:s')
        );
    }

    public function list(
        int $companyId,
        int $userId
    ): array {
        if (!$this->sessions->available()) {
            return [];
        }

        $currentHash = $this->hashCurrent();

        $rows = $this->sessions->listActive(
            $companyId,
            $userId,
            date('Y-m-d H:i:s')
        );

        $result = [];

        foreach ($rows as $row) {
            $storedHash =
                (string) ($row['session_hash'] ?? '');

            $row['current'] =
                $currentHash !== ''
                && $storedHash !== ''
                && hash_equals(
                    $storedHash,
                    $currentHash
                );

            unset($row['session_hash']);

            $row['device'] = $this->device(
                (string) ($row['user_agent'] ?? '')
            );

            $row['status'] = 'Active';

            $result[] = $row;
        }

        return $result;
    }

    public function terminateSession(
        int $companyId,
        int $userId,
        int $authenticatedSessionId
    ): bool {
        if (
            $companyId < 1
            || $userId < 1
            || $authenticatedSessionId < 1
            || !$this->sessions->available()
        ) {
            return false;
        }

        $isCurrentAccount = $userId === (int) (
            $_SESSION['auth']['user_id'] ?? 0
        ) && $companyId === (int) (
            $_SESSION['auth']['company']['company_id'] ?? 0
        );
        $currentRegistryId = (int) (
            $_SESSION['auth'][
                'authenticated_session_registry_id'
            ] ?? 0
        );

        if (
            $isCurrentAccount
            && $currentRegistryId > 0
            && $authenticatedSessionId === $currentRegistryId
        ) {
            return false;
        }

        return $this->sessions->revokeById(
            $companyId,
            $userId,
            $authenticatedSessionId,
            date('Y-m-d H:i:s')
        );
    }

    public function terminateOtherSessions(
        int $companyId,
        int $userId
    ): int {
        $currentHash = $this->hashCurrent();

        if ($currentHash === '') {
            throw new RuntimeException(
                'The current authenticated session could not be identified.'
            );
        }

        if (!$this->sessions->available()) {
            return 0;
        }

        return $this->sessions->revokeAllExceptHash(
            $companyId,
            $userId,
            $currentHash,
            date('Y-m-d H:i:s')
        );
    }

    public function terminateAllSessions(
        int $companyId,
        int $userId
    ): int {
        $currentUserId = (int) (
            $_SESSION['auth']['user_id'] ?? 0
        );
        $currentCompanyId = (int) (
            $_SESSION['auth']['company']['company_id'] ?? 0
        );

        if (
            $currentUserId === $userId
            && $currentCompanyId === $companyId
        ) {
            return $this->terminateOtherSessions(
                $companyId,
                $userId
            );
        }

        if (!$this->sessions->available()) {
            return 0;
        }

        return $this->sessions->revokeAll(
            $companyId,
            $userId,
            date('Y-m-d H:i:s')
        );
    }

    private function isActive(array $session, string $now): bool
    {
        return empty($session['revoked_at'])
            && (string) ($session['expires_at'] ?? '') > $now;
    }

    private function lifetime(): int
    {
        return max(
            300,
            (int) \config(
                'session_lifetime_seconds',
                28800
            )
        );
    }

    private function throttle(): int
    {
        return max(
            60,
            (int) \config(
                'session_activity_throttle_seconds',
                300
            )
        );
    }

    private function device(string $agent): string
    {
        $os = '';

        if (str_contains($agent, 'iPhone')) {
            $os = 'iPhone';
        } elseif (str_contains($agent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($agent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($agent, 'Macintosh')) {
            $os = 'macOS';
        }

        $browser = '';

        if (str_contains($agent, 'Edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($agent, 'Firefox/')) {
            $browser = 'Firefox';
        } elseif (
            str_contains($agent, 'CriOS')
            || str_contains($agent, 'Chrome/')
        ) {
            $browser = 'Chrome';
        } elseif (str_contains($agent, 'Safari/')) {
            $browser = str_contains($agent, 'Mobile/')
                ? 'Mobile Safari'
                : 'Safari';
        }

        if ($browser === '' && $os === '') {
            return 'Unknown device';
        }

        if ($browser === '') {
            return $os;
        }

        if ($os === '') {
            return $browser;
        }

        return $browser . ' / ' . $os;
    }
}
