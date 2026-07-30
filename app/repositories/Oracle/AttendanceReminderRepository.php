<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\AttendanceReminderRepository
    as AttendanceReminderRepositoryContract;

final class AttendanceReminderRepository
    implements AttendanceReminderRepositoryContract
{
    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(
        int $companyId,
        int $userId
    ): ?array {
        $statement = \db()->prepare(
            'SELECT
                reminder_id,
                company_id,
                user_id,
                timezone,
                workday_mask,
                check_in_enabled,
                check_in_time,
                check_out_enabled,
                check_out_time,
                reminder_lead_minutes,
                browser_notifications_enabled,
                created_at,
                updated_at
             FROM attendance_user_reminders
             WHERE company_id = :company_id
               AND user_id = :user_id
             FETCH FIRST 1 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);
        $reminder = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($reminder)
            ? $reminder
            : null;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function saveForUser(
        int $companyId,
        int $userId,
        array $values
    ): void {
        $statement = \db()->prepare(
            'MERGE INTO attendance_user_reminders target
             USING (
                SELECT
                    :company_id AS company_id,
                    :user_id AS user_id,
                    :timezone AS timezone,
                    :workday_mask AS workday_mask,
                    :check_in_enabled
                        AS check_in_enabled,
                    :check_in_time AS check_in_time,
                    :check_out_enabled
                        AS check_out_enabled,
                    :check_out_time AS check_out_time,
                    :reminder_lead_minutes
                        AS reminder_lead_minutes,
                    :browser_notifications_enabled
                        AS browser_notifications_enabled
                FROM dual
             ) source
             ON (
                target.company_id = source.company_id
                AND target.user_id = source.user_id
             )
             WHEN MATCHED THEN UPDATE SET
                target.timezone = source.timezone,
                target.workday_mask =
                    source.workday_mask,
                target.check_in_enabled =
                    source.check_in_enabled,
                target.check_in_time =
                    source.check_in_time,
                target.check_out_enabled =
                    source.check_out_enabled,
                target.check_out_time =
                    source.check_out_time,
                target.reminder_lead_minutes =
                    source.reminder_lead_minutes,
                target.browser_notifications_enabled =
                    source.browser_notifications_enabled,
                target.updated_at = SYSTIMESTAMP
             WHEN NOT MATCHED THEN INSERT (
                company_id,
                user_id,
                timezone,
                workday_mask,
                check_in_enabled,
                check_in_time,
                check_out_enabled,
                check_out_time,
                reminder_lead_minutes,
                browser_notifications_enabled
             ) VALUES (
                source.company_id,
                source.user_id,
                source.timezone,
                source.workday_mask,
                source.check_in_enabled,
                source.check_in_time,
                source.check_out_enabled,
                source.check_out_time,
                source.reminder_lead_minutes,
                source.browser_notifications_enabled
             )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'timezone' => $values['timezone'],
            'workday_mask' =>
                $values['workday_mask'],
            'check_in_enabled' =>
                !empty($values['check_in_enabled'])
                    ? 1
                    : 0,
            'check_in_time' =>
                $values['check_in_time'],
            'check_out_enabled' =>
                !empty($values['check_out_enabled'])
                    ? 1
                    : 0,
            'check_out_time' =>
                $values['check_out_time'],
            'reminder_lead_minutes' =>
                $values['reminder_lead_minutes'],
            'browser_notifications_enabled' =>
                !empty(
                    $values[
                        'browser_notifications_enabled'
                    ]
                )
                    ? 1
                    : 0,
        ]);
    }
}
