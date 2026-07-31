<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class AttendanceDayResolver
{
    private WorkforceCalendarService $calendars;

    public function __construct(
        ?WorkforceCalendarService $calendars = null
    ) {
        $this->calendars = $calendars
            ?? new WorkforceCalendarService();
    }

    /**
     * Resolve one scan to a deterministic configured attendance day.
     *
     * @return array<string, mixed>
     */
    public function resolveForUser(
        int $userId,
        ?DateTimeImmutable $instant = null
    ): array {
        $instant = $instant ?? new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );
        $applicationTimezone = $this->timezone(
            (string) \config('timezone', 'UTC')
        );
        $initialDate = $instant
            ->setTimezone($applicationTimezone)
            ->format('Y-m-d');
        $initial = $this->calendars
            ->contextForUser($userId, $initialDate);

        if ($initial === null) {
            return $this->failure(
                'schedule_missing',
                'No active workforce calendar is assigned to your employee profile.'
            );
        }

        $timezone = $this->timezone(
            (string) ($initial['timezone'] ?? 'UTC')
        );
        $localInstant = $instant->setTimezone($timezone);
        $localDate = $localInstant->format('Y-m-d');
        $candidateDates = [
            $localInstant->modify('-1 day')
                ->format('Y-m-d'),
            $localDate,
        ];
        $candidates = [];

        foreach ($candidateDates as $candidateDate) {
            $context = $this->calendars
                ->contextForUser(
                    $userId,
                    $candidateDate
                );

            if (
                $context === null
                || empty($context['workingDay'])
                || $this->isFullHoliday($context)
            ) {
                continue;
            }

            $window = $this->window(
                $context,
                $candidateDate,
                $timezone
            );

            if ($window === null) {
                continue;
            }

            if (
                $localInstant >= $window['opensAt']
                && $localInstant <= $window['closesAt']
            ) {
                $context['localDate'] = $candidateDate;
                $context['scheduledStartAt'] =
                    $window['startsAt']->format(
                        'Y-m-d H:i:s'
                    );
                $context['scheduledEndAt'] =
                    $window['endsAt']->format(
                        'Y-m-d H:i:s'
                    );
                $context['scanWindowStartAt'] =
                    $window['opensAt']->format(
                        'Y-m-d H:i:s'
                    );
                $context['scanWindowEndAt'] =
                    $window['closesAt']->format(
                        'Y-m-d H:i:s'
                    );
                $candidates[] = $context;
            }
        }

        if ($candidates === []) {
            return $this->failure(
                'outside_window',
                'No attendance scan window is currently open.'
            ) + [
                'timezone' => $timezone->getName(),
                'scannedAt' => $localInstant->format(
                    'Y-m-d H:i:s'
                ),
                'localDate' => $localDate,
            ];
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int =>
                strcmp(
                    (string) $right['scheduledStartAt'],
                    (string) $left['scheduledStartAt']
                )
        );
        $schedule = $candidates[0];

        return [
            'successful' => true,
            'reason' => null,
            'message' => null,
            'attendanceDate' =>
                (string) $schedule['localDate'],
            'scannedAt' => $localInstant->format(
                'Y-m-d H:i:s'
            ),
            'timezone' => $timezone->getName(),
            'schedule' => $schedule,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, DateTimeImmutable>|null
     */
    private function window(
        array $context,
        string $attendanceDate,
        DateTimeZone $timezone
    ): ?array {
        $start = $this->atTime(
            $attendanceDate,
            (string) ($context['startTime'] ?? ''),
            $timezone
        );
        $end = $this->atTime(
            $attendanceDate,
            (string) ($context['endTime'] ?? ''),
            $timezone
        );

        if ($start === null || $end === null) {
            return null;
        }

        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }

        $openMinutes = max(
            0,
            min(
                720,
                (int) (
                    $context['scanOpenBeforeMinutes']
                    ?? 120
                )
            )
        );
        $closeMinutes = max(
            0,
            min(
                720,
                (int) (
                    $context['scanCloseAfterMinutes']
                    ?? 240
                )
            )
        );

        return [
            'startsAt' => $start,
            'endsAt' => $end,
            'opensAt' => $start->modify(
                '-' . $openMinutes . ' minutes'
            ),
            'closesAt' => $end->modify(
                '+' . $closeMinutes . ' minutes'
            ),
        ];
    }

    private function atTime(
        string $date,
        string $time,
        DateTimeZone $timezone
    ): ?DateTimeImmutable {
        $value = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $date . ' ' . $time,
            $timezone
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $value === false
            || (
                is_array($errors)
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
        ) {
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $context */
    private function isFullHoliday(array $context): bool
    {
        $holiday = is_array($context['holiday'] ?? null)
            ? $context['holiday']
            : null;

        return $holiday !== null
            && ($holiday['portion'] ?? 'full') === 'full';
    }

    /** @return array<string, mixed> */
    private function failure(
        string $reason,
        string $message
    ): array {
        return [
            'successful' => false,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    private function timezone(string $identifier): DateTimeZone
    {
        try {
            return new DateTimeZone($identifier);
        } catch (Throwable $exception) {
            return new DateTimeZone('UTC');
        }
    }
}
