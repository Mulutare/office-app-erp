<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\RepositoryFactory;
use App\Repositories\WorkforceCalendarRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

final class WorkforceCalendarService
{
    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    private WorkforceCalendarRepository $calendars;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?WorkforceCalendarRepository $calendars = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->calendars = $calendars
            ?? RepositoryFactory::workforceCalendars();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /** @return array<string, mixed> */
    public function workspace(
        int $selectedCalendarId = 0,
        int $year = 0
    ): array {
        $companyId = $this->tenant->companyId();
        $year = $year >= 2000 && $year <= 2100
            ? $year
            : (int) date('Y');
        $calendars = $this->calendars
            ->calendars($companyId);

        if ($selectedCalendarId < 1) {
            foreach ($calendars as $calendar) {
                if (!empty($calendar['is_default'])) {
                    $selectedCalendarId = (int) (
                        $calendar['calendar_id'] ?? 0
                    );
                    break;
                }
            }
        }

        if (
            $selectedCalendarId < 1
            && $calendars !== []
        ) {
            $selectedCalendarId = (int) (
                $calendars[0]['calendar_id'] ?? 0
            );
        }

        $selected = $selectedCalendarId > 0
            ? $this->calendars->calendar(
                $companyId,
                $selectedCalendarId
            )
            : null;

        if (
            $selected === null
            && $calendars !== []
        ) {
            $selectedCalendarId = 0;

            foreach ($calendars as $calendar) {
                if (!empty($calendar['is_default'])) {
                    $selectedCalendarId = (int) (
                        $calendar['calendar_id'] ?? 0
                    );
                    break;
                }
            }

            if ($selectedCalendarId < 1) {
                $selectedCalendarId = (int) (
                    $calendars[0]['calendar_id'] ?? 0
                );
            }

            $selected = $selectedCalendarId > 0
                ? $this->calendars->calendar(
                    $companyId,
                    $selectedCalendarId
                )
                : null;
        }

        $days = $this->defaultWeek();

        if ($selected !== null) {
            foreach (
                $this->calendars->days(
                    $companyId,
                    $selectedCalendarId
                ) as $day
            ) {
                $weekday = (int) (
                    $day['iso_weekday'] ?? 0
                );

                if (isset($days[$weekday])) {
                    $days[$weekday] = $this->presentDay(
                        $day
                    );
                }
            }
        }

        $employees = $this->calendars
            ->employeeOptions($companyId);
        $assignments = $this->calendars
            ->assignments($companyId);

        foreach ($employees as &$employee) {
            $employee['displayName'] =
                $this->employeeName($employee);
        }
        unset($employee);

        foreach ($assignments as &$assignment) {
            $assignment['employeeName'] =
                $this->employeeName($assignment);
        }
        unset($assignment);

        return [
            'calendars' => $calendars,
            'selected' => $selected,
            'days' => $days,
            'holidays' => $selected === null
                ? []
                : $this->calendars->holidays(
                    $companyId,
                    $selectedCalendarId,
                    sprintf('%04d-01-01', $year),
                    sprintf('%04d-12-31', $year)
                ),
            'employees' => $employees,
            'assignments' => $assignments,
            'year' => $year,
            'weekdays' => self::WEEKDAYS,
            'timezones' => $this->timezones(),
        ];
    }

