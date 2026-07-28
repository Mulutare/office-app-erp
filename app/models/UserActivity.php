<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\UserActivityRepository;

/**
 * Backward-compatible data-access facade.
 */
final class UserActivity extends UserActivityRepository
{
}
