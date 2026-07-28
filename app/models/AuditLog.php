<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\AuditLogRepository;

/**
 * Backward-compatible data-access facade.
 */
final class AuditLog extends AuditLogRepository
{
}