    /** @param array<string, mixed> $input */
    public function create(
        array $input,
        int $actorUserId
    ): array {
        $companyId = $this->tenant->companyId();
        $values = $this->normalizeCalendar($input);
        $existingCalendars = $this->calendars
            ->calendars($companyId);

        if ($existingCalendars === []) {
            $values['is_default'] = true;
        }

        $errors = $this->validateCalendar($values);

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
                'old' => $input,
            ];
        }

        foreach ($existingCalendars as $calendar) {
            if (
                strtoupper((string) (
                    $calendar['code'] ?? ''
                )) === $values['code']
            ) {
                return [
                    'successful' => false,
                    'errors' => [
                        'code' =>
                            'That calendar code is already used by this company.',
                    ],
                    'old' => $input,
                ];
            }
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            if (!empty($values['is_default'])) {
                $this->calendars->clearDefault(
                    $companyId
                );
            }

            $calendarId = $this->calendars
                ->createCalendar(
                    $companyId,
                    $values,
                    $actorUserId
                );

            foreach ($this->defaultWeek() as $day) {
                $this->calendars->saveDay(
                    $companyId,
                    $calendarId,
                    (int) $day['iso_weekday'],
                    $day
                );
            }

            $this->auditLogs->record(
                $actorUserId,
                'CREATE_WORKFORCE_CALENDAR',
                'attendance',
                'workforce_calendars',
                (string) $calendarId,
                null,
                $values,
                $companyId
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            if (
                $exception instanceof PDOException
                && in_array(
                    (string) $exception->getCode(),
                    ['23000', '23505'],
                    true
                )
            ) {
                return [
                    'successful' => false,
                    'errors' => [
                        'code' =>
                            'That calendar conflicts with an existing company calendar.',
                    ],
                    'old' => $input,
                ];
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'calendarId' => $calendarId,
            'name' => $values['name'],
        ];
    }

    /** @param array<string, mixed> $input */
    public function saveWeek(
        int $calendarId,
        array $input,
        int $actorUserId
    ): array {
        $companyId = $this->tenant->companyId();
        $calendar = $this->calendars->calendar(
            $companyId,
            $calendarId
        );

        if ($calendar === null) {
            return [
                'successful' => false,
                'notFound' => true,
                'errors' => [],
            ];
        }

        $days = [];
        $errors = [];
        $workingDays = 0;

        foreach (self::WEEKDAYS as $weekday => $label) {
            $source = is_array(
                $input[$weekday] ?? null
            )
                ? $input[$weekday]
                : [];
            $working = !empty($source['working_day']);
            $start = $this->nullable(
                $source['start_time'] ?? null
            );
            $end = $this->nullable(
                $source['end_time'] ?? null
            );
            $break = filter_var(
                $source['break_minutes'] ?? 0,
                FILTER_VALIDATE_INT
            );
            $break = is_int($break) ? $break : -1;

            if (
                $working
                && (
                    !$this->validTime($start)
                    || !$this->validTime($end)
                )
            ) {
                $errors['days'] =
                    'Every working day requires valid start and end times.';
            }

            if ($break < 0 || $break > 480) {
                $errors['days'] =
                    'Break time must be between 0 and 480 minutes.';
            }

            $workingDays += $working ? 1 : 0;
            $days[$weekday] = [
                'iso_weekday' => $weekday,
                'working_day' => $working,
                'start_time' => $working
                    ? $start
                    : null,
                'end_time' => $working
                    ? $end
                    : null,
                'break_minutes' => $working
                    ? $break
                    : 0,
            ];
        }

        if ($workingDays < 1) {
            $errors['days'] =
                'Select at least one working day.';
        }

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
                'old' => $input,
            ];
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            foreach ($days as $weekday => $day) {
                $this->calendars->saveDay(
                    $companyId,
                    $calendarId,
                    $weekday,
                    $day
                );
            }

            $this->auditLogs->record(
                $actorUserId,
                'UPDATE_WORKFORCE_WEEK',
                'attendance',
                'workforce_calendar_days',
                (string) $calendarId,
                null,
                ['days' => $days],
                $companyId
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'errors' => [],
        ];
    }

    /** @param array<string, mixed> $input */
    public function addHoliday(
        int $calendarId,
        array $input,
        int $actorUserId
    ): array {
        $companyId = $this->tenant->companyId();
        $calendar = $this->calendars->calendar(
            $companyId,
            $calendarId
        );

        if ($calendar === null) {
            return [
                'successful' => false,
                'notFound' => true,
                'errors' => [],
            ];
        }

        $values = [
            'holiday_date' => trim((string) (
                $input['holiday_date'] ?? ''
            )),
            'name' => trim((string) (
                $input['name'] ?? ''
            )),
            'holiday_type' => strtolower(
                trim((string) (
                    $input['holiday_type'] ?? ''
                ))
            ),
            'day_portion' => strtolower(
                trim((string) (
                    $input['day_portion'] ?? ''
                ))
            ),
            'observed' => !empty($input['observed']),
            'description' => $this->nullable(
                $input['description'] ?? null
            ),
        ];
        $errors = [];

        if (!$this->validDate(
            $values['holiday_date']
        )) {
            $errors['holiday_date'] =
                'Enter a valid holiday date.';
        }

        if (
            mb_strlen($values['name']) < 2
            || mb_strlen($values['name']) > 150
        ) {
            $errors['name'] =
                'Holiday name must contain 2–150 characters.';
        }

        if (!in_array(
            $values['holiday_type'],
            ['public', 'company'],
            true
        )) {
            $errors['holiday_type'] =
                'Select public or company holiday.';
        }

        if (!in_array(
            $values['day_portion'],
            ['full', 'am', 'pm'],
            true
        )) {
            $errors['day_portion'] =
                'Select a valid day portion.';
        }

        if (
            is_string($values['description'])
            && mb_strlen(
                $values['description']
            ) > 500
        ) {
            $errors['description'] =
                'Description cannot exceed 500 characters.';
        }

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
                'old' => $input,
            ];
        }

        try {
            $holidayId = $this->calendars
                ->addHoliday(
                    $companyId,
                    $calendarId,
                    $values,
                    $actorUserId
                );
        } catch (PDOException $exception) {
            if (in_array(
                (string) $exception->getCode(),
                ['23000', '23505'],
                true
            )) {
                return [
                    'successful' => false,
                    'errors' => [
                        'name' =>
                            'That holiday already exists on this calendar date.',
                    ],
                    'old' => $input,
                ];
            }

            throw $exception;
        }

        $this->auditLogs->record(
            $actorUserId,
            'CREATE_WORKFORCE_HOLIDAY',
            'attendance',
            'workforce_holidays',
            (string) $holidayId,
            null,
            $values + [
                'calendar_id' => $calendarId,
            ],
            $companyId
        );

        return [
            'successful' => true,
            'errors' => [],
            'name' => $values['name'],
        ];
    }

    /** @param array<string, mixed> $input */
    public function assign(
        array $input,
        int $actorUserId
    ): array {
        $companyId = $this->tenant->companyId();
        $employeeId = $this->integer(
            $input['employee_id'] ?? null
        );
        $calendarId = $this->integer(
            $input['calendar_id'] ?? null
        );
        $from = trim((string) (
            $input['effective_from'] ?? ''
        ));
        $to = $this->nullable(
            $input['effective_to'] ?? null
        );
        $errors = [];
        $employeeIds = array_map(
            static fn (array $employee): int =>
                (int) ($employee['employee_id'] ?? 0),
            $this->calendars->employeeOptions(
                $companyId
            )
        );

        if (!in_array(
            $employeeId,
            $employeeIds,
            true
        )) {
            $errors['employee_id'] =
                'Select an active employee from this company.';
        }

        if ($this->calendars->calendar(
            $companyId,
            $calendarId
        ) === null) {
            $errors['calendar_id'] =
                'Select an active company calendar.';
        }

        if (!$this->validDate($from)) {
            $errors['effective_from'] =
                'Enter a valid effective date.';
        }

        if (
            $to !== null
            && (
                !$this->validDate($to)
                || (
                    $this->validDate($from)
                    && $to < $from
                )
            )
        ) {
            $errors['effective_to'] =
                'End date must be blank or on/after the start date.';
        }

        if (
            $errors === []
            && $this->calendars->scheduleOverlaps(
                $companyId,
                $employeeId,
                $from,
                $to
            )
        ) {
            $errors['effective_from'] =
                'This employee already has an overlapping work schedule.';
        }

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
                'old' => $input,
            ];
        }

        $scheduleId = $this->calendars
            ->assignSchedule(
                $companyId,
                $employeeId,
                $calendarId,
                $from,
                $to,
                $actorUserId
            );
        $this->auditLogs->record(
            $actorUserId,
            'ASSIGN_WORK_SCHEDULE',
            'attendance',
            'employee_work_schedules',
            (string) $scheduleId,
            null,
            [
                'employee_id' => $employeeId,
                'calendar_id' => $calendarId,
                'effective_from' => $from,
                'effective_to' => $to,
            ],
            $companyId
        );

        return [
            'successful' => true,
            'errors' => [],
        ];
    }

    /** @return array<string, mixed>|null */
    public function contextForUser(
        int $userId,
        string $localDate
    ): ?array {
        $context = $this->calendars
            ->contextForUser(
                $this->tenant->companyId(),
                $userId,
                $localDate
            );

        if ($context === null) {
            return null;
        }

        $calendar = is_array(
            $context['calendar'] ?? null
        )
            ? $context['calendar']
            : [];
        $day = is_array($context['day'] ?? null)
            ? $context['day']
            : null;
        $holiday = is_array(
            $context['holiday'] ?? null
        )
            ? $context['holiday']
            : null;

        return [
            'employeeId' => (int) (
                $context['employee_id'] ?? 0
            ),
            'calendarId' => (int) (
                $calendar['calendar_id'] ?? 0
            ),
            'calendarName' => (string) (
                $calendar['name'] ?? ''
            ),
            'timezone' => (string) (
                $calendar['timezone'] ?? 'UTC'
            ),
            'countryCode' => (string) (
                $calendar['country_code'] ?? ''
            ),
            'subdivisionCode' => (string) (
                $calendar['subdivision_code'] ?? ''
            ),
            'workingDay' => $day !== null
                && !empty($day['working_day']),
            'startTime' => (string) (
                $day['start_time'] ?? ''
            ),
            'endTime' => (string) (
                $day['end_time'] ?? ''
            ),
            'breakMinutes' => (int) (
                $day['break_minutes'] ?? 0
            ),
            'holiday' => $holiday === null
                ? null
                : [
                    'name' => (string) (
                        $holiday['name'] ?? ''
                    ),
                    'type' => (string) (
                        $holiday['holiday_type']
                            ?? 'public'
                    ),
                    'portion' => (string) (
                        $holiday['day_portion']
                            ?? 'full'
                    ),
                    'observed' => !empty(
                        $holiday['observed']
                    ),
                ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function defaultWeek(): array
    {
        $days = [];

        foreach (self::WEEKDAYS as $weekday => $label) {
            $working = $weekday <= 5;
            $days[$weekday] = [
                'iso_weekday' => $weekday,
                'label' => $label,
                'working_day' => $working,
                'start_time' => $working
                    ? '08:30'
                    : null,
                'end_time' => $working
                    ? '17:30'
                    : null,
                'break_minutes' => $working
                    ? 60
                    : 0,
            ];
        }

        return $days;
    }

    /** @param array<string, mixed> $day */
    private function presentDay(array $day): array
    {
        $weekday = (int) (
            $day['iso_weekday'] ?? 0
        );

        return [
            'iso_weekday' => $weekday,
            'label' => self::WEEKDAYS[$weekday] ?? '',
            'working_day' =>
                !empty($day['working_day']),
            'start_time' => $day['start_time'] ?? null,
            'end_time' => $day['end_time'] ?? null,
            'break_minutes' => (int) (
                $day['break_minutes'] ?? 0
            ),
        ];
    }

    /** @param array<string, mixed> $input */
    private function normalizeCalendar(array $input): array
    {
        return [
            'code' => strtoupper(trim((string) (
                $input['code'] ?? ''
            ))),
            'name' => trim((string) (
                $input['name'] ?? ''
            )),
            'timezone' => trim((string) (
                $input['timezone'] ?? ''
            )),
            'country_code' => $this->nullable(
                strtoupper(trim((string) (
                    $input['country_code'] ?? ''
                )))
            ),
            'subdivision_code' => $this->nullable(
                strtoupper(trim((string) (
                    $input['subdivision_code'] ?? ''
                )))
            ),
            'week_start' => $this->integer(
                $input['week_start'] ?? 1
            ),
            'is_default' => !empty(
                $input['is_default']
            ),
        ];
    }

    /** @param array<string, mixed> $values */
    private function validateCalendar(array $values): array
    {
        $errors = [];

        if (preg_match(
            '/^[A-Z][A-Z0-9_-]{1,39}$/',
            (string) $values['code']
        ) !== 1) {
            $errors['code'] =
                'Code must contain 2–40 uppercase letters, numbers, hyphens or underscores.';
        }

        $nameLength = mb_strlen(
            (string) $values['name']
        );

        if ($nameLength < 2 || $nameLength > 120) {
            $errors['name'] =
                'Name must contain 2–120 characters.';
        }

        if (!in_array(
            $values['timezone'],
            DateTimeZone::listIdentifiers(),
            true
        )) {
            $errors['timezone'] =
                'Select a valid IANA timezone.';
        }

        if (
            $values['country_code'] !== null
            && preg_match(
                '/^[A-Z]{2}$/',
                (string) $values['country_code']
            ) !== 1
        ) {
            $errors['country_code'] =
                'Country code must be a two-letter ISO code.';
        }

        if (
            $values['subdivision_code'] !== null
            && preg_match(
                '/^[A-Z0-9-]{1,16}$/',
                (string) $values['subdivision_code']
            ) !== 1
        ) {
            $errors['subdivision_code'] =
                'Subdivision code may contain letters, numbers and hyphens.';
        }

        if (
            (int) $values['week_start'] < 1
            || (int) $values['week_start'] > 7
        ) {
            $errors['week_start'] =
                'Select a valid first day of the week.';
        }

        return $errors;
    }

    /** @return array<string, string> */
    private function timezones(): array
    {
        $options = [];

        foreach (
            DateTimeZone::listIdentifiers()
            as $timezone
        ) {
            $options[$timezone] = str_replace(
                '_',
                ' ',
                $timezone
            );
        }

        return $options;
    }

    private function employeeName(array $employee): string
    {
        $preferred = trim((string) (
            $employee['preferred_name'] ?? ''
        ));
        $first = trim((string) (
            $employee['first_name'] ?? ''
        ));
        $last = trim((string) (
            $employee['last_name'] ?? ''
        ));

        return trim(
            ($preferred !== '' ? $preferred : $first)
            . ' ' . $last
        );
    }

    private function validTime(?string $value): bool
    {
        if (
            !is_string($value)
            || preg_match(
                '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
                $value
            ) !== 1
        ) {
            return false;
        }

        return true;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );

        return $date !== false
            && $date->format('Y-m-d') === $value;
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : 0;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
