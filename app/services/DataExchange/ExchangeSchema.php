<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use InvalidArgumentException;

final class ExchangeSchema
{
    /** @param list<ExchangeField> $fields */
    public function __construct(
        public readonly string $entity,
        public readonly string $label,
        public readonly string $module,
        public readonly array $fields,
        public readonly bool $canImport = true,
        public readonly bool $canExport = true,
        public readonly bool $groupByExternalId = false,
    ) {
        if ($fields === []) {
            throw new InvalidArgumentException('An exchange schema requires fields.');
        }
    }

    /** @return array<string, ExchangeField> */
    public function fieldMap(): array
    {
        $map = [];
        foreach ($this->fields as $field) {
            $map[$field->key] = $field;
        }
        return $map;
    }

    /** @param list<string> $headers @return array<int, string|null> */
    public function autoMap(array $headers): array
    {
        $aliases = [];
        foreach ($this->fields as $field) {
            foreach ($field->normalizedNames() as $name) {
                $aliases[$name] = $field->key;
            }
        }
        $mapping = [];
        foreach ($headers as $index => $header) {
            $mapping[$index] = $aliases[ExchangeField::normalize($header)] ?? null;
        }
        return $mapping;
    }
}
