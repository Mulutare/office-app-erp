<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\WorkforceCalendarRepository
    as WorkforceCalendarRepositoryContract;

final class WorkforceCalendarRepository extends MySqlRepository
    implements WorkforceCalendarRepositoryContract
{
    public function calendars(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                calendars.*,
                COUNT(DISTINCT schedules.schedule_id)
                    AS assignment_count,
                COUNT(DISTINCT holidays.holiday_id)
                    AS holiday_count
             FROM workforce_calendars calendars
             LEFT JOIN employee_work_schedules schedules
               ON schedules.company_id =
                    calendars.company_id
              AND schedules.calendar_id =
                    calendars.calendar_id
              AND schedules.active = TRUE
             LEFT JOIN workforce_holidays holidays
               ON holidays.company_id =
                    calendars.company_id
              AND holidays.calendar_id =
                    calendars.calendar_id
              AND holidays.holiday_date >= CURRENT_DATE
             WHERE calendars.company_id = :company_id
             GROUP BY calendars.calendar_id
             ORDER BY
                calendars.is_default DESC,
                calendars.active DESC,
                calendars.name'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);

        return $this->rows($statement);
    }

    public function calendar(
        int $companyId,
        int $calendarId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT *
             FROM workforce_calendars
             WHERE company_id = :company_id
               AND calendar_id = :calendar_id
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'calendar_id' => $calendarId,
        ]);

        return $this->row($statement);
    }

    public function createCalendar(
        int $companyId,
        array $values,
        int $actorUserId
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO workforce_calendars
                (
                    company_id,
                    code,
                    name,
                    timezone,
                    country_code,
                    subdivision_code,
                    week_start,
                    is_default,
                    active,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :code,
                    :name,
                    :timezone,
                    :country_code,
                    :subdivision_code,
                    :week_start,
                    :is_default,
                    TRUE,
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'code' => $values['code'],
            'name' => $values['name'],
            'timezone' => $values['timezone'],
            'country_code' => $values['country_code'],
            'subdivision_code' =>
                $values['subdivision_code'],
            'week_start' => $values['week_start'],
            'is_default' =>
                !empty($values['is_default']) ? 1 : 0,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);

        return (int) $this->connection()
            ->lastInsertId();
    }

    public function clearDefault(int $companyId): void
    {
        $statement = $this->connection()->prepare(
            'UPDATE workforce_calendars
             SET is_default = FALSE,
                 updated_at = CURRENT_TIMESTAMP
             WHERE company_id = :company_id
               AND is_default = TRUE'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
    }

    public function setDefault(
        int $companyId,
        int $calendarId,
        int $actorUserId
    ): void {
        $statement = $this->connection()->prepare(
            'UPDATE workforce_calendars
             SET is_default = TRUE,
                 updated_by = :updated_by,
                 updated_at = CURRENT_TIMESTAMP
             WHERE company_id = :company_id
               AND calendar_id = :calendar_id
               AND active = TRUE'
        );
        $statement->execute([
            'company_id' => $companyId,
            'calendar_id' => $calendarId,
            'updated_by' => $actorUserId,
        ]);

        if (
            $statement->rowCount() !== 1
            && $this->calendar(
                $companyId,
                $calendarId
            ) === null
        ) {
            throw new \RuntimeException(
                'The workforce calendar is not available in the active company.'
            );
        }
    }

    public function days(
        int $companyId,
        int $calendarId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT days.*
             FROM workforce_calendar_days days
             INNER JOIN workforce_calendars calendars
               ON calendars.calendar_id =
                    days.calendar_id
             WHERE calendars.company_id = :company_id
               AND calendars.calendar_id =
                    :calendar_id
             ORDER BY days.iso_weekday'
        );
        $statement->execute([
            'company_id' => $companyId,
            'calendar_id' => $calendarId,
        ]);

        return $this->rows($statement);
    }

    public function saveDay(
        int $companyId,
        int $calendarId,
        int $isoWeekday,
        array $values
    ): void {
        if ($this->calendar(
            $companyId,
            $calendarId
        ) === null) {
            throw new \RuntimeException(
                'The workforce calendar does not belong to the active company.'
            );
        }

        $statement = $this->connection()->prepare(
            'INSERT INTO workforce_calendar_days
                (
                    calendar_id,
                    iso_weekday,
                    working_day,
                    start_time,
                    end_time,
                    break_start_time,
                    break_end_time,
                    break_minutes,
                    target_work_minutes,
                    flex_start_minutes,
                    scan_open_before_minutes,
                    scan_close_after_minutes
                )
             VALUES
                (
                    :calendar_id,
                    :iso_weekday,
                    :working_day,
                    :start_time,
                    :end_time,
                    :break_start_time,
                    :break_end_time,
                    :break_minutes,
                    :target_work_minutes,
                    :flex_start_minutes,
                    :scan_open_before_minutes,
                    :scan_close_after_minutes
                )
             ON DUPLICATE KEY UPDATE
                working_day = VALUES(working_day),
                start_time = VALUES(start_time),
                end_time = VALUES(end_time),
                break_start_time =
                    VALUES(break_start_time),
                break_end_time =
                    VALUES(break_end_time),
                break_minutes = VALUES(break_minutes),
                target_work_minutes =
                    VALUES(target_work_minutes),
                flex_start_minutes =
                    VALUES(flex_start_minutes),
                scan_open_before_minutes =
                    VALUES(scan_open_before_minutes),
                scan_close_after_minutes =
                    VALUES(scan_close_after_minutes)'
        );
        $statement->execute([
            'calendar_id' => $calendarId,
            'iso_weekday' => $isoWeekday,
            'working_day' =>
                !empty($values['working_day']) ? 1 : 0,
            'start_time' => $values['start_time'],
            'end_time' => $values['end_time'],
            'break_start_time' =>
                $values['break_start_time'],
            'break_end_time' =>
                $values['break_end_time'],
            'break_minutes' =>
                $values['break_minutes'],
            'target_work_minutes' =>
                $values['target_work_minutes'],
            'flex_start_minutes' =>
                $values['flex_start_minutes'],
            'scan_open_before_minutes' =>
                $values['scan_open_before_minutes'],
            'scan_close_after_minutes' =>
                $values['scan_close_after_minutes'],
        ]);
    }

    public function holidays(
        int $companyId,
        int $calendarId,
        string $fromDate,
        string $toDate
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT *
             FROM workforce_holidays
             WHERE company_id = :company_id
               AND calendar_id = :calendar_id
               AND holiday_date BETWEEN
                    :from_date AND :to_date
             ORDER BY holiday_date, name'
        );
        $statement->execute([
            'company_id' => $companyId,
            'calendar_id' => $calendarId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);

        return $this->rows($statement);
    }

    public function addHoliday(
        int $companyId,
        int $calendarId,
        array $values,
        int $actorUserId
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO workforce_holidays
                (
                    company_id,
                    calendar_id,
                    holiday_date,
                    name,
                    holiday_type,
                    day_portion,
                    observed,
                    description,
                    created_by
                )
             VALUES
                (
                    :company_id,
                    :calendar_id,
                    :holiday_date,
                    :name,
                    :holiday_type,
                    :day_portion,
                    :observed,
                    :description,
                    :created_by
                )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'calendar_id' => $calendarId,
            'holiday_date' => $values['holiday_date'],
            'name' => $values['name'],
            'holiday_type' => $values['holiday_type'],
            'day_portion' => $values['day_portion'],
            'observed' =>
                !empty($values['observed']) ? 1 : 0,
            'description' => $values['description'],
            'created_by' => $actorUserId,
        ]);

        return (int) $this->connection()
            ->lastInsertId();
    }

    public function employeeOptions(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                employee_id,
                employee_number,
                first_name,
                last_name,
                preferred_name,
                job_title
             FROM hr_employees
             WHERE company_id = :company_id
               AND employment_status
                    IN (\'active\', \'on_leave\')
               AND deleted_at IS NULL
             ORDER BY
                COALESCE(
                    NULLIF(preferred_name, \'\'),
                    first_name
                ),
                last_name,
                employee_number'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);

        return $this->rows($statement);
    }

    public function assignments(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                schedules.*,
                calendars.name AS calendar_name,
                calendars.timezone,
                employees.employee_number,
                employees.first_name,
                employees.last_name,
                employees.preferred_name,
                employees.job_title
             FROM employee_work_schedules schedules
             INNER JOIN workforce_calendars calendars
               ON calendars.company_id =
                    schedules.company_id
              AND calendars.calendar_id =
                    schedules.calendar_id
             INNER JOIN hr_employees employees
               ON employees.company_id =
                    schedules.company_id
              AND employees.employee_id =
                    schedules.employee_id
             WHERE schedules.company_id = :company_id
               AND schedules.active = TRUE
             ORDER BY
                schedules.effective_from DESC,
                employees.last_name,
                employees.first_name'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);

        return $this->rows($statement);
    }

    public function scheduleOverlaps(
        int $companyId,
        int $employeeId,
        string $effectiveFrom,
        ?string $effectiveTo
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM employee_work_schedules
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND active = TRUE
               AND effective_from <=
                    COALESCE(:effective_to, \'9999-12-31\')
               AND COALESCE(
                    effective_to,
                    \'9999-12-31\'
               ) >= :effective_from
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function assignSchedule(
        int $companyId,
        int $employeeId,
        int $calendarId,
        string $effectiveFrom,
        ?string $effectiveTo,
        int $actorUserId
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO employee_work_schedules
                (
                    company_id,
                    employee_id,
                    calendar_id,
                    effective_from,
                    effective_to,
                    active,
                    created_by
                )
             SELECT
                :company_id,
                employees.employee_id,
                calendars.calendar_id,
                :effective_from,
                :effective_to,
                TRUE,
                :created_by
             FROM hr_employees employees
             INNER JOIN workforce_calendars calendars
               ON calendars.company_id =
                    employees.company_id
              AND calendars.calendar_id =
                    :calendar_id
              AND calendars.active = TRUE
             WHERE employees.company_id =
                    :employee_company_id
               AND employees.employee_id =
                    :employee_id
               AND employees.deleted_at IS NULL'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_company_id' => $companyId,
            'employee_id' => $employeeId,
            'calendar_id' => $calendarId,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'created_by' => $actorUserId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException(
                'The employee or calendar is not available in the active company.'
            );
        }

        return (int) $this->connection()
            ->lastInsertId();
    }

    public function contextForUser(
        int $companyId,
        int $userId,
        string $localDate
    ): ?array {
        $employeeStatement = $this->connection()
            ->prepare(
                'SELECT employee_id
                 FROM hr_employees
                 WHERE company_id = :company_id
                   AND user_id = :user_id
                   AND employment_status
                        IN (\'active\', \'on_leave\')
                   AND deleted_at IS NULL
                 LIMIT 1'
            );
        $employeeStatement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);
        $employeeId = (int) (
            $employeeStatement->fetchColumn() ?: 0
        );

        if ($employeeId < 1) {
            return null;
        }

        return $this->contextForEmployee(
            $companyId,
            $employeeId,
            $localDate
        );
    }

    public function contextForEmployee(
        int $companyId,
        int $employeeId,
        string $localDate
    ): ?array {
        if ($employeeId < 1) {
            return null;
        }

        $calendarStatement = $this->connection()
            ->prepare(
                'SELECT calendars.*
                 FROM workforce_calendars calendars
                 LEFT JOIN employee_work_schedules schedules
                   ON schedules.company_id =
                        calendars.company_id
                  AND schedules.calendar_id =
                        calendars.calendar_id
                  AND schedules.employee_id =
                        :employee_id
                  AND schedules.active = TRUE
                  AND schedules.effective_from <=
                        :effective_date
                  AND (
                        schedules.effective_to IS NULL
                        OR schedules.effective_to >=
                            :effective_to_date
                  )
                 WHERE calendars.company_id =
                        :company_id
                   AND calendars.active = TRUE
                   AND (
                        schedules.schedule_id IS NOT NULL
                        OR calendars.is_default = TRUE
                   )
                 ORDER BY
                    (schedules.schedule_id IS NOT NULL)
                        DESC,
                    schedules.effective_from DESC,
                    calendars.is_default DESC
                 LIMIT 1'
            );
        $calendarStatement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'effective_date' => $localDate,
            'effective_to_date' => $localDate,
        ]);
        $calendar = $this->row(
            $calendarStatement
        );

        if ($calendar === null) {
            return null;
        }

        $dayStatement = $this->connection()->prepare(
            'SELECT *
             FROM workforce_calendar_days
             WHERE calendar_id = :calendar_id
               AND iso_weekday = :iso_weekday
             LIMIT 1'
        );
        $dayStatement->execute([
            'calendar_id' =>
                (int) $calendar['calendar_id'],
            'iso_weekday' =>
                (int) date('N', strtotime($localDate)),
        ]);
        $holidayStatement = $this->connection()
            ->prepare(
                'SELECT *
                 FROM workforce_holidays
                 WHERE company_id = :company_id
                   AND calendar_id = :calendar_id
                   AND holiday_date = :holiday_date
                 ORDER BY holiday_id
                 LIMIT 1'
            );
        $holidayStatement->execute([
            'company_id' => $companyId,
            'calendar_id' =>
                (int) $calendar['calendar_id'],
            'holiday_date' => $localDate,
        ]);

        return [
            'employee_id' => $employeeId,
            'calendar' => $calendar,
            'day' => $this->row($dayStatement),
            'holiday' =>
                $this->row($holidayStatement),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(\PDOStatement $statement): array
    {
        $rows = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    private function row(\PDOStatement $statement): ?array
    {
        $row = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($row) ? $row : null;
    }
}
