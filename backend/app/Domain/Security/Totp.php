<?php

declare(strict_types=1);

namespace App\Domain\Security;

use InvalidArgumentException;

/**
 * TOTP (RFC 6238) compatible con Google Authenticator, Aegis y similares.
 * SHA-1, 6 dígitos, paso de 30 s. Sin SMS (spec §10.1).
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $base32Secret, int $timestamp, int $period = 30, int $digits = 6): string
    {
        return self::hotp(self::base32Decode($base32Secret), intdiv($timestamp, $period), $digits);
    }

    /** Verifica con tolerancia de ±$window pasos y comparación en tiempo constante. */
    public static function verify(string $base32Secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $timestamp ??= time();
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $valid = false;
        for ($i = -$window; $i <= $window; $i++) {
            $expected = self::code($base32Secret, $timestamp + $i * 30);
            $valid = hash_equals($expected, $code) || $valid;
        }

        return $valid;
    }

    public static function provisioningUri(string $base32Secret, string $account, string $issuer = 'Gestor Financiero'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($account),
            $base32Secret,
            rawurlencode($issuer),
        );
    }

    /**
     * Códigos de recuperación de un solo uso. Se devuelven en claro UNA vez; en base de datos
     * se guarda solo el hash (ver hashRecoveryCode).
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        return $codes;
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtoupper(str_replace([' ', '-'], '', $code)));
    }

    // ---------------------------------------------------------------- internos

    private static function hotp(string $key, int $counter, int $digits): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim(str_replace(' ', '', $b32), '='));
        $bits = '';
        foreach (str_split($b32) as $ch) {
            $pos = strpos(self::ALPHABET, $ch);
            if ($pos === false) {
                throw new InvalidArgumentException('Secreto base32 inválido.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
