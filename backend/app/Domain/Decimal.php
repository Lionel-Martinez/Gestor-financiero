<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Aritmética decimal exacta sobre strings (sin float, sin depender de bcmath/gmp).
 *
 * Los importes se manejan siempre como string decimal con escala fija:
 *  - importes: escala 4  (DECIMAL(19,4))
 *  - cotizaciones: escala 8 (DECIMAL(20,8))
 *
 * Internamente cada valor se convierte a (signo, dígitos enteros sin escala).
 */
final class Decimal
{
    public const AMOUNT_SCALE = 4;
    public const RATE_SCALE = 8;

    /** Valida y normaliza a la escala dada. No redondea: más decimales de los permitidos es un error. */
    public static function norm(string $value, int $scale = self::AMOUNT_SCALE): string
    {
        [$sign, $digits] = self::parse($value, $scale);

        return self::format($sign, $digits, $scale);
    }

    public static function add(string $a, string $b, int $scale = self::AMOUNT_SCALE): string
    {
        [$sa, $da] = self::parse($a, $scale);
        [$sb, $db] = self::parse($b, $scale);

        return self::format(...self::addSigned($sa, $da, $sb, $db), scale: $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::AMOUNT_SCALE): string
    {
        [$sb, $db] = self::parse($b, $scale);

        return self::add($a, self::format($sb === 0 ? 0 : -$sb, $db, $scale), $scale);
    }

    /** -1, 0 o 1 */
    public static function cmp(string $a, string $b, int $scale = self::AMOUNT_SCALE): int
    {
        [$sa, $da] = self::parse($a, $scale);
        [$sb, $db] = self::parse($b, $scale);

        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        if ($sa === 0) {
            return 0;
        }

        return $sa * self::cmpPos($da, $db);
    }

    public static function isZero(string $value, int $scale = self::AMOUNT_SCALE): bool
    {
        return self::parse($value, $scale)[0] === 0;
    }

    public static function isNegative(string $value, int $scale = self::AMOUNT_SCALE): bool
    {
        return self::parse($value, $scale)[0] < 0;
    }

    /**
     * Multiplica $amount (escala $amountScale) por $rate (escala $rateScale) y devuelve
     * el resultado en $outScale, redondeado half-up (lejos de cero).
     */
    public static function mul(
        string $amount,
        string $rate,
        int $amountScale = self::AMOUNT_SCALE,
        int $rateScale = self::RATE_SCALE,
        int $outScale = self::AMOUNT_SCALE,
    ): string {
        [$sa, $da] = self::parse($amount, $amountScale);
        [$sr, $dr] = self::parse($rate, $rateScale);

        $sign = $sa * $sr;
        $product = self::mulPos($da, $dr);            // escala amountScale + rateScale
        $drop = $amountScale + $rateScale - $outScale;

        if ($drop > 0) {
            $product = self::roundHalfUp($product, $drop);
        } elseif ($drop < 0) {
            $product .= str_repeat('0', -$drop);
        }

        return self::format($product === '0' ? 0 : $sign, $product, $outScale);
    }

    // ---------------------------------------------------------------- internos

    /** @return array{0:int,1:string} signo (-1,0,1) y dígitos sin escala ni ceros a la izquierda */
    private static function parse(string $value, int $scale): array
    {
        if (!preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Importe decimal inválido: '{$value}'");
        }

        $frac = $m[3] ?? '';
        if (strlen($frac) > $scale) {
            // Se admite solo si los decimales sobrantes son ceros (p. ej. "10.500000" con escala 4).
            if (rtrim(substr($frac, $scale), '0') !== '') {
                throw new InvalidArgumentException("'{$value}' excede la escala {$scale}");
            }
            $frac = substr($frac, 0, $scale);
        }

        $digits = self::trimZeros($m[2] . str_pad($frac, $scale, '0'));
        $sign = $digits === '0' ? 0 : ($m[1] === '-' ? -1 : 1);

        return [$sign, $digits];
    }

    private static function format(int $sign, string $digits, int $scale): string
    {
        $digits = self::trimZeros($digits);
        if ($scale > 0) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            $out = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
        } else {
            $out = $digits;
        }

        return ($sign < 0 ? '-' : '') . $out;
    }

    /** @return array{0:int,1:string} */
    private static function addSigned(int $sa, string $da, int $sb, string $db): array
    {
        if ($sa === 0) {
            return [$sb, $db];
        }
        if ($sb === 0) {
            return [$sa, $da];
        }
        if ($sa === $sb) {
            return [$sa, self::addPos($da, $db)];
        }

        $c = self::cmpPos($da, $db);
        if ($c === 0) {
            return [0, '0'];
        }

        return $c > 0
            ? [$sa, self::subPos($da, $db)]
            : [$sb, self::subPos($db, $da)];
    }

    private static function trimZeros(string $d): string
    {
        $d = ltrim($d, '0');

        return $d === '' ? '0' : $d;
    }

    private static function cmpPos(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    private static function addPos(string $a, string $b): string
    {
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $carry = 0;
        $out = '';
        while ($i >= 0 || $j >= 0 || $carry) {
            $s = ($i >= 0 ? (int) $a[$i--] : 0) + ($j >= 0 ? (int) $b[$j--] : 0) + $carry;
            $out .= (string) ($s % 10);
            $carry = intdiv($s, 10);
        }

        return self::trimZeros(strrev($out));
    }

    /** a >= b */
    private static function subPos(string $a, string $b): string
    {
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $borrow = 0;
        $out = '';
        while ($i >= 0) {
            $d = (int) $a[$i--] - $borrow - ($j >= 0 ? (int) $b[$j--] : 0);
            if ($d < 0) {
                $d += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $out .= (string) $d;
        }

        return self::trimZeros(strrev($out));
    }

    private static function mulPos(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $la = strlen($a);
        $lb = strlen($b);
        $res = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            for ($j = $lb - 1; $j >= 0; $j--) {
                $p = (int) $a[$i] * (int) $b[$j] + $res[$i + $j + 1];
                $res[$i + $j + 1] = $p % 10;
                $res[$i + $j] += intdiv($p, 10);
            }
        }

        return self::trimZeros(implode('', $res));
    }

    /** Divide por 10^$drop redondeando half-up. */
    private static function roundHalfUp(string $digits, int $drop): string
    {
        if (strlen($digits) <= $drop) {
            $digits = str_pad($digits, $drop + 1, '0', STR_PAD_LEFT);
        }
        $keep = substr($digits, 0, -$drop);
        $first = (int) $digits[strlen($digits) - $drop];

        return $first >= 5 ? self::addPos($keep, '1') : self::trimZeros($keep);
    }
}
