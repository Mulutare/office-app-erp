<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

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
             LIMIT 1'
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
            'INSERT INTO attendance_user_reminders
                (
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
                )
             VALUES
                (
                    :company_id,
                    :user_id,
                    :timezone,
                    :workday_mask,
                    :check_in_enabled,
                    :check_in_time,
                    :check_out_enabled,
                    :check_out_time,
                    :reminder_lead_minutes,
                    :browser_notifications_enabled
                )
             ON DUPLICATE KEY UPDATE
                timezone = VALUES(timezone),
                workday_mask = VALUES(workday_mask),
                check_in_enabled =
                    VALUES(check_in_enabled),
                check_in_time =
                    VALUES(check_in_time),
                check_out_enabled =
                    VALUES(check_out_enabled),
                check_out_time =
                    VALUES(check_out_time),
                reminder_lead_minutes =
                    VALUES(reminder_lead_minutes),
                browser_notifications_enabled =
                    VALUES(
                        browser_notifications_enabled
                    ),
                updated_at = CURRENT_TIMESTAMP'
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
