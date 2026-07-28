<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\CompanyRepository;

/**
 * Backward-compatible data-access facade.
 */
final class Company extends CompanyRepository
{
}
