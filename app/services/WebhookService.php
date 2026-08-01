<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class WebhookService
{
    public const EVENTS = [
        'sales.order.submitted', 'sales.order.approved',
        'sales.order.confirmed', 'sales.order.cancelled',
        'sales.payment.recorded', 'sales.credit_hold.created',
        'sales.credit_hold.released',
    ];

    public static function verifySignature(
        string $secret,
        string $payload,
        string $timestamp,
        string $signature,
        ?int $now = null
    ): bool {
        if (!ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > 300) {
            return false;
        }
        $provided = str_starts_with($signature, 'v1=') ? substr($signature, 3) : '';
        return strlen($provided) === 64
            && hash_equals(hash_hmac('sha256', $timestamp . '.' . $payload, $secret), $provided);
    }

    /** @param list<string> $events @return array{subscription_id:int,secret:string} */
    public function create(int $companyId, int $clientId, string $url, array $events, int $actorId): array
    {
        $this->validateUrl($url);
        $events = array_values(array_unique($events));
        if ($events === [] || array_diff($events, self::EVENTS) !== []) {
            throw new \InvalidArgumentException('One or more webhook events are invalid.');
        }
        $secret = 'whs_' . bin2hex(random_bytes(32));
        $statement = \db()->prepare(
            'INSERT INTO api_webhook_subscriptions
                (company_id,api_client_id,endpoint_url,events_json,secret_hash,
                 secret_ciphertext,secret_prefix,active,secret_rotated_at,
                 created_by,created_at,updated_at)
             SELECT :company_id,api_client_id,:url,:events,:hash,:ciphertext,:prefix,
                    TRUE,NOW(),:actor,NOW(),NOW()
             FROM api_clients WHERE api_client_id = :client_id
               AND company_id = :company_match AND active = TRUE'
        );
        $statement->execute([
            'company_id' => $companyId, 'url' => $url,
            'events' => json_encode($events, JSON_THROW_ON_ERROR),
            'hash' => hash('sha256', $secret), 'ciphertext' => $this->encrypt($secret),
            'prefix' => substr($secret, 0, 12), 'actor' => $actorId,
            'client_id' => $clientId, 'company_match' => $companyId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \InvalidArgumentException('Active company API client was not found.');
        }
        return ['subscription_id' => (int) \db()->lastInsertId(), 'secret' => $secret];
    }

    /** @return array{secret:string} */
    public function rotate(int $subscriptionId): array
    {
        $secret = 'whs_' . bin2hex(random_bytes(32));
        $statement = \db()->prepare(
            'UPDATE api_webhook_subscriptions
             SET secret_hash=:hash,secret_ciphertext=:ciphertext,secret_prefix=:prefix,
                 secret_rotated_at=NOW(),updated_at=NOW()
             WHERE webhook_subscription_id=:id AND active=TRUE'
        );
        $statement->execute([
            'hash' => hash('sha256', $secret), 'ciphertext' => $this->encrypt($secret),
            'prefix' => substr($secret, 0, 12), 'id' => $subscriptionId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Active webhook subscription not found.');
        }
        return ['secret' => $secret];
    }

    public function fanOut(int $limit = 500): int
    {
        $limit = max(1, min(2000, $limit));
        $statement = \db()->query(
            "SELECT outbox.event_id,outbox.company_id,outbox.event_type,outbox.payload_json,
                    subscriptions.webhook_subscription_id,subscriptions.events_json
             FROM integration_outbox outbox
             INNER JOIN api_webhook_subscriptions subscriptions
                ON subscriptions.company_id=outbox.company_id AND subscriptions.active=TRUE
             LEFT JOIN api_webhook_deliveries deliveries
                ON deliveries.webhook_subscription_id=subscriptions.webhook_subscription_id
               AND deliveries.event_id=outbox.event_id
             WHERE outbox.status='processed' AND deliveries.delivery_id IS NULL
               AND JSON_CONTAINS(subscriptions.events_json, JSON_QUOTE(outbox.event_type)) = 1
             ORDER BY outbox.outbox_sequence LIMIT {$limit}"
        );
        $insert = \db()->prepare(
            "INSERT IGNORE INTO api_webhook_deliveries
                (delivery_id,webhook_subscription_id,event_id,event_type,payload_json,
                 status,attempts,available_at,created_at)
             VALUES (:delivery_id,:subscription_id,:event_id,:event_type,:payload,
                     'pending',0,NOW(),NOW())"
        );
        $created = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $insert->execute([
                'delivery_id' => $this->uuid(), 'subscription_id' => $row['webhook_subscription_id'],
                'event_id' => $row['event_id'], 'event_type' => $row['event_type'],
                'payload' => $row['payload_json'],
            ]);
            $created += $insert->rowCount();
        }
        return $created;
    }

    /** @return array{delivered:int,failed:int} */
    public function dispatch(int $limit = 100): array
    {
        $this->fanOut();
        $limit = max(1, min(200, $limit));
        $workerId = gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
        \db()->beginTransaction();
        try {
            $rows = \db()->query(
            "SELECT deliveries.*,subscriptions.endpoint_url,subscriptions.secret_ciphertext
             FROM api_webhook_deliveries deliveries
             INNER JOIN api_webhook_subscriptions subscriptions
                ON subscriptions.webhook_subscription_id=deliveries.webhook_subscription_id
             WHERE (deliveries.status IN ('pending','failed')
                    OR (deliveries.status='processing' AND deliveries.claimed_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)))
               AND deliveries.available_at<=NOW() AND deliveries.attempts<10
               AND subscriptions.active=TRUE
             ORDER BY deliveries.created_at LIMIT {$limit} FOR UPDATE SKIP LOCKED"
            )->fetchAll(PDO::FETCH_ASSOC);
            $claim = \db()->prepare(
                "UPDATE api_webhook_deliveries SET status='processing',claimed_by=:worker,claimed_at=NOW()
                 WHERE delivery_id=:id"
            );
            foreach ($rows as $row) {
                $claim->execute(['worker' => $workerId, 'id' => $row['delivery_id']]);
            }
            \db()->commit();
        } catch (\Throwable $exception) {
            if (\db()->inTransaction()) { \db()->rollBack(); }
            throw $exception;
        }
        $delivered = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $timestamp = (string) time();
            $payload = json_encode([
                'id' => $row['event_id'], 'type' => $row['event_type'],
                'created_at' => $row['created_at'],
                'data' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->decrypt((string) $row['secret_ciphertext']));
            [$status, $error] = $this->post((string) $row['endpoint_url'], $payload, [
                'Content-Type: application/json',
                'X-OfficeApp-Delivery: ' . $row['delivery_id'],
                'X-OfficeApp-Timestamp: ' . $timestamp,
                'X-OfficeApp-Signature: v1=' . $signature,
            ]);
            if ($status >= 200 && $status < 300) {
                \db()->prepare(
                    "UPDATE api_webhook_deliveries SET status='delivered',attempts=attempts+1,
                     delivered_at=NOW(),response_status=:status,last_error=NULL,
                     claimed_by=NULL,claimed_at=NULL WHERE delivery_id=:id AND claimed_by=:worker"
                )->execute(['status' => $status, 'id' => $row['delivery_id'], 'worker' => $workerId]);
                $delivered++;
            } else {
                $dead = (int) $row['attempts'] >= 9;
                \db()->prepare(
                    "UPDATE api_webhook_deliveries SET status=:state,attempts=attempts+1,
                     available_at=DATE_ADD(NOW(),INTERVAL LEAST(60,POW(2,attempts+1)) MINUTE),
                     dead_lettered_at=CASE WHEN :dead=1 THEN NOW() ELSE NULL END,
                     response_status=:status,last_error=:error,claimed_by=NULL,claimed_at=NULL
                     WHERE delivery_id=:id AND claimed_by=:worker"
                )->execute([
                    'state' => $dead ? 'dead_letter' : 'failed', 'dead' => $dead ? 1 : 0,
                    'status' => $status ?: null, 'error' => mb_substr($error, 0, 500),
                    'id' => $row['delivery_id'], 'worker' => $workerId,
                ]);
                $failed++;
            }
        }
        return ['delivered' => $delivered, 'failed' => $failed];
    }

    public function replay(string $deliveryId): void
    {
        $statement = \db()->prepare(
            "UPDATE api_webhook_deliveries SET status='pending',attempts=0,available_at=NOW(),
             dead_lettered_at=NULL,last_error=NULL WHERE delivery_id=:id AND status='dead_letter'"
        );
        $statement->execute(['id' => $deliveryId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Dead-letter webhook delivery not found.');
        }
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)) {
            throw new \InvalidArgumentException('Webhook endpoint must be a valid HTTP(S) URL.');
        }
        if ((string) \config('environment', 'production') === 'production' && $parts['scheme'] !== 'https') {
            throw new \InvalidArgumentException('Production webhook endpoints must use HTTPS.');
        }
        if ((string) \config('environment', 'production') === 'production') {
            $this->resolvedPublicIp((string) $parts['host']);
        }
    }

    private function encryptionKey(): string
    {
        $key = getenv('API_WEBHOOK_ENCRYPTION_KEY');
        if (!is_string($key) || strlen($key) < 32) {
            throw new \RuntimeException('API_WEBHOOK_ENCRYPTION_KEY must contain at least 32 characters.');
        }
        return hash('sha256', $key, true);
    }

    private function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($ciphertext)) {
            throw new \RuntimeException('Webhook secret encryption failed.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt(string $encoded): string
    {
        $binary = base64_decode($encoded, true);
        if (!is_string($binary) || strlen($binary) < 29) {
            throw new \RuntimeException('Webhook secret ciphertext is invalid.');
        }
        $plaintext = openssl_decrypt(substr($binary, 28), 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, substr($binary, 0, 12), substr($binary, 12, 16));
        if (!is_string($plaintext)) {
            throw new \RuntimeException('Webhook secret decryption failed.');
        }
        return $plaintext;
    }

    /** @param list<string> $headers @return array{0:int,1:string} */
    private function post(string $url, string $payload, array $headers): array
    {
        try {
            $this->validateUrl($url);
        } catch (\Throwable $exception) {
            return [0, $exception->getMessage()];
        }
        $curl = curl_init($url);
        if ($curl === false) {
            return [0, 'Unable to initialize webhook transport.'];
        }
        $options = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP];
        if ((string) \config('environment', 'production') === 'production') {
            $parts = parse_url($url);
            $host = (string) ($parts['host'] ?? '');
            $port = (int) ($parts['port'] ?? 443);
            $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $this->resolvedPublicIp($host)];
        }
        curl_setopt_array($curl, $options);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        return [$status, $error !== '' ? $error : 'Webhook endpoint returned HTTP ' . $status . '.'];
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);
    }

    private function resolvedPublicIp(string $host): string
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            throw new \InvalidArgumentException('Webhook host cannot be resolved.');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException('Webhook host resolves to a private or reserved address.');
            }
        }
        return (string) $addresses[0];
    }
}
