<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\StraightLineDepreciationCalculator;

$failures = 0;
$check = static function (bool $condition, string $description) use (&$failures): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
};

$calculator = new StraightLineDepreciationCalculator();
$schedule = $calculator->schedule(60000.00, 6000.00, 36, '2026-01-15');

$check(count($schedule) === 36, 'Straight-line schedule contains exactly 36 periods');
$check(
    $schedule[0]['depreciation_amount'] === 1500.00
    && $schedule[0]['accumulated_amount'] === 1500.00
    && $schedule[0]['book_value_after'] === 58500.00,
    'First depreciation period matches the 60,000 / 6,000 / 36 example'
);
$last = $schedule[array_key_last($schedule)];
$check(
    $last['accumulated_amount'] === 54000.00
    && $last['book_value_after'] === 6000.00,
    'Schedule stops exactly at salvage value'
);

$rounded = $calculator->schedule(100.00, 0.00, 3, '2026-01-31');
$check(
    array_sum(array_column($rounded, 'depreciation_amount')) === 100.00
    && $rounded[2]['depreciation_amount'] === 33.34,
    'Final period corrects currency rounding without over-depreciation'
);

$invalidRejected = false;
try {
    $calculator->schedule(100.00, 101.00, 12, '2026-01-01');
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
$check($invalidRejected, 'Invalid salvage greater than cost is rejected');

echo PHP_EOL . sprintf('5 checks, %d failures', $failures) . PHP_EOL;
exit($failures === 0 ? 0 : 1);
