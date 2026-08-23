<?php

declare(strict_types=1);

namespace App\Services;

final class PowerBiSecretCipher
{
    private function key(): string
    {
        $key = getenv('POWER_BI_ENCRYPTION_KEY');

        if (!is_string($key) || strlen($key) < 32) {
            throw new \RuntimeException(
                'Power BI secret encryption is not configured on the server.'
            );
        }

        return hash('sha256', $key, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (!is_string($ciphertext)) {
            throw new \RuntimeException('Power BI credential encryption failed.');
        }

        // Stored layout: 12-byte IV + 16-byte GCM tag + ciphertext, base64 encoded.
        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $binary = base64_decode($encoded, true);

        if (!is_string($binary) || strlen($binary) < 29) {
            throw new \RuntimeException(
                'Power BI credential ciphertext is invalid.'
            );
        }

        $plaintext = openssl_decrypt(
            substr($binary, 28),
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($binary, 0, 12),
            substr($binary, 12, 16)
        );

        if (!is_string($plaintext)) {
            throw new \RuntimeException('Power BI credential decryption failed.');
        }

        return $plaintext;
    }
}
