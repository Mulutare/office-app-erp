<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use RuntimeException;
use ZipArchive;

final class FileGuard
{
    public const MAX_BYTES = 10_485_760;
    public const MAX_ROWS = 10_000;
    public const MAX_COLUMNS = 100;

    public function validate(string $path, string $originalName): string
    {
        if (!is_file($path) || filesize($path) === false || filesize($path) > self::MAX_BYTES) {
            throw new RuntimeException('Upload is missing or exceeds the 10 MB limit.');
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            throw new RuntimeException('Only CSV and XLSX files are accepted.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        $allowed = $extension === 'csv'
            ? ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel']
            : ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('The uploaded file content does not match its extension.');
        }
        if ($extension === 'xlsx') {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw new RuntimeException('The XLSX workbook structure is invalid.');
            }
            $zip->close();
        }
        return $extension;
    }
}
