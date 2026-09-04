<?php

declare(strict_types=1);

namespace App\Services;

final class PrivateUploadService
{
    private const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];

    private const MAX_BYTES = 10485760;

    public function storeEvidence(int $companyId, array $file): array
    {
        if (
            ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name'])
        ) {
            throw new \RuntimeException(
                'Select a valid PDF, PNG or JPEG bank receipt.'
            );
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new \RuntimeException(
                'Bank receipt must be no larger than 10 MB.'
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);

        if (!isset(self::ALLOWED[$mime])) {
            throw new \RuntimeException(
                'Only genuine PDF, PNG and JPEG bank receipts are accepted.'
            );
        }

        $root =
            dirname(__DIR__, 2)
            . '/storage/private/bank-evidence/company-'
            . $companyId;

        if (
            !is_dir($root)
            && !mkdir($root, 0700, true)
            && !is_dir($root)
        ) {
            throw new \RuntimeException(
                'Private evidence storage is unavailable.'
            );
        }

        $name =
            bin2hex(random_bytes(24))
            . '.'
            . self::ALLOWED[$mime];

        $path = $root . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new \RuntimeException(
                'Bank receipt could not be stored.'
            );
        }

        chmod($path, 0600);

        return [
            'evidence_path' => $path,
            'evidence_original_name' =>
                basename((string) ($file['name'] ?? 'evidence')),
            'evidence_mime' => $mime,
            'evidence_size' => $size,
            'evidence_sha256' => hash_file('sha256', $path),
        ];
    }

    public function storeQuickSaleInvoice(
        int $companyId,
        array $file
    ): array {
        if (
            ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name'])
        ) {
            throw new \RuntimeException(
                'Attach a valid PDF, PNG or JPEG invoice / receipt.'
            );
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new \RuntimeException(
                'Invoice attachment must be no larger than 10 MB.'
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);

        if (!isset(self::ALLOWED[$mime])) {
            throw new \RuntimeException(
                'Only genuine PDF, PNG and JPEG invoice attachments are accepted.'
            );
        }

        $root =
            dirname(__DIR__, 2)
            . '/storage/private/quick-sale-invoices/company-'
            . $companyId;

        if (
            !is_dir($root)
            && !mkdir($root, 0700, true)
            && !is_dir($root)
        ) {
            throw new \RuntimeException(
                'Private Quick Sale invoice storage is unavailable.'
            );
        }

        $name =
            bin2hex(random_bytes(24))
            . '.'
            . self::ALLOWED[$mime];

        $path = $root . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new \RuntimeException(
                'Invoice attachment could not be stored.'
            );
        }

        chmod($path, 0600);

        return [
            'evidence_path' => $path,
            'evidence_original_name' =>
                basename((string) ($file['name'] ?? 'invoice')),
            'evidence_mime' => $mime,
            'evidence_size' => $size,
            'evidence_sha256' => hash_file('sha256', $path),
        ];
    }

    public function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}