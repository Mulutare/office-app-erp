<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use App\Services\TenantContext;
use RuntimeException;

/** Server-side binding of an upload, the reviewed mapping, and the active workspace. */
final class ImportConfirmation
{
    public static function context(): array
    {
        return ['company_id'=>(new TenantContext())->companyId(), 'actor_id'=>(int)($_SESSION['auth']['user_id'] ?? 0)];
    }

    public static function assertContext(array $stored): void
    {
        foreach (self::context() as $key => $value) {
            if ($value < 1 || ($stored[$key] ?? null) !== $value) throw new RuntimeException('The upload belongs to another user or company. Upload it again in this workspace.');
        }
    }

    public static function fingerprint(string $path, array $mapping, string $mode='create'): string
    {
        ksort($mapping);
        $contents = hash_file('sha256', $path);
        if ($contents === false) throw new RuntimeException('The upload is unavailable.');
        return hash('sha256', $contents . json_encode([$mapping,$mode], JSON_THROW_ON_ERROR));
    }

    public static function assertConfirmed(array $stored, array $mapping, string $mode='create'): void
    {
        self::assertContext($stored);
        if (!isset($stored['validated']) || !hash_equals($stored['validated'], self::fingerprint($stored['path'], $mapping,$mode))) {
            throw new RuntimeException('Test Import with this column mapping and import mode before confirming.');
        }
    }
}
