<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\AttendancePushSubscriptionRepository
    as AttendancePushSubscriptionRepositoryContract;

final class AttendancePushSubscriptionRepository
    extends MySqlRepository
    implements AttendancePushSubscriptionRepositoryContract
{
    public function countActive(
        int $companyId,
        int $userId
    ): int {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM attendance_push_subscriptions
             WHERE company_id = :company_id
               AND user_id = :user_id
               AND active = TRUE'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function upsert(array $values): void
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO attendance_push_subscriptions
                (
                    company_id,
                    user_id,
                    endpoint,
                    endpoint_hash,
                    p256dh,
                    auth_secret,
                    content_encoding,
                    active,
                    failure_count,
                    disabled_at
                )
             VALUES
                (
                    :company_id,
                    :user_id,
                    :endpoint,
                    :endpoint_hash,
                    :p256dh,
                    :auth_secret,
                    :content_encoding,
                    TRUE,
                    0,
                    NULL
                )
             ON DUPLICATE KEY UPDATE
                company_id = VALUES(company_id),
                user_id = VALUES(user_id),
                endpoint = VALUES(endpoint),
                p256dh = VALUES(p256dh),
                auth_secret = VALUES(auth_secret),
                content_encoding =
                    VALUES(content_encoding),
                active = TRUE,
                failure_count = 0,
                disabled_at = NULL'
        );
        $statement->execute($values);
    }

    public function deactivate(
        int $companyId,
        int $userId,
        string $endpointHash
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE attendance_push_subscriptions
             SET active = FALSE,
                 disabled_at = CURRENT_TIMESTAMP
             WHERE company_id = :company_id
               AND user_id = :user_id
               AND endpoint_hash = :endpoint_hash
               AND active = TRUE'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'endpoint_hash' => $endpointHash,
        ]);

        return $statement->rowCount() === 1;
    }

    public function pendingDeliveries(
        int $limit = 100
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                notifications.notification_id,
                notifications.company_id,
                notifications.user_id,
                notifications.title,
                notifications.body,
                notifications.notification_type,
                subscriptions.subscription_id,
                subscriptions.endpoint,
                subscriptions.p256dh,
                subscriptions.auth_secret,
                subscriptions.content_encoding,
                COALESCE(deliveries.attempts, 0)
                    AS attempts
             FROM attendance_notifications notifications
             INNER JOIN attendance_push_subscriptions
                subscriptions
               ON subscriptions.company_id =
                    notifications.company_id
              AND subscriptions.user_id =
                    notifications.user_id
              AND subscriptions.active = TRUE
             LEFT JOIN attendance_push_deliveries
                deliveries
               ON deliveries.notification_id =
                    notifications.notification_id
              AND deliveries.subscription_id =
                    subscriptions.subscription_id
             WHERE notifications.status = \'unread\'
               AND notifications.created_at >=
                    CURRENT_TIMESTAMP - INTERVAL 2 DAY
               AND (
                    deliveries.notification_id IS NULL
                    OR (
                        deliveries.delivery_status =
                            \'retry\'
                        AND (
                            deliveries.next_attempt_at
                                IS NULL
                            OR deliveries.next_attempt_at
                                <= CURRENT_TIMESTAMP
                        )
                    )
               )
             ORDER BY
                notifications.created_at,
                subscriptions.subscription_id
             LIMIT :limit'
        );
        $statement->bindValue(
            ':limit',
            max(1, min(500, $limit)),
            \PDO::PARAM_INT
        );
        $statement->execute();
        $rows = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($rows) ? $rows : [];
    }

    public function markDelivered(
        int $notificationId,
        int $subscriptionId,
        int $statusCode
    ): void {
        $this->writeDelivery(
            $notificationId,
            $subscriptionId,
            'delivered',
            1,
            $statusCode,
            null,
            null
        );
        $statement = $this->connection()->prepare(
            'UPDATE attendance_push_subscriptions
             SET failure_count = 0,
                 last_success_at = CURRENT_TIMESTAMP
             WHERE subscription_id = :subscription_id'
        );
        $statement->execute([
            'subscription_id' => $subscriptionId,
        ]);
    }

    public function markFailed(
        int $notificationId,
        int $subscriptionId,
        int $attempts,
        ?int $statusCode,
        string $reason,
        ?string $nextAttemptAt,
        bool $permanent
    ): void {
        $this->writeDelivery(
            $notificationId,
            $subscriptionId,
            $permanent ? 'failed' : 'retry',
            $attempts,
            $statusCode,
            $reason,
            $nextAttemptAt
        );
        $statement = $this->connection()->prepare(
            'UPDATE attendance_push_subscriptions
             SET failure_count = failure_count + 1,
                 last_failure_at = CURRENT_TIMESTAMP
             WHERE subscription_id = :subscription_id'
        );
        $statement->execute([
            'subscription_id' => $subscriptionId,
        ]);
    }

    public function disableSubscription(
        int $subscriptionId
    ): void {
        $statement = $this->connection()->prepare(
            'UPDATE attendance_push_subscriptions
             SET active = FALSE,
                 disabled_at = CURRENT_TIMESTAMP
             WHERE subscription_id = :subscription_id'
        );
        $statement->execute([
            'subscription_id' => $subscriptionId,
        ]);
    }

    private function writeDelivery(
        int $notificationId,
        int $subscriptionId,
        string $status,
        int $attempts,
        ?int $statusCode,
        ?string $reason,
        ?string $nextAttemptAt
    ): void {
        $statement = $this->connection()->prepare(
            'INSERT INTO attendance_push_deliveries
                (
                    notification_id,
                    subscription_id,
                    delivery_status,
                    attempts,
                    next_attempt_at,
                    last_status_code,
                    failure_reason,
                    delivered_at
                )
             VALUES
                (
                    :notification_id,
                    :subscription_id,
                    :delivery_status,
                    :attempts,
                    :next_attempt_at,
                    :last_status_code,
                    :failure_reason,
                    CASE
                        WHEN :delivered = 1
                        THEN CURRENT_TIMESTAMP
                        ELSE NULL
                    END
                )
             ON DUPLICATE KEY UPDATE
                delivery_status =
                    VALUES(delivery_status),
                attempts = VALUES(attempts),
                next_attempt_at =
                    VALUES(next_attempt_at),
                last_status_code =
                    VALUES(last_status_code),
                failure_reason =
                    VALUES(failure_reason),
                delivered_at =
                    VALUES(delivered_at)'
        );
        $statement->execute([
            'notification_id' => $notificationId,
            'subscription_id' => $subscriptionId,
            'delivery_status' => $status,
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt,
            'last_status_code' => $statusCode,
            'failure_reason' => $reason,
            'delivered' =>
                $status === 'delivered' ? 1 : 0,
        ]);
    }
}
