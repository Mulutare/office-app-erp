<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use LogicException;

final class PlatformAdministratorProtectionService
{
    private const MANAGEMENT_ERROR =
        'Platform administrator accounts can only be managed by another active platform administrator.';

    private const LAST_ACTIVE_ERROR =
        'The last active platform administrator cannot be deactivated.';

    private User $users;

    public function __construct()
    {
        $this->users = new User();
    }

    /**
     * @param array<string, mixed> $target
     */
    public function managementError(
        array $target,
        int $actorId
    ): ?string {
        if (empty($target['is_platform_admin'])) {
            return null;
        }

        if (
            $actorId > 0
            && $this->users
                ->isOperationalPlatformAdministrator(
                    $actorId
                )
        ) {
            return null;
        }

        return self::MANAGEMENT_ERROR;
    }

    /**
     * @param array<string, mixed> $target
     */
    public function deactivationError(
        array $target,
        bool $requestedActive
    ): ?string {
        if (
            $requestedActive
            || empty($target['active'])
            || empty($target['is_platform_admin'])
        ) {
            return null;
        }

        if (!\db()->inTransaction()) {
            throw new LogicException(
                'Platform-administrator protection requires an active transaction.'
            );
        }

        $administratorIds = $this->users
            ->lockOperationalPlatformAdministratorIds();

        if (count($administratorIds) > 1) {
            return null;
        }

        return self::LAST_ACTIVE_ERROR;
    }
}
