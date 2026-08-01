<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\IntegrationEventRepository as Contract;
use PDO;

final class IntegrationEventRepository extends MySqlRepository implements Contract
{
    public function pending(int $limit): array
    {
        $limit = max(1, min($limit, 200));
        $statement = $this->connection()->query(
            "SELECT event_id, company_id, event_type, aggregate_type,
                    aggregate_id, payload_json, attempts
             FROM integration_outbox
             WHERE status IN ('pending', 'failed')
               AND available_at <= NOW()
               AND attempts < 10
             ORDER BY outbox_sequence
             LIMIT {$limit}"
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markProcessed(string $eventId): void
    {
        $statement = $this->connection()->prepare(
            "UPDATE integration_outbox
             SET status = 'processed', processed_at = NOW(),
                 attempts = attempts + 1, last_error = NULL
             WHERE event_id = :event_id"
        );
        $statement->execute(['event_id' => $eventId]);
    }

    public function markFailed(
        string $eventId,
        string $error,
        bool $retry
    ): void {
        $statement = $this->connection()->prepare(
            "UPDATE integration_outbox
             SET status = 'failed', attempts = attempts + 1,
                 available_at = CASE WHEN :retry = 1
                    THEN DATE_ADD(NOW(), INTERVAL 5 MINUTE)
                    ELSE available_at END,
                 last_error = :last_error
             WHERE event_id = :event_id"
        );
        $statement->execute([
            'retry' => $retry ? 1 : 0,
            'last_error' => mb_substr($error, 0, 500),
            'event_id' => $eventId,
        ]);
    }
}
