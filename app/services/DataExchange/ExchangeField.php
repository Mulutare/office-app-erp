<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

final class ExchangeField
{
    /** @param list<string> $aliases */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly string $type = 'string',
        public readonly array $aliases = [],
        public readonly ?string $example = null,
        public readonly bool $importable = true,
        public readonly bool $exportable = true,
    ) {
    }

    /** @return list<string> */
    public function normalizedNames(): array
    {
        return array_values(array_unique(array_map(
            [self::class, 'normalize'],
            [$this->key, $this->label, ...$this->aliases]
        )));
    }

    public static function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');
    }
}
