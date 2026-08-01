<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\RepositoryFactory;
use App\Services\ApiException;
use App\Services\ApiSecurityService;
use App\Services\SalesService;
use PDO;

final class ApiV1SalesController
{
    private ApiSecurityService $security;
    private SalesService $sales;

    public function __construct()
    {
        $this->security = new ApiSecurityService();
        $this->sales = new SalesService();
    }

    public function token(): void
    {
        $this->respond(function (): array {
            $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            $clientId = (string) ($_POST['client_id'] ?? '');
            $secret = (string) ($_POST['client_secret'] ?? '');
            if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
                $clientId = (string) $_SERVER['PHP_AUTH_USER'];
                $secret = (string) $_SERVER['PHP_AUTH_PW'];
            }
            if (preg_match('/^Basic\s+(.+)$/i', $authorization, $match) === 1) {
                $decoded = base64_decode($match[1], true);
                if (is_string($decoded) && str_contains($decoded, ':')) {
                    [$clientId, $secret] = explode(':', $decoded, 2);
                }
            }
            if ((string) ($_POST['grant_type'] ?? 'client_credentials') !== 'client_credentials') {
                throw new ApiException(400, 'unsupported_grant_type', 'Only client_credentials is supported.');
            }
            return [200, $this->security->issueToken(trim($clientId), $secret), null];
        });
    }

    public function products(): void
    {
        $this->authenticated('sales.products.read', false, function (array $client): array {
            return [200, ['data' => RepositoryFactory::sales()->products((int) $client['company_id'])]];
        });
    }

    public function product(string $id): void
    {
        $this->authenticated('sales.products.read', false, function (array $client) use ($id): array {
            return [200, ['data' => $this->find(
                RepositoryFactory::sales()->products((int) $client['company_id']),
                'product_id', $id, 'product_not_found'
            )]];
        });
    }

    public function customers(): void
    {
        $this->authenticated('sales.customers.read', false, function (array $client): array {
            return [200, ['data' => RepositoryFactory::sales()->customers((int) $client['company_id'])]];
        });
    }

    public function customer(string $id): void
    {
        $this->authenticated('sales.customers.read', false, function (array $client) use ($id): array {
            return [200, ['data' => $this->find(
                RepositoryFactory::sales()->customers((int) $client['company_id']),
                'customer_id', $id, 'customer_not_found'
            )]];
        });
    }

    public function createCustomer(): void
    {
        $this->authenticated('sales.customers.write', true, function (array $client, array $body): array {
            $result = $this->sales->createCustomer($body, (int) $client['service_user_id']);
            $this->assertDomainResult($result);
            return [201, ['data' => ['customer_id' => (int) $result['customer']]]];
        });
    }

    public function orders(): void
    {
        $this->authenticated('sales.orders.read', false, function (array $client): array {
            return [200, ['data' => RepositoryFactory::sales()->orders((int) $client['company_id'], 200)]];
        });
    }

    public function order(string $id): void
    {
        $this->authenticated('sales.orders.read', false, function (array $client) use ($id): array {
            return [200, ['data' => $this->find(
                RepositoryFactory::sales()->orders((int) $client['company_id'], 200),
                'order_id', $id, 'order_not_found'
            )]];
        });
    }

    public function createOrder(): void
    {
        $this->authenticated('sales.orders.write', true, function (array $client, array $body): array {
            $body['confirm'] = false;
            $result = $this->sales->createOrder($body, (int) $client['service_user_id']);
            $this->assertDomainResult($result);
            return [201, ['data' => [
                'order_id' => (int) $result['orderId'],
                'order_number' => (string) $result['orderNumber'],
                'status' => 'draft',
            ]]];
        });
    }

    public function submitOrder(string $id): void
    {
        $this->transition($id, 'submit', 'sales.orders.submit');
    }

    public function cancelOrder(string $id): void
    {
        $this->transition($id, 'cancel', 'sales.orders.cancel');
    }

    public function payment(string $id): void
    {
        $this->authenticated('sales.payments.write', true, function (array $client, array $body) use ($id): array {
            $result = $this->sales->recordPayment((int) $id, $body, (int) $client['service_user_id']);
            $this->assertDomainResult($result);
            return [201, ['data' => ['order_id' => (int) $id, 'recorded' => true]]];
        });
    }

    public function receivables(): void
    {
        $this->authenticated('sales.receivables.read', false, function (array $client): array {
            $rows = array_values(array_filter(
                RepositoryFactory::sales()->orders((int) $client['company_id'], 200),
                static fn (array $row): bool => (float) ($row['balance_due'] ?? 0) > 0
            ));
            return [200, ['data' => $rows]];
        });
    }

    public function receivable(string $id): void
    {
        $this->authenticated('sales.receivables.read', false, function (array $client) use ($id): array {
            $row = $this->find(
                RepositoryFactory::sales()->orders((int) $client['company_id'], 200),
                'order_id', $id, 'receivable_not_found'
            );
            if ((float) ($row['balance_due'] ?? 0) <= 0) {
                throw new ApiException(404, 'receivable_not_found', 'Receivable was not found.');
            }
            return [200, ['data' => $row]];
        });
    }

    public function reportSummary(): void
    {
        $this->authenticated('sales.reports.read', false, function (array $client): array {
            return [200, ['data' => RepositoryFactory::sales()->dashboard((int) $client['company_id'])]];
        });
    }

    private function transition(string $id, string $action, string $scope): void
    {
        $this->authenticated($scope, true, function (array $client, array $body, string $key) use ($id, $action): array {
            $result = $this->sales->transitionOrder(
                (int) $id, $action, isset($body['reason']) ? (string) $body['reason'] : null,
                (int) $client['service_user_id'], $key
            );
            $this->assertDomainResult($result);
            return [200, ['data' => ['order_id' => (int) $id, 'action' => $action]]];
        });
    }

    /** @param callable(array<string,mixed>,array<string,mixed>,string):array{0:int,1:array<string,mixed>} $operation */
    private function authenticated(string $scope, bool $write, callable $operation): void
    {
        $this->respond(function () use ($scope, $write, $operation): array {
            $client = $this->security->authenticate($scope);
            $body = $this->jsonBody();
            if (!$write) {
                [$status, $payload] = $operation($client, $body, '');
                return [$status, $payload, $client];
            }
            $key = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
            if ($key === '' || strlen($key) > 100 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
                throw new ApiException(400, 'idempotency_key_required', 'A valid Idempotency-Key header is required.');
            }
            $hash = hash('sha256', ($_SERVER['REQUEST_METHOD'] ?? '') . '|' . ($_SERVER['REQUEST_URI'] ?? '') . '|' . json_encode($body, JSON_THROW_ON_ERROR));
            $prior = $this->reserveIdempotency((int) $client['api_client_id'], $key, $hash);
            if ($prior !== null) {
                return [(int) $prior['response_status'], json_decode((string) $prior['response_json'], true, 512, JSON_THROW_ON_ERROR), $client];
            }
            try {
                [$status, $payload] = $operation($client, $body, $key);
                \db()->prepare(
                    "UPDATE api_idempotency_keys SET status = 'completed', response_status = :status,
                        response_json = :response WHERE api_client_id = :client_id AND idempotency_key = :key"
                )->execute([
                    'status' => $status, 'response' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'client_id' => $client['api_client_id'], 'key' => $key,
                ]);
                return [$status, $payload, $client];
            } catch (ApiException $exception) {
                $errorPayload = ['error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ]];
                \db()->prepare(
                    "UPDATE api_idempotency_keys SET status='completed',response_status=:status,
                     response_json=:response WHERE api_client_id=:client_id AND idempotency_key=:key"
                )->execute([
                    'status' => $exception->status,
                    'response' => json_encode($errorPayload, JSON_THROW_ON_ERROR),
                    'client_id' => $client['api_client_id'],
                    'key' => $key,
                ]);
                throw $exception;
            } catch (\Throwable $exception) {
                \db()->prepare('DELETE FROM api_idempotency_keys WHERE api_client_id = :client_id AND idempotency_key = :key AND status = \'processing\'')
                    ->execute(['client_id' => $client['api_client_id'], 'key' => $key]);
                throw $exception;
            }
        });
    }

    /** @return array<string,mixed>|null */
    private function reserveIdempotency(int $clientId, string $key, string $hash): ?array
    {
        try {
            \db()->prepare(
                "INSERT INTO api_idempotency_keys
                    (api_client_id,idempotency_key,request_hash,status,created_at,expires_at)
                 VALUES (:client_id,:key,:hash,'processing',NOW(),DATE_ADD(NOW(),INTERVAL 24 HOUR))"
            )->execute(['client_id' => $clientId, 'key' => $key, 'hash' => $hash]);
            return null;
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }
        $statement = \db()->prepare(
            'SELECT request_hash,status,response_status,response_json FROM api_idempotency_keys
             WHERE api_client_id = :client_id AND idempotency_key = :key'
        );
        $statement->execute(['client_id' => $clientId, 'key' => $key]);
        $prior = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($prior) || !hash_equals((string) $prior['request_hash'], $hash)) {
            throw new ApiException(409, 'idempotency_conflict', 'The Idempotency-Key was already used with a different request.');
        }
        if ($prior['status'] !== 'completed') {
            throw new ApiException(409, 'request_in_progress', 'A request with this Idempotency-Key is still processing.');
        }
        return $prior;
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return [];
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > 1048576) {
            throw new ApiException(413, 'payload_too_large', 'The JSON payload is too large.');
        }
        if ($raw === '') {
            return [];
        }
        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'invalid_json', 'The request body must contain valid JSON.');
        }
        if (!is_array($body)) {
            throw new ApiException(400, 'invalid_json', 'The JSON body must be an object.');
        }
        return $body;
    }

    /** @param callable():array{0:int,1:array<string,mixed>,2?:array<string,mixed>|null} $operation */
    private function respond(callable $operation): void
    {
        $started = microtime(true);
        $correlation = $this->correlationId();
        $client = null;
        $errorCode = null;
        try {
            [$status, $payload, $client] = $operation();
        } catch (ApiException $exception) {
            $status = $exception->status;
            $errorCode = $exception->errorCode;
            $payload = ['error' => ['code' => $errorCode, 'message' => $exception->getMessage()]];
        } catch (\Throwable $exception) {
            $status = 500;
            $errorCode = 'internal_error';
            $payload = ['error' => ['code' => $errorCode, 'message' => 'An unexpected API error occurred.']];
            error_log('[api] correlation=' . $correlation . ' class=' . $exception::class);
        }
        $this->logRequest($correlation, is_array($client) ? $client : null, $status, $errorCode, $started);
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Correlation-ID: ' . $correlation);
        if ($status === 429) {
            header('Retry-After: 60');
        }
        echo json_encode($payload + ['correlation_id' => $correlation], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function find(array $rows, string $column, string $id, string $code): array
    {
        if (!ctype_digit($id)) {
            throw new ApiException(404, $code, 'Resource was not found.');
        }
        foreach ($rows as $row) {
            if ((int) ($row[$column] ?? 0) === (int) $id) {
                return $row;
            }
        }
        throw new ApiException(404, $code, 'Resource was not found.');
    }

    /** @param array<string,mixed> $result */
    private function assertDomainResult(array $result): void
    {
        if (empty($result['successful'])) {
            throw new ApiException(422, 'validation_failed', implode(' ', array_map('strval', $result['errors'] ?? ['Request rejected.'])));
        }
    }

    private function correlationId(): string
    {
        $incoming = trim((string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $incoming) === 1) {
            return substr(hash('sha256', $incoming), 0, 8) . '-' . substr(hash('sha256', $incoming), 8, 4) . '-4' . substr(hash('sha256', $incoming), 13, 3) . '-8' . substr(hash('sha256', $incoming), 17, 3) . '-' . substr(hash('sha256', $incoming), 20, 12);
        }
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    /** @param array<string,mixed>|null $client */
    private function logRequest(string $correlation, ?array $client, int $status, ?string $error, float $started): void
    {
        try {
            \db()->prepare(
                'INSERT INTO api_request_logs
                    (correlation_id,api_client_id,company_id,method,route,response_status,
                     remote_ip,duration_ms,error_code,requested_at)
                 VALUES (:correlation,:client_id,:company_id,:method,:route,:status,
                         :remote_ip,:duration,:error,NOW())'
            )->execute([
                'correlation' => $correlation,
                'client_id' => $client['api_client_id'] ?? null,
                'company_id' => $client['company_id'] ?? null,
                'method' => substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 10),
                'route' => substr((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), 0, 180),
                'status' => $status,
                'remote_ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                'duration' => max(0, (int) round((microtime(true) - $started) * 1000)),
                'error' => $error,
            ]);
        } catch (\Throwable) {
            error_log('[api] correlation=' . $correlation . ' audit_log_failed');
        }
    }
}
