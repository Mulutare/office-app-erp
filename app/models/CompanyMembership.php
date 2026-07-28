<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\CompanyMembershipRepository;

/**
 * Backward-compatible data-access facade.
 */
final class CompanyMembership extends CompanyMembershipRepository
{
}
