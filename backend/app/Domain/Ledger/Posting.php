<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Decimal;
use InvalidArgumentException;

/**
 * Constructores puros de asientos (cabecera + apuntes) para cada tipo de operación.
 * No tocan la base de datos: reciben IDs de cuentas contables ya resueltos.
 *
 * Resultado: ['entry' => [...cabecera...], 'lines' => [...apuntes...]]
 *
 * Reglas de negocio reflejadas aquí:
 *  - ingreso:   Debe cuenta de fondos / Haber ingreso (con categoría)
 *  - gasto:     Debe gasto (con categoría) / Haber cuenta de fondos
 *  - transferencia entre cuentas propias: no toca ingresos ni gastos (spec §7.3)
 *  - transferencia entre espacios: un asiento por espacio contra su cuenta de
 *    transferencia interespacio, con el mismo operation_id (spec §7.2)
 *  - conversión: un asiento por moneda contra cuentas puente (spec §7.1)
 *  - apertura: contrapartida de patrimonio inicial, nunca ingreso (spec §8.2)
 */
final class Posting
{
    /** @return array{entry:array<string,mixed>,lines:list<array<string,mixed>>} */
    public static function income(string $spaceId, string $currency, string $fundsAccountId, string $incomeAccountId, string $amount, string $categoryId, string $occurredAt, string $description = ''): array
    {
        $amount = self::positive($amount);

        return self::build($spaceId, $currency, 'ordinary', $occurredAt, $description, [
            self::line($fundsAccountId, $amount, '0.0000'),
            self::line($incomeAccountId, '0.0000', $amount, $categoryId),
        ]);
    }

    /** @return array{entry:array<string,mixed>,lines:list<array<string,mixed>>} */
    public static function expense(string $spaceId, string $currency, string $fundsAccountId, string $expenseAccountId, string $amount, string $categoryId, string $occurredAt, string $description = ''): array
    {
        $amount = self::positive($amount);

        return self::build($spaceId, $currency, 'ordinary', $occurredAt, $description, [
            self::line($expenseAccountId, $amount, '0.0000', $categoryId),
            self::line($fundsAccountId, '0.0000', $amount),
        ]);
    }

    /** Transferencia entre cuentas propias del mismo espacio y moneda. */
    public static function ownTransfer(string $spaceId, string $currency, string $fromAccountId, string $toAccountId, string $amount, string $occurredAt, string $description = ''): array
    {
        if ($fromAccountId === $toAccountId) {
            throw new InvalidArgumentException('Origen y destino de la transferencia no pueden ser la misma cuenta.');
        }
        $amount = self::positive($amount);

        return self::build($spaceId, $currency, 'ordinary', $occurredAt, $description, [
            self::line($toAccountId, $amount, '0.0000'),
            self::line($fromAccountId, '0.0000', $amount),
        ]);
    }

    /**
     * Transferencia entre espacios (Personal ↔ LM Studio).
     *
     * @return array{out:array{entry:array<string,mixed>,lines:list<array<string,mixed>>},in:array{entry:array<string,mixed>,lines:list<array<string,mixed>>}}
     */
    public static function interSpaceTransfer(
        string $fromSpaceId, string $fromFundsAccountId, string $fromClearingAccountId,
        string $toSpaceId, string $toFundsAccountId, string $toClearingAccountId,
        string $currency, string $amount, string $occurredAt, string $description = '',
    ): array {
        if ($fromSpaceId === $toSpaceId) {
            throw new InvalidArgumentException('Una transferencia entre espacios requiere espacios distintos.');
        }
        $amount = self::positive($amount);

        return [
            // Sale dinero del espacio origen: queda un derecho contra el otro espacio.
            'out' => self::build($fromSpaceId, $currency, 'ordinary', $occurredAt, $description, [
                self::line($fromClearingAccountId, $amount, '0.0000'),
                self::line($fromFundsAccountId, '0.0000', $amount),
            ]),
            // Entra dinero al espacio destino: queda una obligación con el otro espacio.
            'in' => self::build($toSpaceId, $currency, 'ordinary', $occurredAt, $description, [
                self::line($toFundsAccountId, $amount, '0.0000'),
                self::line($toClearingAccountId, '0.0000', $amount),
            ]),
        ];
    }

