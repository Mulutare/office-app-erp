<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\CompanyModuleRepository;

/**
 * Backward-compatible data-access facade.
 */
final class CompanyModule extends CompanyModuleRepository
{
}
