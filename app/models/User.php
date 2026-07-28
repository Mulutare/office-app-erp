<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\UserRepository;

/**
 * Backward-compatible data-access facade.
 */
final class User extends UserRepository
{
}
