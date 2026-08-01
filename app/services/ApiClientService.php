<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class ApiClientService
{
    /** @param list<string> $scopes @param list<string> $ipAllowlist @return array<string,mixed> */
    public function create(
        int $companyId,
        int $serviceUserId,
        string $name,
        array $scopes,
        int $createdBy,
        array $ipAllowlist = [],
        int $rateLimit = 60,
        int $tokenTtl = 3600
    ): array {
        $scopes = $this->validateScopes($scopes);
        $this->assertServiceUser($companyId, $serviceUserId);
        $secret = 'oas_' . bin2hex(random_bytes(32));
        $identifier = bin2hex(random_bytes(16));
        \db()->beginTransaction();
        try {
            $statement = \db()->prepare(
                'INSERT INTO api_clients
                    (company_id, service_user_id, client_identifier, name,
                     secret_hash, secret_prefix, ip_allowlist_json,
                     token_ttl_seconds, rate_limit_per_minute, active,
                     secret_rotated_at, created_by, created_at, updated_at)
                 VALUES
                    (:company_id, :user_id, :identifier, :name, :secret_hash,
                     :prefix, :allowlist, :ttl, :rate_limit, TRUE,
                     NOW(), :created_by, NOW(), NOW())'
            );
            $statement->execute([
                'company_id' => $companyId,
                'user_id' => $serviceUserId,
                'identifier' => $identifier,
                'name' => trim($name),
                'secret_hash' => password_hash($secret, PASSWORD_DEFAULT),
                'prefix' => substr($secret, 0, 12),
                'allowlist' => json_encode(array_values(array_unique($ipAllowlist)), JSON_THROW_ON_ERROR),
                'ttl' => max(300, min(86400, $tokenTtl)),
                'rate_limit' => max(1, min(10000, $rateLimit)),
                'created_by' => $createdBy,
            ]);
            $clientId = (int) \db()->lastInsertId();
            $grant = \db()->prepare(
                'INSERT INTO api_client_scopes (api_client_id, scope_code, granted_by, granted_at)
                 VALUES (:client_id, :scope, :granted_by, NOW())'
            );
            foreach ($scopes as $scope) {
                $grant->execute(['client_id' => $clientId, 'scope' => $scope, 'granted_by' => $createdBy]);
            }
            \db()->commit();
            return ['client_id' => $identifier, 'client_secret' => $secret, 'scopes' => $scopes];
        } catch (\Throwable $exception) {
            if (\db()->inTransaction()) {
                \db()->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{client_secret:string} */
    public function rotate(string $identifier): array
    {
        $secret = 'oas_' . bin2hex(random_bytes(32));
        \db()->beginTransaction();
        try {
            $statement = \db()->prepare(
                'UPDATE api_clients SET secret_hash = :hash, secret_prefix = :prefix,
                    secret_rotated_at = NOW(), updated_at = NOW()
                 WHERE client_identifier = :identifier AND active = TRUE'
            );
            $statement->execute([
                'hash' => password_hash($secret, PASSWORD_DEFAULT),
                'prefix' => substr($secret, 0, 12),
                'identifier' => $identifier,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Active API client not found.');
            }
            \db()->prepare(
                'UPDATE api_access_tokens tokens INNER JOIN api_clients clients
                 ON clients.api_client_id = tokens.api_client_id
                 SET tokens.revoked_at = NOW()
                 WHERE clients.client_identifier = :identifier AND tokens.revoked_at IS NULL'
            )->execute(['identifier' => $identifier]);
            \db()->commit();
            return ['client_secret' => $secret];
        } catch (\Throwable $exception) {
            if (\db()->inTransaction()) {
                \db()->rollBack();
            }
            throw $exception;
        }
    }

    public function revoke(string $identifier): void
    {
        \db()->beginTransaction();
        try {
            $statement = \db()->prepare(
                'UPDATE api_clients SET active = FALSE, revoked_at = NOW(), updated_at = NOW()
                 WHERE client_identifier = :identifier AND revoked_at IS NULL'
            );
            $statement->execute(['identifier' => $identifier]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Active API client not found.');
            }
            \db()->prepare(
                'UPDATE api_access_tokens tokens INNER JOIN api_clients clients
                 ON clients.api_client_id = tokens.api_client_id
                 SET tokens.revoked_at = NOW()
                 WHERE clients.client_identifier = :identifier AND tokens.revoked_at IS NULL'
            )->execute(['identifier' => $identifier]);
            \db()->commit();
        } catch (\Throwable $exception) {
            if (\db()->inTransaction()) {
                \db()->rollBack();
            }
            throw $exception;
        }
    }

    public function revokeToken(string $rawToken): void
    {
        $statement = \db()->prepare(
            'UPDATE api_access_tokens SET revoked_at = NOW()
             WHERE token_hash = :hash AND revoked_at IS NULL'
        );
        $statement->execute(['hash' => hash('sha256', $rawToken)]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Active API token not found.');
        }
    }

    /** @param list<string> $scopes @return list<string> */
    private function validateScopes(array $scopes): array
    {
        $scopes = array_values(array_unique(array_map('trim', $scopes)));
        if ($scopes === [] || array_diff($scopes, array_keys(ApiSecurityService::SCOPE_PERMISSIONS)) !== []) {
            throw new \InvalidArgumentException('One or more API scopes are invalid or not externally allowed.');
        }
        return $scopes;
    }

    private function assertServiceUser(int $companyId, int $userId): void
    {
        $statement = \db()->prepare(
            'SELECT COUNT(*) FROM company_users memberships
             INNER JOIN users ON users.user_id = memberships.user_id
             WHERE memberships.company_id = :company_id AND memberships.user_id = :user_id
               AND memberships.active = TRUE AND users.active = TRUE AND users.deleted_at IS NULL'
        );
        $statement->execute(['company_id' => $companyId, 'user_id' => $userId]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \InvalidArgumentException('The service user is not active in the selected company.');
        }
    }
}
