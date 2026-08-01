<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\IntegrationEventRepository;
use App\Repositories\RepositoryFactory;
use RuntimeException;
use Throwable;

final class IntegrationDispatcherService
{
    /** @var list<IntegrationEventHandler> */
    private array $handlers;

    /** @param list<IntegrationEventHandler>|null $handlers */
    public function __construct(
        private ?IntegrationEventRepository $events = null,
        ?array $handlers = null
    ) {
        $this->events ??= RepositoryFactory::integrationEvents();
        $this->handlers = $handlers ?? [
            new FinanceSalesIntegrationHandler(),
            new InventorySalesIntegrationHandler(),
            new WebhookOutboxMarkerHandler(),
        ];
    }

    /** @return array{processed:int,failed:int} */
    public function dispatch(int $limit = 100): array
    {
        $processed = 0;
        $failed = 0;
        $workerId = gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
        foreach ($this->events->claimPending($limit, $workerId) as $event) {
            try {
                $payload = json_decode(
                    (string) $event['payload_json'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($payload)) {
                    throw new RuntimeException('Integration payload is invalid.');
                }
                $event['payload'] = $payload;
                $handled = false;
                foreach ($this->handlers as $handler) {
                    if ($handler->supports((string) $event['event_type'])) {
                        $handler->handle($event);
                        $handled = true;
                    }
                }
                if (!$handled) {
                    throw new RuntimeException('No integration handler is registered.');
                }
                $this->events->markProcessed((string) $event['event_id'], $workerId);
                $processed++;
            } catch (Throwable $exception) {
                $this->events->markFailed(
                    (string) $event['event_id'],
                    $exception::class . ': ' . $exception->getMessage(),
                    (int) $event['attempts'] < 9,
                    $workerId
                );
                $failed++;
            }
        }
        return ['processed' => $processed, 'failed' => $failed];
    }
}
