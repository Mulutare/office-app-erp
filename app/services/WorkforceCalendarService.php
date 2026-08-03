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
        $previousDays = $this->calendars->days(
            $companyId,
            $calendarId
        );

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
            $breakStart = $this->nullable(
                $source['break_start_time'] ?? null
            );
            $breakEnd = $this->nullable(
                $source['break_end_time'] ?? null
            );
            $break = filter_var(
                $source['break_minutes'] ?? 0,
                FILTER_VALIDATE_INT
            );
            $break = is_int($break) ? $break : -1;
            $target = filter_var(
                $source['target_work_minutes'] ?? 480,
                FILTER_VALIDATE_INT
            );
            $target = is_int($target) ? $target : -1;
            $flex = filter_var(
                $source['flex_start_minutes'] ?? 0,
                FILTER_VALIDATE_INT
            );
            $flex = is_int($flex) ? $flex : -1;
            $scanOpen = filter_var(
                $source['scan_open_before_minutes']
                    ?? 120,
                FILTER_VALIDATE_INT
            );
            $scanOpen = is_int($scanOpen)
                ? $scanOpen
                : -1;
            $scanClose = filter_var(
                $source['scan_close_after_minutes']
                    ?? 240,
                FILTER_VALIDATE_INT
            );
            $scanClose = is_int($scanClose)
                ? $scanClose
                : -1;

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

            if ($target < 60 || $target > 960) {
                $errors['days'] =
                    'Net work target must be between 60 and 960 minutes on every working day.';
            }

            if ($flex < 0 || $flex > 240) {
                $errors['days'] =
                    'Flexible arrival must be between 0 and 240 minutes.';
            }

            if (
                $scanOpen < 0
                || $scanOpen > 720
                || $scanClose < 0
                || $scanClose > 720
            ) {
                $errors['days'] =
                    'Attendance scan windows must be between 0 and 720 minutes.';
            }

            if (
                $working
                && (($breakStart === null)
                    !== ($breakEnd === null))
            ) {
                $errors['days'] =
                    'Lunch start and end must both be entered or both be left empty.';
            } elseif (
                $working
                && $breakStart !== null
                && (
                    !$this->validTime($breakStart)
                    || !$this->validTime($breakEnd)
                    || !$this->validLunchWindow(
                        (string) $start,
                        (string) $end,
                        $breakStart,
                        (string) $breakEnd,
                        $break
                    )
                )
            ) {
                $errors['days'] =
                    'Lunch must fall inside the shift and its window must match the unpaid break minutes.';
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
                'break_start_time' => $working
                    ? $breakStart
                    : null,
                'break_end_time' => $working
                    ? $breakEnd
                    : null,
                'break_minutes' => $working
                    ? $break
                    : 0,
                'target_work_minutes' => $working
                    ? $target
                    : 0,
                'flex_start_minutes' => $working
                    ? $flex
                    : 0,
                'scan_open_before_minutes' => $working
                    ? $scanOpen
                    : 0,
                'scan_close_after_minutes' => $working
                    ? $scanClose
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
                ['days' => $previousDays],
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
        $scope = trim((string) (
            $input['assignment_scope'] ?? 'employee'
        ));
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
        $calendar = $this->calendars->calendar(
            $companyId,
            $calendarId
        );

        if (!in_array(
            $scope,
            ['company_default', 'employee'],
            true
        )) {
            $errors['assignment_scope'] =
                'Select company-wide coverage or a specific employee override.';
        }

        if (
            $calendar === null
            || empty($calendar['active'])
        ) {
            $errors['calendar_id'] =
                'Select an active company calendar.';
        }

        if (
            $errors === []
            && $scope === 'company_default'
        ) {
            return $this->setCompanyDefault(
                $companyId,
                $calendarId,
                $calendar,
                $actorUserId
            );
        }

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
            'scope' => 'employee',
        ];
    }

    /**
     * @param array<string, mixed> $calendar
     * @return array<string, mixed>
     */
    private function setCompanyDefault(
        int $companyId,
        int $calendarId,
        array $calendar,
        int $actorUserId
    ): array {
        $previousCalendarId = null;

        foreach (
            $this->calendars->calendars($companyId)
            as $candidate
        ) {
            if (!empty($candidate['is_default'])) {
                $previousCalendarId = (int) (
                    $candidate['calendar_id'] ?? 0
                );
                break;
            }
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $this->calendars->clearDefault($companyId);
            $this->calendars->setDefault(
                $companyId,
                $calendarId,
                $actorUserId
            );
            $this->auditLogs->record(
                $actorUserId,
                'SET_DEFAULT_WORKFORCE_CALENDAR',
                'attendance',
                'workforce_calendars',
                (string) $calendarId,
                [
                    'calendar_id' =>
                        $previousCalendarId,
                ],
                [
                    'calendar_id' => $calendarId,
                    'calendar_name' => (string) (
                        $calendar['name'] ?? ''
                    ),
                    'coverage' =>
                        'all_employees_without_override',
                ],
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
            'scope' => 'company_default',
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

        return $this->presentContext($context);
    }

    /** @return array<string, mixed>|null */
    public function contextForEmployee(
        int $employeeId,
        string $localDate
    ): ?array {
        $context = $this->calendars
            ->contextForEmployee(
                $this->tenant->companyId(),
                $employeeId,
                $localDate
            );

        return $this->presentContext($context);
    }

    /**
     * @param array<string, mixed>|null $context
     * @return array<string, mixed>|null
     */
    private function presentContext(?array $context): ?array
    {
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
            'breakStartTime' => (string) (
                $day['break_start_time'] ?? ''
            ),
            'breakEndTime' => (string) (
                $day['break_end_time'] ?? ''
            ),
            'targetWorkMinutes' => (int) (
                $day['target_work_minutes'] ?? 0
            ),
            'flexStartMinutes' => (int) (
                $day['flex_start_minutes'] ?? 0
            ),
            'scanOpenBeforeMinutes' => (int) (
                $day['scan_open_before_minutes'] ?? 120
            ),
            'scanCloseAfterMinutes' => (int) (
                $day['scan_close_after_minutes'] ?? 240
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
                'break_start_time' => $working
                    ? '12:30'
                    : null,
                'break_end_time' => $working
                    ? '13:30'
                    : null,
                'break_minutes' => $working
                    ? 60
                    : 0,
                'target_work_minutes' => $working
                    ? 480
                    : 0,
                'flex_start_minutes' => $working
                    ? 30
                    : 0,
                'scan_open_before_minutes' => $working
                    ? 120
                    : 0,
                'scan_close_after_minutes' => $working
                    ? 240
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
            'break_start_time' =>
                $day['break_start_time'] ?? null,
            'break_end_time' =>
                $day['break_end_time'] ?? null,
            'break_minutes' => (int) (
                $day['break_minutes'] ?? 0
            ),
            'target_work_minutes' => (int) (
                $day['target_work_minutes'] ?? 0
            ),
            'flex_start_minutes' => (int) (
                $day['flex_start_minutes'] ?? 0
            ),
            'scan_open_before_minutes' => (int) (
                $day['scan_open_before_minutes'] ?? 120
            ),
            'scan_close_after_minutes' => (int) (
                $day['scan_close_after_minutes'] ?? 240
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

    private function validLunchWindow(
        string $shiftStart,
        string $shiftEnd,
        string $breakStart,
        string $breakEnd,
        int $breakMinutes
    ): bool {
        $shiftStartMinutes =
            $this->timeMinutes($shiftStart);
        $shiftEndMinutes =
            $this->timeMinutes($shiftEnd);
        $breakStartMinutes =
            $this->timeMinutes($breakStart);
        $breakEndMinutes =
            $this->timeMinutes($breakEnd);
        $shiftDuration = (
            $shiftEndMinutes
            - $shiftStartMinutes
            + 1440
        ) % 1440;
        $breakOffset = (
            $breakStartMinutes
            - $shiftStartMinutes
            + 1440
        ) % 1440;
        $breakDuration = (
            $breakEndMinutes
            - $breakStartMinutes
            + 1440
        ) % 1440;

        return $shiftDuration > 0
            && $breakDuration > 0
            && $breakDuration === $breakMinutes
            && $breakOffset > 0
            && ($breakOffset + $breakDuration)
                < $shiftDuration;
    }

    private function timeMinutes(string $time): int
    {
        [$hour, $minute] = array_map(
            'intval',
            explode(':', $time)
        );

        return ($hour * 60) + $minute;
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
