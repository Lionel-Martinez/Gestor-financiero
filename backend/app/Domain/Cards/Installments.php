<?php

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Decimal;
use InvalidArgumentException;

/**
 * Cronograma de cuotas de una compra con tarjeta de crédito, sin interés (decisión D-5).
 *
 * Reglas:
 *  - Una compra hecha hasta el día de cierre (inclusive) entra en el resumen que cierra ese mes; después, en el del mes siguiente.
 *  - La cuota k va en el resumen k meses después del primero.
 *  - El vencimiento es el día de vencimiento del mes del cierre si cae después del cierre; si no, del mes siguiente.
 *  - El importe se reparte en partes iguales a 4 decimales y el resto va a la última cuota (la suma es exacta).
 *  - Días de cierre y vencimiento válidos: 1 a 28 (evita meses cortos).
 *
 * El gasto se reconoce completo al comprar; las cuotas solo organizan lo que se debe pagar en cada resumen.
 */
final class Installments
{
    public const MAX_INSTALLMENTS = 60;

    /**
     * @return list<array{no:int,amount:string,statement_month:string,due_date:string}>
     */
    public static function schedule(string $purchaseDate, int $closingDay, int $dueDay, string $amount, int $count): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $purchaseDate, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Fecha de compra inválida.');
        }
        foreach ([$closingDay, $dueDay] as $d) {
            if ($d < 1 || $d > 28) {
                throw new InvalidArgumentException('El día de cierre y el de vencimiento deben estar entre 1 y 28.');
            }
        }
        if ($count < 1 || $count > self::MAX_INSTALLMENTS) {
            throw new InvalidArgumentException('La cantidad de cuotas debe estar entre 1 y ' . self::MAX_INSTALLMENTS . '.');
        }
        $amount = Decimal::norm($amount);
        if (Decimal::isNegative($amount) || Decimal::isZero($amount)) {
            throw new InvalidArgumentException('El importe debe ser mayor que cero.');
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $index = $year * 12 + ($month - 1) + ($day <= $closingDay ? 0 : 1);   // meses desde el año 0
        $dueNextMonth = $dueDay <= $closingDay;

        $units = (int) str_replace('.', '', $amount);
        $base = intdiv($units, $count);
        $rest = $units - $base * $count;

        $out = [];
        for ($k = 0; $k < $count; $k++) {
            $s = $index + $k;
            $d = $s + ($dueNextMonth ? 1 : 0);
            $part = $base + ($k === $count - 1 ? $rest : 0);
            $out[] = [
                'no' => $k + 1,
                'amount' => self::fromUnits($part),
                'statement_month' => sprintf('%04d-%02d', intdiv($s, 12), $s % 12 + 1),
                'due_date' => sprintf('%04d-%02d-%02d', intdiv($d, 12), $d % 12 + 1, $dueDay),
            ];
        }

        return $out;
    }

    private static function fromUnits(int $units): string
    {
        return sprintf('%d.%04d', intdiv($units, 10000), $units % 10000);
    }
}
