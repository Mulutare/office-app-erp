<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\EmployeeActivityRepository;

/**
 * Backward-compatible data-access facade.
 */
final class EmployeeActivity extends EmployeeActivityRepository
{
}