    /**
     * Conversión de moneda con cuentas puente: dos asientos independientes y equilibrados,
     * cada uno en su moneda. Comisión opcional como gasto separado en la moneda de origen.
     *
     * @return array{source:array<string,mixed>,target:array<string,mixed>,commission:?array<string,mixed>,rate:string}
     */
    public static function conversion(
        string $spaceId,
        string $fromCurrency, string $fromFundsAccountId, string $fromBridgeAccountId, string $fromAmount,
        string $toCurrency, string $toFundsAccountId, string $toBridgeAccountId, string $toAmount,
        string $occurredAt, string $description = '',
        ?string $commissionAmount = null, ?string $commissionExpenseAccountId = null,
    ): array {
        if ($fromCurrency === $toCurrency) {
            throw new InvalidArgumentException('Una conversión requiere monedas distintas.');
        }
        $fromAmount = self::positive($fromAmount);
        $toAmount = self::positive($toAmount);

        $commission = null;
        if ($commissionAmount !== null && !Decimal::isZero($commissionAmount)) {
            if ($commissionExpenseAccountId === null) {
                throw new InvalidArgumentException('La comisión requiere una cuenta de gasto.');
            }
            $commissionAmount = self::positive($commissionAmount);
            $commission = self::build($spaceId, $fromCurrency, 'fx', $occurredAt, "Comisión: {$description}", [
                self::line($commissionExpenseAccountId, $commissionAmount, '0.0000'),
                self::line($fromFundsAccountId, '0.0000', $commissionAmount),
            ]);
        }

        // Cotización implícita (unidades de destino por unidad de origen), solo informativa.
        $rate = self::impliedRate($fromAmount, $toAmount);

        return [
            'source' => self::build($spaceId, $fromCurrency, 'fx', $occurredAt, $description, [
                self::line($fromBridgeAccountId, $fromAmount, '0.0000'),
                self::line($fromFundsAccountId, '0.0000', $fromAmount),
            ]),
            'target' => self::build($spaceId, $toCurrency, 'fx', $occurredAt, $description, [
                self::line($toFundsAccountId, $toAmount, '0.0000'),
                self::line($toBridgeAccountId, '0.0000', $toAmount),
            ]),
            'commission' => $commission,
            'rate' => $rate,
        ];
    }

    /**
     * Asiento de apertura. Activo: Debe activo / Haber patrimonio inicial.
     * Pasivo (p. ej. deuda de tarjeta): Debe patrimonio inicial / Haber pasivo.
     */
    public static function opening(string $spaceId, string $currency, string $accountId, string $accountType, string $equityAccountId, string $amount, string $occurredAt): array
    {
        $amount = self::positive($amount);

        $lines = match ($accountType) {
            EntryValidator::ASSET => [self::line($accountId, $amount, '0.0000'), self::line($equityAccountId, '0.0000', $amount)],
            EntryValidator::LIABILITY => [self::line($equityAccountId, $amount, '0.0000'), self::line($accountId, '0.0000', $amount)],
            default => throw new InvalidArgumentException('La apertura solo aplica a cuentas de activo o pasivo.'),
        };

        return self::build($spaceId, $currency, 'opening', $occurredAt, 'Saldo inicial', $lines);
    }

