<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\IntegrationEventRepository as Contract;
use PDO;

final class IntegrationEventRepository extends MySqlRepository implements Contract
{
    public function claimPending(int $limit, string $workerId): array
    {
        $limit = max(1, min($limit, 200));
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $statement = $connection->query(
                "SELECT candidate.event_id, candidate.company_id, candidate.event_type,
                        candidate.aggregate_type, candidate.aggregate_id,
                        candidate.payload_json, candidate.attempts
                 FROM integration_outbox candidate
                 WHERE (
                        candidate.status IN ('pending', 'failed')
                        OR (candidate.status = 'processing'
                            AND candidate.claimed_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
                       )
                   AND candidate.dead_lettered_at IS NULL
                   AND candidate.available_at <= NOW()
                   AND candidate.attempts < 10
                   AND NOT EXISTS (
                        SELECT 1 FROM integration_outbox predecessor
                        WHERE predecessor.company_id = candidate.company_id
                          AND predecessor.aggregate_type = candidate.aggregate_type
                          AND predecessor.aggregate_id = candidate.aggregate_id
                          AND predecessor.outbox_sequence < candidate.outbox_sequence
                          AND predecessor.status <> 'processed'
                   )
                 ORDER BY candidate.outbox_sequence
                 LIMIT {$limit}
                 FOR UPDATE SKIP LOCKED"
            );
            $events = $statement->fetchAll(PDO::FETCH_ASSOC);
            $claim = $connection->prepare(
                "UPDATE integration_outbox SET status = 'processing',
                    claimed_by = :worker_id, claimed_at = NOW()
                 WHERE event_id = :event_id"
            );
            foreach ($events as $event) {
                $claim->execute(['worker_id' => $workerId, 'event_id' => $event['event_id']]);
            }
            $connection->commit();
            return $events;
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    public function markProcessed(string $eventId, string $workerId): void
    {
        $statement = $this->connection()->prepare(
            "UPDATE integration_outbox
             SET status = 'processed', processed_at = NOW(),
                 attempts = attempts + 1, last_error = NULL,
                 claimed_by = NULL, claimed_at = NULL
             WHERE event_id = :event_id AND status = 'processing'
               AND claimed_by = :worker_id"
        );
        $statement->execute(['event_id' => $eventId, 'worker_id' => $workerId]);
    }

    public function markFailed(
        string $eventId,
        string $error,
        bool $retry,
        string $workerId
    ): void {
        $statement = $this->connection()->prepare(
            "UPDATE integration_outbox
             SET status = 'failed', attempts = attempts + 1,
                 available_at = CASE WHEN :retry = 1
                    THEN DATE_ADD(NOW(), INTERVAL LEAST(60, 5 * POW(2, attempts)) MINUTE)
                    ELSE available_at END,
                 dead_lettered_at = CASE WHEN :dead_letter = 1 THEN NOW() ELSE NULL END,
                 last_error = :last_error, claimed_by = NULL, claimed_at = NULL
             WHERE event_id = :event_id AND status = 'processing'
               AND claimed_by = :worker_id"
        );
        $statement->execute([
            'retry' => $retry ? 1 : 0,
            'dead_letter' => $retry ? 0 : 1,
            'last_error' => mb_substr($error, 0, 500),
            'event_id' => $eventId,
            'worker_id' => $workerId,
        ]);
    }
}
