<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Decimal;

/**
 * Validación pura (sin base de datos) de un asiento contable.
 *
 * Reglas (spec §6.1, §6.7, §16):
 *  - al menos dos apuntes, un único lado (debe o haber) por apunte, importes > 0
 *  - una sola moneda por asiento y compatible con la cuenta contable
 *  - Debe = Haber
 *  - cuentas agrupadoras no reciben apuntes; cuentas inactivas no admiten nuevos
 *  - todas las referencias pertenecen al mismo espacio que el asiento
 *  - activos/pasivos/patrimonio no aceptan categoría; ingresos/gastos la exigen
 *    salvo en asientos de tipo fx/adjustment/opening
 *  - la categoría debe ser global o del mismo espacio, activa y compatible con el tipo de cuenta
 *
 * El servidor repite esta validación (LedgerService) y la app móvil usa un port en TypeScript.
 */
final class EntryValidator
{
    public const ASSET = 'asset';
    public const LIABILITY = 'liability';
    public const EQUITY = 'equity';
    public const INCOME = 'income';
    public const EXPENSE = 'expense';

    /** Tipos de asiento que pueden tener ingresos/gastos sin categoría. */
    private const UNCATEGORIZED_ALLOWED_KINDS = ['fx', 'adjustment', 'opening'];

    /**
     * @param array{space_id:string,currency:string,kind:string} $entry
     * @param list<array{ledger_account_id:string,debit:string,credit:string,category_id:?string}> $lines
     * @param array<string,array{space_id:string,type:string,is_group:bool,is_active:bool,currency:?string}> $accounts
     * @param array<string,array{space_id:?string,kind:string,is_active:bool}> $categories
     * @return list<string> lista de errores (vacía si el asiento es válido)
     */
    public function validate(array $entry, array $lines, array $accounts, array $categories): array
    {
        $errors = [];

        if (count($lines) < 2) {
            $errors[] = 'El asiento necesita al menos dos apuntes.';
        }

        $debit = '0.0000';
        $credit = '0.0000';

        foreach ($lines as $i => $line) {
            $n = $i + 1;
            try {
                $d = Decimal::norm($line['debit']);
                $c = Decimal::norm($line['credit']);
            } catch (\InvalidArgumentException $e) {
                $errors[] = "Apunte {$n}: {$e->getMessage()}";
                continue;
            }

            if (Decimal::isNegative($d) || Decimal::isNegative($c)) {
                $errors[] = "Apunte {$n}: los importes no pueden ser negativos.";
            }
            $hasD = !Decimal::isZero($d);
            $hasC = !Decimal::isZero($c);
            if ($hasD === $hasC) {
                $errors[] = "Apunte {$n}: debe tener importe en el debe o en el haber, no en ambos ni en ninguno.";
            }

            $debit = Decimal::add($debit, $d);
            $credit = Decimal::add($credit, $c);

            $account = $accounts[$line['ledger_account_id']] ?? null;
            if ($account === null) {
                $errors[] = "Apunte {$n}: la cuenta contable no existe.";
                continue;
            }
            if ($account['space_id'] !== $entry['space_id']) {
                $errors[] = "Apunte {$n}: la cuenta contable pertenece a otro espacio.";
            }
            if ($account['is_group']) {
                $errors[] = "Apunte {$n}: las cuentas agrupadoras no reciben apuntes directos.";
            }
            if (!$account['is_active']) {
                $errors[] = "Apunte {$n}: la cuenta contable está inactiva.";
            }
            if ($account['currency'] !== null && $account['currency'] !== $entry['currency']) {
                $errors[] = "Apunte {$n}: la moneda de la cuenta ({$account['currency']}) no coincide con la del asiento ({$entry['currency']}).";
            }

            $errors = [...$errors, ...$this->categoryErrors($n, $entry, $account['type'], $line['category_id'] ?? null, $categories)];
        }

        if (Decimal::cmp($debit, $credit) !== 0) {
            $errors[] = "El asiento no está equilibrado (debe {$debit} ≠ haber {$credit}).";
        }
        if (Decimal::isZero($debit)) {
            $errors[] = 'El asiento no puede tener importe cero.';
        }

        return $errors;
    }

    /**
     * @param array<string,array{space_id:?string,kind:string,is_active:bool}> $categories
     * @return list<string>
     */
    private function categoryErrors(int $n, array $entry, string $accountType, ?string $categoryId, array $categories): array
    {
        $isPnl = in_array($accountType, [self::INCOME, self::EXPENSE], true);

        if (!$isPnl) {
            return $categoryId !== null
                ? ["Apunte {$n}: activos, pasivos y patrimonio no aceptan categorías de ingreso/gasto."]
                : [];
        }

        if ($categoryId === null) {
            return in_array($entry['kind'], self::UNCATEGORIZED_ALLOWED_KINDS, true)
                ? []
                : ["Apunte {$n}: los ingresos y gastos requieren una categoría."];
        }

        $category = $categories[$categoryId] ?? null;
        if ($category === null) {
            return ["Apunte {$n}: la categoría no existe."];
        }

        $errors = [];
        if ($category['space_id'] !== null && $category['space_id'] !== $entry['space_id']) {
            $errors[] = "Apunte {$n}: la categoría pertenece a otro espacio.";
        }
        if (!$category['is_active']) {
            $errors[] = "Apunte {$n}: la categoría está desactivada.";
        }
        if ($category['kind'] !== 'both' && $category['kind'] !== $accountType) {
            $errors[] = "Apunte {$n}: la categoría es de tipo {$category['kind']} y la cuenta es de {$accountType}.";
        }

        return $errors;
    }
}
