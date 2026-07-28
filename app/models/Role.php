<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\RoleRepository;

/**
 * Backward-compatible data-access facade.
 */
final class Role extends RoleRepository
{
}
