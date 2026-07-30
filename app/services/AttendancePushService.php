<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttendancePushSubscriptionRepository;
use App\Repositories\AuditLogWriter;
use App\Repositories\RepositoryFactory;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class AttendancePushService
{
    private const MAX_ATTEMPTS = 5;

    private AttendancePushSubscriptionRepository $subscriptions;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;
    private ?WebPushTransport $transport;

    /** @var array<string, mixed> */
    private array $configuration;

    /**
     * @param array<string, mixed>|null $configuration
     */
    public function __construct(
        ?AttendancePushSubscriptionRepository $subscriptions = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null,
        ?WebPushTransport $transport = null,
        ?array $configuration = null
    ) {
        $this->subscriptions = $subscriptions
            ?? RepositoryFactory::attendancePushSubscriptions();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant ?? new TenantContext();
        $configured = \config('web_push', []);
        $this->configuration = $configuration
            ?? (is_array($configured) ? $configured : []);
        $this->transport = $transport;
    }

    /**
     * @return array{
     *     configured: bool,
     *     publicKey: string,
     *     activeDeviceCount: int
     * }
     */
    public function status(int $actorUserId): array
    {
        $configured = $this->configured();

        return [
            'configured' => $configured,
            'publicKey' => $configured
                ? (string) (
                    $this->configuration['public_key']
                    ?? ''
                )
                : '',
            'activeDeviceCount' =>
                $this->subscriptions->countActive(
                    $this->tenant->companyId(),
                    $actorUserId
                ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     message: string,
     *     errors: array<string, string>
     * }
     */
    public function subscribe(
        int $actorUserId,
        array $input
    ): array {
        if (!$this->configured()) {
            return [
                'successful' => false,
                'message' =>
                    'Background notifications are not configured on this OfficeApp server.',
                'errors' => [
                    'configuration' =>
                        'The server administrator must enable Web Push first.',
                ],
            ];
        }

        $validated = $this->validateSubscription(
            $input
        );

        if ($validated['errors'] !== []) {
            return [
                'successful' => false,
                'message' =>
                    'The device subscription was not accepted.',
                'errors' => $validated['errors'],
            ];
        }

        $companyId = $this->tenant->companyId();
        $values = $validated['values'];
        $values['company_id'] = $companyId;
        $values['user_id'] = $actorUserId;

        try {
            \db()->beginTransaction();
            $this->subscriptions->upsert($values);
            $this->auditLogs->record(
                $actorUserId,
                'ATTENDANCE_PUSH_SUBSCRIBE',
                'attendance',
                'attendance_push_subscriptions',
                null,
                null,
                [
                    'delivery' => 'background_push',
                    'content_encoding' =>
                        $values['content_encoding'],
                ],
                $companyId
            );
            \db()->commit();
        } catch (Throwable $exception) {
            if (\db()->inTransaction()) {
                \db()->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'message' =>
                'Background notifications are enabled on this device.',
            'errors' => [],
        ];
    }

    /**
     * @return array{
     *     successful: bool,
     *     message: string,
     *     errors: array<string, string>
     * }
     */
    public function unsubscribe(
        int $actorUserId,
        string $endpoint
    ): array {
        $endpoint = trim($endpoint);

        if ($endpoint === '' || strlen($endpoint) > 2048) {
            return [
                'successful' => false,
                'message' =>
                    'The device subscription could not be identified.',
                'errors' => [
                    'endpoint' =>
                        'A valid device subscription is required.',
                ],
            ];
        }

        $companyId = $this->tenant->companyId();
        $disabled = $this->subscriptions->deactivate(
            $companyId,
            $actorUserId,
            hash('sha256', $endpoint)
        );

        if ($disabled) {
            $this->auditLogs->record(
                $actorUserId,
                'ATTENDANCE_PUSH_UNSUBSCRIBE',
                'attendance',
                'attendance_push_subscriptions',
                null,
                null,
                [
                    'delivery' => 'background_push',
                ],
                $companyId
            );
        }

        return [
            'successful' => true,
            'message' =>
                'Background notifications are disabled on this device.',
            'errors' => [],
        ];
    }

    /**
     * @return array{
     *     configured: bool,
     *     candidates: int,
     *     delivered: int,
     *     retrying: int,
     *     failed: int
     * }
     */
    public function dispatchPending(int $limit = 100): array
    {
        if (!$this->configured()) {
            return [
                'configured' => false,
                'candidates' => 0,
                'delivered' => 0,
                'retrying' => 0,
                'failed' => 0,
            ];
        }

        $transport = $this->transport();
        $rows = $this->subscriptions
            ->pendingDeliveries($limit);
        $delivered = 0;
        $retrying = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $notificationId = (int) (
                $row['notification_id'] ?? 0
            );
            $subscriptionId = (int) (
                $row['subscription_id'] ?? 0
            );

            if (
                $notificationId < 1
                || $subscriptionId < 1
            ) {
                $failed++;
                continue;
            }

            $payload = json_encode(
                [
                    'title' => (string) (
                        $row['title']
                        ?? 'Attendance reminder'
                    ),
                    'body' => (string) (
                        $row['body'] ?? ''
                    ),
                    'tag' => 'attendance:'
                        . $notificationId,
                    'url' => \appUrl('/attendance/me'),
                ],
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            );

            if (!is_string($payload)) {
                $failed++;
                continue;
            }

            $report = $transport->send(
                $row,
                $payload
            );
            $statusCode = is_int(
                $report['statusCode'] ?? null
            )
                ? $report['statusCode']
                : null;

            if (!empty($report['successful'])) {
                $this->subscriptions->markDelivered(
                    $notificationId,
                    $subscriptionId,
                    $statusCode ?? 201
                );
                $delivered++;
                continue;
            }

            $attempts = (int) (
                $row['attempts'] ?? 0
            ) + 1;
            $expired = !empty($report['expired'])
                || in_array(
                    $statusCode,
                    [404, 410],
                    true
                );
            $permanent = $expired
                || $attempts >= self::MAX_ATTEMPTS;
            $retryAt = $permanent
                ? null
                : $this->retryAt($attempts);
            $reason = mb_substr(
                trim((string) (
                    $report['reason']
                    ?? 'Web Push delivery failed.'
                )),
                0,
                500
            );

            $this->subscriptions->markFailed(
                $notificationId,
                $subscriptionId,
                $attempts,
                $statusCode,
                $reason === ''
                    ? 'Web Push delivery failed.'
                    : $reason,
                $retryAt,
                $permanent
            );

            if ($expired) {
                $this->subscriptions
                    ->disableSubscription(
                        $subscriptionId
                    );
            }

            if ($permanent) {
                $failed++;
            } else {
                $retrying++;
            }
        }

        return [
            'configured' => true,
            'candidates' => count($rows),
            'delivered' => $delivered,
            'retrying' => $retrying,
            'failed' => $failed,
        ];
    }

    public function configured(): bool
    {
        $subject = trim((string) (
            $this->configuration['subject'] ?? ''
        ));
        $subjectValid = false;

        if (str_starts_with($subject, 'mailto:')) {
            $subjectValid = filter_var(
                substr($subject, 7),
                FILTER_VALIDATE_EMAIL
            ) !== false;
        } elseif (str_starts_with(
            $subject,
            'https://'
        )) {
            $subjectValid = filter_var(
                $subject,
                FILTER_VALIDATE_URL
            ) !== false;
        }

        return !empty(
            $this->configuration['enabled']
        )
            && $subjectValid
            && $this->hasKeysAndDependency();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     values: array<string, string>,
     *     errors: array<string, string>
     * }
     */
    private function validateSubscription(
        array $input
    ): array {
        $endpoint = trim((string) (
            $input['endpoint'] ?? ''
        ));
        $p256dh = trim((string) (
            $input['p256dh'] ?? ''
        ));
        $authSecret = trim((string) (
            $input['auth'] ?? ''
        ));
        $encoding = strtolower(trim((string) (
            $input['content_encoding']
            ?? 'aes128gcm'
        )));
        $errors = [];

        if (!$this->allowedEndpoint($endpoint)) {
            $errors['endpoint'] =
                'This browser push service is not allowed by the server policy.';
        }

        if (!$this->validKey($p256dh, 40)) {
            $errors['p256dh'] =
                'The browser encryption key is invalid.';
        }

        if (!$this->validKey($authSecret, 16)) {
            $errors['auth'] =
                'The browser authentication secret is invalid.';
        }

        if (!in_array(
            $encoding,
            ['aes128gcm', 'aesgcm'],
            true
        )) {
            $errors['content_encoding'] =
                'The browser content encoding is unsupported.';
        }

        return [
            'values' => [
                'endpoint' => $endpoint,
                'endpoint_hash' =>
                    hash('sha256', $endpoint),
                'p256dh' => $p256dh,
                'auth_secret' => $authSecret,
                'content_encoding' => $encoding,
            ],
            'errors' => $errors,
        ];
    }

    private function hasKeysAndDependency(): bool
    {
        return trim((string) (
            $this->configuration['public_key'] ?? ''
        )) !== ''
            && trim((string) (
                $this->configuration['private_key'] ?? ''
            )) !== ''
            && class_exists(
                \Minishlink\WebPush\WebPush::class
            );
    }

    private function allowedEndpoint(string $endpoint): bool
    {
        if (
            $endpoint === ''
            || strlen($endpoint) > 2048
            || filter_var(
                $endpoint,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            return false;
        }

        $parts = parse_url($endpoint);
        $scheme = strtolower((string) (
            $parts['scheme'] ?? ''
        ));
        $host = strtolower((string) (
            $parts['host'] ?? ''
        ));

        if (
            $scheme !== 'https'
            || $host === ''
            || filter_var(
                $host,
                FILTER_VALIDATE_IP
            ) !== false
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        $allowedHosts = is_array(
            $this->configuration['allowed_hosts']
            ?? null
        )
            ? $this->configuration['allowed_hosts']
            : [];

        foreach ($allowedHosts as $allowedHost) {
            if (!is_string($allowedHost)) {
                continue;
            }

            $allowedHost = strtolower(
                trim($allowedHost)
            );

            if ($allowedHost === $host) {
                return true;
            }

            if (
                str_starts_with(
                    $allowedHost,
                    '*.'
                )
                && str_ends_with(
                    $host,
                    substr($allowedHost, 1)
                )
                && $host !== substr(
                    $allowedHost,
                    2
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function validKey(
        string $key,
        int $minimumLength
    ): bool {
        return strlen($key) >= $minimumLength
            && strlen($key) <= 255
            && preg_match(
                '/^[A-Za-z0-9_-]+={0,2}$/',
                $key
            ) === 1;
    }

    private function transport(): WebPushTransport
    {
        if ($this->transport !== null) {
            return $this->transport;
        }

        if (!$this->configured()) {
            throw new RuntimeException(
                'Web Push is not configured.'
            );
        }

        $this->transport =
            new MinishlinkWebPushTransport(
                $this->configuration
            );

        return $this->transport;
    }

    private function retryAt(int $attempts): string
    {
        $seconds = min(
            3600,
            60 * (2 ** max(0, $attempts - 1))
        );

        return (new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        ))
            ->modify('+' . $seconds . ' seconds')
            ->format('Y-m-d H:i:s');
    }
}
