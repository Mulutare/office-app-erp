<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\LoginAttemptRepository;

/**
 * Backward-compatible data-access facade.
 */
final class LoginAttempt extends LoginAttemptRepository
{
}
