<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class ApiSecurityService
{
    /** @var array<string,string> */
    public const SCOPE_PERMISSIONS = [
        'sales.products.read' => 'sales.view',
        'sales.customers.read' => 'sales.view',
        'sales.customers.write' => 'sales.catalogue.manage',
        'sales.orders.read' => 'sales.view',
        'sales.orders.write' => 'sales.orders.create',
        'sales.orders.submit' => 'sales.orders.submit',
        'sales.orders.cancel' => 'sales.orders.cancel',
        'sales.payments.write' => 'sales.payments.record',
        'sales.receivables.read' => 'sales.view',
        'sales.reports.read' => 'sales.reports.export',
    ];

    /** @return array<string,mixed> */
    public function issueToken(string $clientIdentifier, string $secret): array
    {
        $this->assertTokenEndpointRateLimit();
        $statement = \db()->prepare(
            "SELECT clients.*, companies.name company_name, companies.code company_code,
                    companies.default_currency, companies.timezone
             FROM api_clients clients
             INNER JOIN companies ON companies.company_id = clients.company_id
             WHERE clients.client_identifier = :identifier
               AND clients.active = TRUE AND clients.revoked_at IS NULL
               AND companies.active = TRUE AND companies.deleted_at IS NULL
               AND companies.subscription_status IN ('active','trial')
               AND (companies.subscription_expires_at IS NULL OR companies.subscription_expires_at > NOW())
             LIMIT 1"
        );
        $statement->execute(['identifier' => $clientIdentifier]);
        $client = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($client) || !password_verify($secret, (string) $client['secret_hash'])) {
            throw new ApiException(401, 'invalid_client', 'Client authentication failed.');
        }
        $this->assertIpAllowed($client);
        $this->assertSalesLicensed((int) $client['company_id']);
        $rawToken = 'oat_' . bin2hex(random_bytes(32));
        $ttl = (int) $client['token_ttl_seconds'];
        $insert = \db()->prepare(
            'INSERT INTO api_access_tokens
                (api_client_id, token_hash, token_prefix, issued_at, expires_at)
             VALUES (:client_id, :hash, :prefix, NOW(), DATE_ADD(NOW(), INTERVAL :ttl SECOND))'
        );
        $insert->bindValue('client_id', (int) $client['api_client_id'], PDO::PARAM_INT);
        $insert->bindValue('hash', hash('sha256', $rawToken));
        $insert->bindValue('prefix', substr($rawToken, 0, 12));
        $insert->bindValue('ttl', $ttl, PDO::PARAM_INT);
        $insert->execute();
        return [
            'access_token' => $rawToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'scope' => implode(' ', $this->scopes((int) $client['api_client_id'])),
        ];
    }

    /** @return array<string,mixed> */
    public function authenticate(string $requiredScope): array
    {
        $authorization = $this->authorizationHeader();
        if (preg_match('/^Bearer\s+([^\s]+)$/i', $authorization, $match) !== 1) {
            throw new ApiException(401, 'invalid_token', 'A bearer token is required.');
        }
        $statement = \db()->prepare(
            "SELECT tokens.api_token_id, tokens.api_client_id, tokens.expires_at,
                    clients.*, companies.name company_name, companies.code company_code,
                    companies.default_currency, companies.timezone
             FROM api_access_tokens tokens
             INNER JOIN api_clients clients ON clients.api_client_id = tokens.api_client_id
             INNER JOIN companies ON companies.company_id = clients.company_id
             WHERE tokens.token_hash = :hash AND tokens.revoked_at IS NULL
               AND tokens.expires_at > NOW() AND clients.active = TRUE
               AND clients.revoked_at IS NULL AND companies.active = TRUE
               AND companies.deleted_at IS NULL
               AND companies.subscription_status IN ('active','trial')
               AND (companies.subscription_expires_at IS NULL OR companies.subscription_expires_at > NOW())
             LIMIT 1"
        );
        $statement->execute(['hash' => hash('sha256', $match[1])]);
        $client = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($client)) {
            throw new ApiException(401, 'invalid_token', 'The bearer token is invalid, expired or revoked.');
        }
        $this->assertIpAllowed($client);
        $this->assertSalesLicensed((int) $client['company_id']);
        $scopes = $this->scopes((int) $client['api_client_id']);
        if (!in_array($requiredScope, $scopes, true)) {
            throw new ApiException(403, 'insufficient_scope', 'The token does not grant the required scope.');
        }
        $permission = self::SCOPE_PERMISSIONS[$requiredScope] ?? null;
        if ($permission === null) {
            throw new ApiException(403, 'scope_not_supported', 'The requested scope is not externally supported.');
        }
        $this->assertServiceUserPermission(
            (int) $client['company_id'],
            (int) $client['service_user_id'],
            $permission
        );
        $this->assertRateLimit($client);
        \db()->prepare('UPDATE api_access_tokens SET last_used_at = NOW() WHERE api_token_id = :id')
            ->execute(['id' => $client['api_token_id']]);
        $_SESSION['auth'] = [
            'user_id' => (int) $client['service_user_id'],
            'is_platform_admin' => false,
            'permissions' => [$permission],
            'company' => [
                'company_id' => (int) $client['company_id'],
                'name' => $client['company_name'],
                'code' => $client['company_code'],
                'default_currency' => $client['default_currency'],
                'timezone' => $client['timezone'],
            ],
        ];
        $client['scope'] = $requiredScope;
        return $client;
    }

    /** @return list<string> */
    private function scopes(int $clientId): array
    {
        $statement = \db()->prepare(
            'SELECT scope_code FROM api_client_scopes WHERE api_client_id = :client_id ORDER BY scope_code'
        );
        $statement->execute(['client_id' => $clientId]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string,mixed> $client */
    private function assertIpAllowed(array $client): void
    {
        $allowed = json_decode((string) ($client['ip_allowlist_json'] ?? '[]'), true);
        if (!is_array($allowed) || $allowed === []) {
            return;
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!in_array($remote, $allowed, true)) {
            throw new ApiException(403, 'ip_not_allowed', 'The client IP address is not allowed.');
        }
    }

    private function assertSalesLicensed(int $companyId): void
    {
        $statement = \db()->prepare(
            "SELECT COUNT(*) FROM company_modules company_module
             INNER JOIN erp_modules module ON module.module_id = company_module.module_id
             WHERE company_module.company_id = :company_id AND module.code = 'sales'
               AND module.available = TRUE AND company_module.enabled = TRUE
               AND company_module.license_status IN ('active','trial')
               AND (company_module.expires_at IS NULL OR company_module.expires_at > NOW())"
        );
        $statement->execute(['company_id' => $companyId]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new ApiException(403, 'module_unavailable', 'The Sales module is not actively licensed.');
        }
    }

    private function assertServiceUserPermission(int $companyId, int $userId, string $permission): void
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*) FROM company_user_roles assignments
             INNER JOIN roles ON roles.role_id = assignments.role_id AND roles.active = TRUE
             INNER JOIN company_role_permissions grants
                ON grants.company_id = assignments.company_id AND grants.role_id = assignments.role_id
             INNER JOIN permissions ON permissions.permission_id = grants.permission_id AND permissions.active = TRUE
             INNER JOIN company_users membership
                ON membership.company_id = assignments.company_id AND membership.user_id = assignments.user_id
               AND membership.active = TRUE
             INNER JOIN users ON users.user_id = assignments.user_id
               AND users.active = TRUE AND users.deleted_at IS NULL
             WHERE assignments.company_id = :company_id AND assignments.user_id = :user_id
               AND permissions.code = :permission'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'permission' => $permission,
        ]);
        if ((int) $statement->fetchColumn() < 1) {
            throw new ApiException(403, 'permission_denied', 'The service account is not authorized for this operation.');
        }
    }

    /** @param array<string,mixed> $client */
    private function assertRateLimit(array $client): void
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*) FROM api_request_logs
             WHERE api_client_id = :client_id AND requested_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
        );
        $statement->execute(['client_id' => $client['api_client_id']]);
        if ((int) $statement->fetchColumn() >= (int) $client['rate_limit_per_minute']) {
            throw new ApiException(429, 'rate_limit_exceeded', 'The API rate limit has been exceeded.');
        }
    }

    private function assertTokenEndpointRateLimit(): void
    {
        $statement = \db()->prepare(
            "SELECT COUNT(*) FROM api_request_logs
             WHERE route LIKE '%/api/v1/oauth/token' AND requested_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
               AND remote_ip = :remote_ip"
        );
        $statement->execute(['remote_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);
        if ((int) $statement->fetchColumn() >= 30) {
            throw new ApiException(429, 'rate_limit_exceeded', 'The token endpoint rate limit has been exceeded.');
        }
    }

    private function authorizationHeader(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (is_string($_SERVER[$key] ?? null) && trim((string) $_SERVER[$key]) !== '') {
                return trim((string) $_SERVER[$key]);
            }
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value)) {
                    return trim($value);
                }
            }
        }
        return '';
    }
}
