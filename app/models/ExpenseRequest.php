<?php

declare(strict_types=1);

namespace App\Models;

use App\Repositories\MySql\ExpenseRequestRepository;

/**
 * Backward-compatible data-access facade.
 */
final class ExpenseRequest extends ExpenseRequestRepository
{
}
