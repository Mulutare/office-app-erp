<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use RuntimeException;

final class CsvCodec
{
    /** @return array{headers:list<string>,rows:list<list<mixed>>} */
    public function read(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSV file could not be opened.');
        }
        $records = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) > FileGuard::MAX_COLUMNS) {
                throw new RuntimeException('Spreadsheet exceeds the 100-column limit.');
            }
            $records[] = array_map([$this, 'clean'], $row);
            if (count($records) > FileGuard::MAX_ROWS + 1) {
                throw new RuntimeException('Spreadsheet exceeds the 10,000-row limit.');
            }
        }
        fclose($handle);
        $headers = array_map('strval', array_shift($records) ?? []);
        return ['headers' => $headers, 'rows' => $records];
    }

    /** @param list<string> $headers @param list<array<string, mixed>> $rows */
    public function write(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('CSV output could not be created.');
        }
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map([$this, 'safe'], array_values($row)), ',', '"', '');
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);
        return "\xEF\xBB\xBF" . ($contents === false ? '' : $contents);
    }

    private function clean(mixed $value): string
    {
        $value = (string) $value;
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('CSV must use UTF-8 encoding.');
        }
        if (preg_match('/^[=+\-@]/', ltrim($value)) === 1) {
            throw new RuntimeException('Spreadsheet formulas are not accepted.');
        }
        return trim($value);
    }

    private function safe(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return preg_match('/^[=+\-@]/', ltrim($value)) === 1 ? "'" . $value : $value;
    }
}