    /**
     * Reversión total o parcial de un asiento confirmado.
     *
     * @param list<array{id:string,ledger_account_id:string,debit:string,credit:string,category_id:?string}> $originalLines
     * @param array<string,string> $remaining  importe aún no revertido por apunte original (id => importe)
     * @param array<string,string>|null $requested null = revertir todo lo pendiente; si no, apunte original => importe a revertir
     * @return array{lines:list<array<string,mixed>>,allocations:array<string,string>}
     */
    public static function reversalLines(array $originalLines, array $remaining, ?array $requested = null): array
    {
        $lines = [];
        $allocations = [];

        foreach ($originalLines as $orig) {
            $left = $remaining[$orig['id']] ?? Decimal::norm(Decimal::isZero($orig['debit']) ? $orig['credit'] : $orig['debit']);
            $amount = $requested === null ? $left : ($requested[$orig['id']] ?? '0.0000');
            $amount = Decimal::norm($amount);

            if (Decimal::isZero($amount)) {
                continue;
            }
            if (Decimal::isNegative($amount) || Decimal::cmp($amount, $left) > 0) {
                throw new InvalidArgumentException("El importe a revertir del apunte {$orig['id']} excede lo pendiente de revertir ({$left}).");
            }

            $wasDebit = !Decimal::isZero($orig['debit']);
            $lines[] = self::line(
                $orig['ledger_account_id'],
                $wasDebit ? '0.0000' : $amount,
                $wasDebit ? $amount : '0.0000',
                $orig['category_id'] ?? null,
            );
            $allocations[$orig['id']] = $amount;
        }

        if ($lines === []) {
            throw new InvalidArgumentException('No hay importes pendientes de revertir en este asiento.');
        }

        return ['lines' => $lines, 'allocations' => $allocations];
    }

    // ---------------------------------------------------------------- internos

    private static function positive(string $amount): string
    {
        $amount = Decimal::norm($amount);
        if (Decimal::isNegative($amount) || Decimal::isZero($amount)) {
            throw new InvalidArgumentException('El importe debe ser mayor que cero.');
        }

        return $amount;
    }

    /** @return array{ledger_account_id:string,debit:string,credit:string,category_id:?string} */
    private static function line(string $accountId, string $debit, string $credit, ?string $categoryId = null): array
    {
        return ['ledger_account_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'category_id' => $categoryId];
    }

    /** @param list<array<string,mixed>> $lines */
    private static function build(string $spaceId, string $currency, string $kind, string $occurredAt, string $description, array $lines): array
    {
        return [
            'entry' => [
                'space_id' => $spaceId,
                'currency' => $currency,
                'kind' => $kind,
                'occurred_at' => $occurredAt,
                'description' => $description,
            ],
            'lines' => $lines,
        ];
    }

    private static function impliedRate(string $fromAmount, string $toAmount): string
    {
        // rate = toAmount / fromAmount con 8 decimales (half-up). Ambos importes tienen escala 4,
        // así que la razón de las unidades enteras es la cotización real.
        $num = ltrim(str_replace('.', '', $toAmount), '0') ?: '0';
        $den = ltrim(str_replace('.', '', $fromAmount), '0') ?: '0';

        $q = self::divStr($num . str_repeat('0', 9), $den);   // floor(rate * 10^9)
        $last = (int) substr($q, -1);
        $base = strlen($q) > 1 ? substr($q, 0, -1) : '0';     // floor(rate * 10^8)
        if ($last >= 5) {
            $base = Decimal::add($base, '1', 0);
        }

        return self::fmt($base, Decimal::RATE_SCALE);
    }

    private static function fmt(string $digits, int $scale): string
    {
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }

    /** División entera larga de strings positivos. */
    private static function divStr(string $num, string $den): string
    {
        $out = '';
        $rem = '0';
        foreach (str_split($num) as $ch) {
            $rem = ltrim($rem . $ch, '0') ?: '0';
            $q = 0;
            while (Decimal::cmp($rem, $den, 0) >= 0) {
                $rem = Decimal::sub($rem, $den, 0);
                $q++;
            }
            $out .= $q;
        }

        return ltrim($out, '0') ?: '0';
    }
}
