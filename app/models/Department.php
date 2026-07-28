<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\DepartmentRepository;

/**
 * Backward-compatible data-access facade.
 */
final class Department extends DepartmentRepository
{
}
