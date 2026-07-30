<?php

declare(strict_types=1);

namespace App\Services;

interface WebPushTransport
{
    /**
     * @param array<string, mixed> $subscription
     *
     * @return array{
     *     successful: bool,
     *     statusCode: int|null,
     *     expired: bool,
     *     reason: string
     * }
     */
    public function send(
        array $subscription,
        string $payload
    ): array;
}
