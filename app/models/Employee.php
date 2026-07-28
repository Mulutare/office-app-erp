<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\EmployeeRepository;

/**
 * Backward-compatible data-access facade.
 */
final class Employee extends EmployeeRepository
{
}
