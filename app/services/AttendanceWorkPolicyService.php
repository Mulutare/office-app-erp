<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Applies a dated workforce-calendar rule to an attendance interval.
 *
 * Calculated values are persisted on the attendance record so later calendar
 * changes never rewrite historical payroll and compliance evidence.
 */
final class AttendanceWorkPolicyService
{
    /**
     * @param array<string, mixed>|null $context
     * @return array<string, int|string|bool>
     */
    public function evaluate(
        ?array $context,
        string $attendanceDate,
        string $checkInAt,
        ?string $checkOutAt = null
    ): array {
        $timezone = $this->timezone(
            (string) ($context['timezone'] ?? 'UTC')
        );
        $checkIn = new DateTimeImmutable(
            $checkInAt,
            $timezone
        );
        $checkOut = $checkOutAt === null
            ? null
            : new DateTimeImmutable(
                $checkOutAt,
                $timezone
            );
        $workingDay = $context !== null
            && !empty($context['workingDay']);
        $target = $workingDay
            ? max(0, (int) (
                $context['targetWorkMinutes'] ?? 0
            ))
            : 0;
        $configuredBreak = $workingDay
            ? max(0, (int) (
                $context['breakMinutes'] ?? 0
            ))
            : 0;
        $flex = $workingDay
            ? max(0, (int) (
                $context['flexStartMinutes'] ?? 0
            ))
            : 0;
        $scheduledStart = $this->atLocalTime(
            $attendanceDate,
            (string) ($context['startTime'] ?? ''),
            $timezone
        );
        $latestStart = $scheduledStart?->modify(
            '+' . $flex . ' minutes'
        );
        $scheduledEnd = $this->atLocalTime(
            $attendanceDate,
            (string) ($context['endTime'] ?? ''),
            $timezone
        );

        if (
            $scheduledStart !== null
            && $scheduledEnd !== null
            && $scheduledEnd <= $scheduledStart
        ) {
            $scheduledEnd = $scheduledEnd->modify(
                '+1 day'
            );
        }

        $late = $latestStart !== null
            && $checkIn > $latestStart;
        $lateMinutes = $late
            ? (int) floor(
                (
                    $checkIn->getTimestamp()
                    - $latestStart->getTimestamp()
                ) / 60
            )
            : 0;
        $earlyDepartureMinutes =
            $checkOut !== null
            && $scheduledEnd !== null
            && $checkOut < $scheduledEnd
                ? (int) floor(
                    (
                        $scheduledEnd->getTimestamp()
                        - $checkOut->getTimestamp()
                    ) / 60
                )
                : 0;
        $gross = $checkOut === null
            ? 0
            : max(
                0,
                (int) floor(
                    (
                        $checkOut->getTimestamp()
                        - $checkIn->getTimestamp()
                    ) / 60
                )
            );
        $deductedBreak = $checkOut === null
            ? 0
            : $this->deductedBreak(
                $context,
                $attendanceDate,
                $checkIn,
                $checkOut,
                $configuredBreak,
                $target,
                $timezone
            );
        $net = max(0, $gross - $deductedBreak);
        $expectedCheckout = $target > 0
            ? $checkIn->modify(
                '+'
                . ($target + $configuredBreak)
                . ' minutes'
            )
            : null;

        return [
            'late' => $late,
            'late_minutes' => $lateMinutes,
            'early_departure_minutes' =>
                $earlyDepartureMinutes,
            'missing_clock_out' => $checkOut === null,
            'gross_minutes' => $gross,
            'break_minutes' => $deductedBreak,
            'work_minutes' => $net,
            'target_work_minutes' => $target,
            'work_variance_minutes' => $target > 0
                ? $net - $target
                : 0,
            'expected_checkout_at' =>
                $expectedCheckout?->format(
                    'Y-m-d H:i:s'
                ) ?? '',
            'latest_start_at' =>
                $latestStart?->format(
                    'Y-m-d H:i:s'
                ) ?? '',
        ];
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function deductedBreak(
        ?array $context,
        string $attendanceDate,
        DateTimeImmutable $checkIn,
        DateTimeImmutable $checkOut,
        int $configuredBreak,
        int $target,
        DateTimeZone $timezone
    ): int {
        if (
            $configuredBreak < 1
            || $checkOut <= $checkIn
        ) {
            return 0;
        }

        $breakStart = $this->atLocalTime(
            $attendanceDate,
            (string) (
                $context['breakStartTime'] ?? ''
            ),
            $timezone
        );
        $breakEnd = $this->atLocalTime(
            $attendanceDate,
            (string) (
                $context['breakEndTime'] ?? ''
            ),
            $timezone
        );

        if ($breakStart === null || $breakEnd === null) {
            $gross = max(
                0,
                (int) floor(
                    (
                        $checkOut->getTimestamp()
                        - $checkIn->getTimestamp()
                    ) / 60
                )
            );

            return $target > 0
                && $gross >= ($target + $configuredBreak)
                    ? $configuredBreak
                    : 0;
        }

        if ($breakEnd <= $breakStart) {
            $breakEnd = $breakEnd->modify('+1 day');
        }

        $overlapStart = $checkIn > $breakStart
            ? $checkIn
            : $breakStart;
        $overlapEnd = $checkOut < $breakEnd
            ? $checkOut
            : $breakEnd;

        if ($overlapEnd <= $overlapStart) {
            return 0;
        }

        return min(
            $configuredBreak,
            (int) floor(
                (
                    $overlapEnd->getTimestamp()
                    - $overlapStart->getTimestamp()
                ) / 60
            )
        );
    }

    private function atLocalTime(
        string $date,
        string $time,
        DateTimeZone $timezone
    ): ?DateTimeImmutable {
        if (preg_match(
            '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
            $time
        ) !== 1) {
            return null;
        }

        $value = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $date . ' ' . $time,
            $timezone
        );

        return $value === false ? null : $value;
    }

    private function timezone(
        string $identifier
    ): DateTimeZone {
        try {
            return new DateTimeZone($identifier);
        } catch (\Throwable $exception) {
            return new DateTimeZone('UTC');
        }
    }
}
