<?php

declare(strict_types=1);

namespace App\Services;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

final class MinishlinkWebPushTransport
    implements WebPushTransport
{
    private WebPush $webPush;

    /** @param array<string, mixed> $configuration */
    public function __construct(array $configuration)
    {
        $this->webPush = new WebPush(
            [
                'VAPID' => [
                    'subject' => (string) (
                        $configuration['subject'] ?? ''
                    ),
                    'publicKey' => (string) (
                        $configuration['public_key'] ?? ''
                    ),
                    'privateKey' => (string) (
                        $configuration['private_key'] ?? ''
                    ),
                ],
            ],
            [
                'TTL' => 300,
                'urgency' => 'normal',
                'batchSize' => 50,
            ]
        );
        $this->webPush->setReuseVAPIDHeaders(true);
    }

    public function send(
        array $subscription,
        string $payload
    ): array {
        try {
            $report = $this->webPush
                ->sendOneNotification(
                    Subscription::create([
                        'endpoint' => (string) (
                            $subscription['endpoint']
                            ?? ''
                        ),
                        'keys' => [
                            'p256dh' => (string) (
                                $subscription['p256dh']
                                ?? ''
                            ),
                            'auth' => (string) (
                                $subscription[
                                    'auth_secret'
                                ] ?? ''
                            ),
                        ],
                        'contentEncoding' => (string) (
                            $subscription[
                                'content_encoding'
                            ] ?? 'aes128gcm'
                        ),
                    ]),
                    $payload
                );
            $response = $report->getResponse();
            $statusCode = $response === null
                ? null
                : $response->getStatusCode();
            $expired = method_exists(
                $report,
                'isSubscriptionExpired'
            )
                ? $report->isSubscriptionExpired()
                : in_array(
                    $statusCode,
                    [404, 410],
                    true
                );

            return [
                'successful' => $report->isSuccess(),
                'statusCode' => $statusCode,
                'expired' => $expired,
                'reason' => $this->safeReason(
                    $report->getReason()
                ),
            ];
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'statusCode' => null,
                'expired' => false,
                'reason' => $this->safeReason(
                    $exception->getMessage()
                ),
            ];
        }
    }

    private function safeReason(string $reason): string
    {
        $reason = preg_replace(
            '/https?:\/\/\S+/i',
            '[push service]',
            $reason
        );
        $reason = is_string($reason)
            ? trim($reason)
            : 'Web Push delivery failed.';

        return mb_substr(
            $reason === ''
                ? 'Web Push delivery failed.'
                : $reason,
            0,
            500
        );
    }
}
