<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\AttendancePushSubscriptionRepository
    as AttendancePushSubscriptionRepositoryContract;

final class AttendancePushSubscriptionRepository
    extends OracleRepository
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
               AND active = 1'
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
            'MERGE INTO attendance_push_subscriptions target
             USING (
                SELECT
                    :company_id AS company_id,
                    :user_id AS user_id,
                    :endpoint AS endpoint,
                    :endpoint_hash AS endpoint_hash,
                    :p256dh AS p256dh,
                    :auth_secret AS auth_secret,
                    :content_encoding
                        AS content_encoding
                FROM dual
             ) source
             ON (
                target.endpoint_hash =
                    source.endpoint_hash
             )
             WHEN MATCHED THEN UPDATE SET
                target.company_id = source.company_id,
                target.user_id = source.user_id,
                target.endpoint = source.endpoint,
                target.p256dh = source.p256dh,
                target.auth_secret =
                    source.auth_secret,
                target.content_encoding =
                    source.content_encoding,
                target.active = 1,
                target.failure_count = 0,
                target.disabled_at = NULL,
                target.updated_at = SYSTIMESTAMP
             WHEN NOT MATCHED THEN INSERT
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
                    source.company_id,
                    source.user_id,
                    source.endpoint,
                    source.endpoint_hash,
                    source.p256dh,
                    source.auth_secret,
                    source.content_encoding,
                    1,
                    0,
                    NULL
                )'
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
             SET active = 0,
                 disabled_at = SYSTIMESTAMP,
                 updated_at = SYSTIMESTAMP
             WHERE company_id = :company_id
               AND user_id = :user_id
               AND endpoint_hash = :endpoint_hash
               AND active = 1'
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
        $limit = max(1, min(500, $limit));
        $statement = $this->connection()->query(
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
                NVL(deliveries.attempts, 0)
                    AS attempts
             FROM attendance_notifications notifications
             INNER JOIN attendance_push_subscriptions
                subscriptions
               ON subscriptions.company_id =
                    notifications.company_id
              AND subscriptions.user_id =
                    notifications.user_id
              AND subscriptions.active = 1
             LEFT JOIN attendance_push_deliveries
                deliveries
               ON deliveries.notification_id =
                    notifications.notification_id
              AND deliveries.subscription_id =
                    subscriptions.subscription_id
             WHERE notifications.status = \'unread\'
               AND notifications.created_at >=
                    SYSTIMESTAMP - INTERVAL \'2\' DAY
               AND (
                    deliveries.notification_id IS NULL
                    OR (
                        deliveries.delivery_status =
                            \'retry\'
                        AND (
                            deliveries.next_attempt_at
                                IS NULL
                            OR deliveries.next_attempt_at
                                <= SYSTIMESTAMP
                        )
                    )
               )
             ORDER BY
                notifications.created_at,
                subscriptions.subscription_id
             FETCH FIRST ' . $limit . ' ROWS ONLY'
        );
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
                 last_success_at = SYSTIMESTAMP,
                 updated_at = SYSTIMESTAMP
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
                 last_failure_at = SYSTIMESTAMP,
                 updated_at = SYSTIMESTAMP
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
             SET active = 0,
                 disabled_at = SYSTIMESTAMP,
                 updated_at = SYSTIMESTAMP
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
            'MERGE INTO attendance_push_deliveries target
             USING (
                SELECT
                    :notification_id
                        AS notification_id,
                    :subscription_id
                        AS subscription_id,
                    :delivery_status
                        AS delivery_status,
                    :attempts AS attempts,
                    CASE
                        WHEN :next_attempt_at IS NULL
                        THEN CAST(NULL AS TIMESTAMP)
                        ELSE TO_TIMESTAMP(
                            :next_attempt_at_value,
                            \'YYYY-MM-DD HH24:MI:SS\'
                        )
                    END AS next_attempt_at,
                    :last_status_code
                        AS last_status_code,
                    :failure_reason
                        AS failure_reason,
                    :delivered AS delivered
                FROM dual
             ) source
             ON (
                target.notification_id =
                    source.notification_id
                AND target.subscription_id =
                    source.subscription_id
             )
             WHEN MATCHED THEN UPDATE SET
                target.delivery_status =
                    source.delivery_status,
                target.attempts = source.attempts,
                target.next_attempt_at =
                    source.next_attempt_at,
                target.last_status_code =
                    source.last_status_code,
                target.failure_reason =
                    source.failure_reason,
                target.delivered_at = CASE
                    WHEN source.delivered = 1
                    THEN SYSTIMESTAMP
                    ELSE NULL
                END,
                target.updated_at = SYSTIMESTAMP
             WHEN NOT MATCHED THEN INSERT
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
                    source.notification_id,
                    source.subscription_id,
                    source.delivery_status,
                    source.attempts,
                    source.next_attempt_at,
                    source.last_status_code,
                    source.failure_reason,
                    CASE
                        WHEN source.delivered = 1
                        THEN SYSTIMESTAMP
                        ELSE NULL
                    END
                )'
        );
        $statement->execute([
            'notification_id' => $notificationId,
            'subscription_id' => $subscriptionId,
            'delivery_status' => $status,
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt,
            'next_attempt_at_value' => $nextAttemptAt,
            'last_status_code' => $statusCode,
            'failure_reason' => $reason,
            'delivered' =>
                $status === 'delivered' ? 1 : 0,
        ]);
    }
}
