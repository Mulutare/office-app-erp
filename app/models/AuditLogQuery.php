<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\AuditLogQueryRepository;

/**
 * Backward-compatible data-access facade.
 */
final class AuditLogQuery extends AuditLogQueryRepository
{
}
