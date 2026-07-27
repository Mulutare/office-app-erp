<?php

declare(strict_types=1);

namespace App\Services;

final class AuditChangePresenter
{
    /**
     * @return list<array{
     *     field: string,
     *     old: string,
     *     new: string
     * }>
     */
    public function changes(
        mixed $oldValues,
        mixed $newValues
    ): array {
        $old = $this->decode($oldValues);
        $new = $this->decode($newValues);
        $fields = array_values(array_unique(
            array_merge(
                array_keys($old),
                array_keys($new)
            )
        ));
        $changes = [];

        foreach (array_slice($fields, 0, 30) as $field) {
            $field = (string) $field;
            $oldValue = $this->format(
                $field,
                $old[$field] ?? null
            );
            $newValue = $this->format(
                $field,
                $new[$field] ?? null
            );

            if (
                $oldValue === $newValue
                && !$this->isSensitiveField($field)
            ) {
                continue;
            }

            $changes[] = [
                'field' => $this->fieldLabel($field),
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    /**
     * @return list<array{
     *     field: string,
     *     value: string
     * }>
     */
    public function snapshot(mixed $values): array
    {
        $decoded = $this->decode($values);
        $snapshot = [];

        foreach (
            array_slice($decoded, 0, 30, true)
            as $field => $value
        ) {
            $snapshot[] = [
                'field' => $this->fieldLabel(
                    (string) $field
                ),
                'value' => $this->format(
                    (string) $field,
                    $value
                ),
            ];
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function format(
        string $field,
        mixed $value
    ): string {
        if ($this->isSensitiveField($field)) {
            return '[REDACTED]';
        }

        if ($value === null) {
            return 'Not set';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            );

            return is_string($encoded)
                ? mb_substr($encoded, 0, 500)
                : 'Recorded value';
        }

        return mb_substr((string) $value, 0, 500);
    }

    private function fieldLabel(string $field): string
    {
        return ucwords(str_replace(
            '_',
            ' ',
            $field
        ));
    }

    private function isSensitiveField(
        string $field
    ): bool {
        $normalized = strtolower($field);
        $sensitiveTerms = [
            'password',
            'secret',
            'token',
            'credential',
            'private_key',
            'api_key',
            'hash',
        ];

        foreach ($sensitiveTerms as $term) {
            if (str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }
}
