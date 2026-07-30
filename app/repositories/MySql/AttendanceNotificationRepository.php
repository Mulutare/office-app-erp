<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\AttendanceNotificationRepository
    as AttendanceNotificationRepositoryContract;

final class AttendanceNotificationRepository extends MySqlRepository
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
              AND memberships.active = TRUE
             INNER JOIN companies
               ON companies.company_id =
                    reminders.company_id
              AND companies.active = TRUE
              AND companies.deleted_at IS NULL
             INNER JOIN users
               ON users.user_id = reminders.user_id
              AND users.active = TRUE
              AND users.deleted_at IS NULL
             INNER JOIN hr_employees employees
               ON employees.company_id =
                    reminders.company_id
              AND employees.user_id =
                    reminders.user_id
              AND employees.employment_status =
                    \'active\'
              AND employees.deleted_at IS NULL
             WHERE reminders.check_in_enabled = TRUE
                OR reminders.check_out_enabled = TRUE
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
            'INSERT IGNORE INTO attendance_notifications
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
                    :company_id,
                    :user_id,
                    :notification_type,
                    :title,
                    :body,
                    :scheduled_for,
                    :local_date,
                    \'in_app\',
                    \'unread\',
                    :dedupe_key
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
                notifications.status = \'unread\' DESC,
                notifications.scheduled_for DESC
             LIMIT :limit'
        );
        $statement->bindValue(
            ':company_id',
            $companyId,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':user_id',
            $userId,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':limit',
            max(1, min(20, $limit)),
            \PDO::PARAM_INT
        );
        $statement->execute();
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
                 read_at = CURRENT_TIMESTAMP
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
