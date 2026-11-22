<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores del dashboard calculados desde el ledger confirmado.
 *
 *  - Patrimonio: activos − pasivos
 *  - Disponible real: saldo de cuentas líquidas (las tarjetas son pasivo y no cuentan como dinero)
 *  - Ingresos/Gastos confirmados: por fecha contable; transferencias, aperturas y conversiones no cuentan
 *  - Nada se mezcla entre monedas: todo se devuelve por moneda; el total convertido es opcional
 *    y usa la cotización de referencia elegida, sin tocar los movimientos originales.
 */
final class DashboardService
{
    /**
     * @param list<string> $spaceIds  uno (Personal/LM Studio) o varios (General)
     * @return array<string,mixed>
     */
    public function summary(string $userId, array $spaceIds, CarbonImmutable $from, CarbonImmutable $to, string $referenceCurrency, string $rateType): array
    {
        $days = max(1, $from->diffInDays($to) + 1);
        $prevTo = $from->subDay();
        $prevFrom = $prevTo->subDays($days - 1);

        $income = $this->flow($spaceIds, 'income', $from, $to);
        $expense = $this->flow($spaceIds, 'expense', $from, $to);
        $prevIncome = $this->flow($spaceIds, 'income', $prevFrom, $prevTo);
        $prevExpense = $this->flow($spaceIds, 'expense', $prevFrom, $prevTo);

        $net = $this->netWorth($spaceIds);
        $available = $this->available($spaceIds);

        return [
            'context_spaces' => $spaceIds,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'previous_from' => $prevFrom->toDateString(), 'previous_to' => $prevTo->toDateString()],
            'reference' => ['currency' => $referenceCurrency, 'rate_type' => $rateType],
            'net_worth' => $this->withConversion($net, $referenceCurrency, $rateType),
            'income' => $this->withConversion($income, $referenceCurrency, $rateType) + ['variation_pct' => $this->variation($income, $prevIncome)],
            'expense' => $this->withConversion($expense, $referenceCurrency, $rateType) + ['variation_pct' => $this->variation($expense, $prevExpense)],
            'available' => $this->withConversion($available, $referenceCurrency, $rateType),
            'expense_by_category' => $this->expenseByCategory($spaceIds, $from, $to),
            'recent' => $this->recent($spaceIds),
            // Cobros y obligaciones llegan con los módulos de recurrencias (roadmap R-2); la estructura queda reservada.
            'upcoming_collections' => [],
            'upcoming_obligations' => [],
        ];
    }

    /** @return array<string,string> moneda => importe */
    private function netWorth(array $spaceIds): array
    {
        $rows = DB::table('v_ledger_balances as b')
            ->join('ledger_accounts as a', 'a.id', '=', 'b.ledger_account_id')
            ->whereIn('b.space_id', $spaceIds)->whereIn('a.type', ['asset', 'liability'])
            ->select('b.currency', 'a.type', 'b.balance')->get();

        $out = [];
        foreach ($rows as $r) {
            $signed = $r->type === 'asset' ? (string) $r->balance : Decimal::sub('0', (string) $r->balance);
            $out[$r->currency] = Decimal::add($out[$r->currency] ?? '0', $signed);
        }

        return $out;
    }

    /** @return array<string,string> */
    private function available(array $spaceIds): array
    {
        $rows = DB::table('v_ledger_balances as b')
            ->join('financial_account_currencies as c', 'c.ledger_account_id', '=', 'b.ledger_account_id')
            ->join('financial_accounts as fa', 'fa.id', '=', 'c.financial_account_id')
            ->whereIn('b.space_id', $spaceIds)->whereIn('fa.kind', OperationService::liquidKinds())
            ->where('fa.is_archived', false)
            ->select('b.currency', 'b.balance')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->currency] = Decimal::add($out[$r->currency] ?? '0', (string) $r->balance);
        }

        return $out;
    }

    /** @return array<string,string> moneda => importe del período (ingreso = haber − debe; gasto = debe − haber) */
    private function flow(array $spaceIds, string $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('e.status', 'confirmed')->whereIn('l.space_id', $spaceIds)->where('a.type', $type)
            ->whereBetween('e.accounting_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('e.currency')
            ->selectRaw('e.currency as currency, SUM(l.debit) as d, SUM(l.credit) as c')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->currency] = $type === 'income' ? Decimal::sub((string) $r->c, (string) $r->d) : Decimal::sub((string) $r->d, (string) $r->c);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function expenseByCategory(array $spaceIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->leftJoin('categories as c', 'c.id', '=', 'l.category_id')
            ->where('e.status', 'confirmed')->whereIn('l.space_id', $spaceIds)->where('a.type', 'expense')
            ->whereBetween('e.accounting_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('l.space_id', 'l.category_id', 'c.name', 'e.currency')
            ->selectRaw('l.space_id as space_id, l.category_id as category_id, COALESCE(c.name, \'Sin categoría\') as category, e.currency as currency, SUM(l.debit) - SUM(l.credit) as total')
            ->orderByDesc('total')->limit(50)->get()->map(fn ($r) => (array) $r)->all();
    }

    /** Últimos cinco movimientos confirmados, con el espacio de origen visible. */
    private function recent(array $spaceIds): array
    {
        $entries = DB::table('journal_entries as e')
            ->join('spaces as s', 's.id', '=', 'e.space_id')
            ->where('e.status', 'confirmed')->whereIn('e.space_id', $spaceIds)->where('e.kind', '<>', 'opening')
            ->orderByDesc('e.occurred_at')->limit(5)
            ->select('e.id', 'e.number', 'e.kind', 'e.currency', 'e.occurred_at', 'e.description', 'e.status', 's.code as space_code', 'e.operation_id')
            ->get();

        return $entries->map(function ($e) {
            $amount = DB::table('journal_lines')->where('entry_id', $e->id)->sum('debit');

            return (array) $e + ['amount' => Decimal::norm((string) $amount)];
        })->all();
    }

    /** @param array<string,string> $byCurrency */
    private function withConversion(array $byCurrency, string $reference, string $rateType): array
    {
        $converted = '0.0000';
        $missing = [];
        foreach ($byCurrency as $currency => $amount) {
            if ($currency === $reference) {
                $converted = Decimal::add($converted, $amount);
                continue;
            }
            $rate = DB::table('exchange_rates')->where('base_currency', $currency)->where('quote_currency', $reference)
                ->where('rate_type', $rateType)->where('quoted_at', '<=', now()->utc()->format('Y-m-d H:i:s.v'))
                ->orderByDesc('quoted_at')->first();
            if ($rate === null) {
                $missing[] = $currency;
                continue;
            }
            $converted = Decimal::add($converted, Decimal::mul($amount, (string) $rate->rate));
        }

        return [
            'by_currency' => $byCurrency,
            'converted' => $missing === [] ? $converted : null,   // null: faltan cotizaciones, no se inventan
            'missing_rates' => $missing,
        ];
    }

    /** @param array<string,string> $current @param array<string,string> $previous */
    private function variation(array $current, array $previous): ?float
    {
        // Variación porcentual solo informativa (no se persiste): se calcula si hay una única moneda comparable.
        $currencies = array_unique([...array_keys($current), ...array_keys($previous)]);
        if (count($currencies) !== 1) {
            return null;
        }
        $c = $current[$currencies[0]] ?? '0';
        $p = $previous[$currencies[0]] ?? '0';
        if (Decimal::isZero($p)) {
            return null;
        }

        return round(((float) Decimal::sub($c, $p) / (float) $p) * 100, 2);
    }
}
