<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\AttendanceNotificationRepository
    as AttendanceNotificationRepositoryContract;

final class AttendanceNotificationRepository extends OracleRepository
    implements AttendanceNotificationRepositoryContract
{
    public function reminderCandidates(): array
    {
        $statement = $this->connection()->query(
            'SELECT
                reminders.*,
                employees.employee_id
             FROM attendance_user_reminders reminders
             INNER JOIN company_users memberships
               ON memberships.company_id =
                    reminders.company_id
              AND memberships.user_id =
                    reminders.user_id
              AND memberships.active = 1
             INNER JOIN companies
               ON companies.company_id =
                    reminders.company_id
              AND companies.active = 1
              AND companies.deleted_at IS NULL
             INNER JOIN users
               ON users.user_id = reminders.user_id
              AND users.active = 1
              AND users.deleted_at IS NULL
             INNER JOIN hr_employees employees
               ON employees.company_id =
                    reminders.company_id
              AND employees.user_id =
                    reminders.user_id
              AND employees.employment_status =
                    \'active\'
              AND employees.deleted_at IS NULL
             WHERE reminders.check_in_enabled = 1
                OR reminders.check_out_enabled = 1
             ORDER BY
                reminders.company_id,
                reminders.user_id'
        );
        $rows = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($rows) ? $rows : [];
    }

    public function create(array $values): bool
    {
        $statement = $this->connection()->prepare(
            'MERGE INTO attendance_notifications target
             USING (
                SELECT
                    :company_id AS company_id,
                    :user_id AS user_id,
                    :notification_type
                        AS notification_type,
                    :title AS title,
                    :body AS body,
                    TO_TIMESTAMP(
                        :scheduled_for,
                        \'YYYY-MM-DD HH24:MI:SS\'
                    ) AS scheduled_for,
                    TO_DATE(
                        :local_date,
                        \'YYYY-MM-DD\'
                    ) AS local_date,
                    :dedupe_key AS dedupe_key
                FROM dual
             ) source
             ON (
                target.company_id = source.company_id
                AND target.user_id = source.user_id
                AND target.dedupe_key =
                    source.dedupe_key
             )
             WHEN NOT MATCHED THEN INSERT
                (
                    company_id,
                    user_id,
                    notification_type,
                    title,
                    body,
                    scheduled_for,
                    local_date,
                    channel,
                    status,
                    dedupe_key
                )
             VALUES
                (
                    source.company_id,
                    source.user_id,
                    source.notification_type,
                    source.title,
                    source.body,
                    source.scheduled_for,
                    source.local_date,
                    \'in_app\',
                    \'unread\',
                    source.dedupe_key
                )'
        );
        $statement->execute([
            'company_id' => $values['company_id'],
            'user_id' => $values['user_id'],
            'notification_type' =>
                $values['notification_type'],
            'title' => $values['title'],
            'body' => $values['body'],
            'scheduled_for' =>
                $values['scheduled_for'],
            'local_date' => $values['local_date'],
            'dedupe_key' => $values['dedupe_key'],
        ]);

        return $statement->rowCount() === 1;
    }

    public function inbox(
        int $companyId,
        int $userId,
        int $limit = 8
    ): array {
        $limit = max(1, min(20, $limit));
        $statement = $this->connection()->prepare(
            'SELECT
                notifications.*,
                reminders.timezone
             FROM attendance_notifications notifications
             LEFT JOIN attendance_user_reminders reminders
               ON reminders.company_id =
                    notifications.company_id
              AND reminders.user_id =
                    notifications.user_id
             WHERE notifications.company_id =
                    :company_id
               AND notifications.user_id = :user_id
             ORDER BY
                CASE
                    WHEN notifications.status = \'unread\'
                    THEN 1
                    ELSE 0
                END DESC,
                notifications.scheduled_for DESC
             FETCH FIRST ' . $limit . ' ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);
        $rows = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($rows) ? $rows : [];
    }

    public function markRead(
        int $companyId,
        int $userId,
        int $notificationId
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE attendance_notifications
             SET status = \'read\',
                 read_at = SYSTIMESTAMP
             WHERE company_id = :company_id
               AND user_id = :user_id
               AND notification_id =
                    :notification_id
               AND status = \'unread\''
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'notification_id' => $notificationId,
        ]);

        return $statement->rowCount() === 1;
    }
}
