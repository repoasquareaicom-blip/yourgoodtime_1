<?php

namespace App\Support;

class PredictionViewerSignature
{
    public const ALGORITHM = 'sha256';

    public static function normalizeAppKey(?string $appKey): string
    {
        return trim((string) $appKey);
    }

    public static function canonicalInput(int $id, bool $embedded, int $expires): string
    {
        return implode('|', [$id, $embedded ? 1 : 0, $expires]);
    }

    public static function sign(int $id, bool $embedded, int $expires, ?string $appKey): string
    {
        return hash_hmac(
            self::ALGORITHM,
            self::canonicalInput($id, $embedded, $expires),
            self::normalizeAppKey($appKey)
        );
    }

    public static function failureReason(
        int $id,
        bool $embedded,
        int $expires,
        ?string $signature,
        ?string $appKey,
        ?int $now = null
    ): ?string {
        if (! $embedded || $id < 1 || ! is_string($signature) || $signature === '') {
            return 'INVALID_REQUEST';
        }

        if ($expires < ($now ?? time())) {
            return 'EXPIRED';
        }

        if (self::normalizeAppKey($appKey) === '') {
            return 'INVALID_REQUEST';
        }

        return hash_equals(self::sign($id, $embedded, $expires, $appKey), $signature)
            ? null
            : 'SIGNATURE_MISMATCH';
    }

    public static function keyHasBase64Prefix(?string $appKey): bool
    {
        return str_starts_with(self::normalizeAppKey($appKey), 'base64:');
    }
}
