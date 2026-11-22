<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Decimal;
use App\Domain\Ledger\EntryValidator;
use App\Domain\Ledger\Posting;
use App\Exceptions\LedgerException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Único punto de escritura del libro mayor (spec §10.2: servicios Laravel exclusivos para operaciones contables).
 *
 * Flujo: createEntry() deja un borrador (no afecta saldos) → confirm() valida, resuelve el período,
 * asigna número correlativo y confirma, todo dentro de una transacción atómica.
 */
final class LedgerService
{
    private const MAX_PERIOD_SHIFT_MONTHS = 36;

    public function __construct(
        private readonly EntryValidator $validator,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array{entry:array<string,mixed>,lines:list<array<string,mixed>>} $posting
     * @return string id del asiento (borrador)
     */
    public function createEntry(
        array $posting, string $userId, ?string $deviceId = null,
        ?string $operationId = null, ?string $syncOpId = null, ?string $entryId = null,
        string $status = 'draft', ?array $validationErrors = null,
    ): string {
        $e = $posting['entry'];
        $id = $entryId ?? (string) Str::uuid();
        $now = $this->now();

        return DB::transaction(function () use ($posting, $e, $id, $now, $userId, $deviceId, $operationId, $syncOpId, $status, $validationErrors) {
            DB::table('journal_entries')->insert([
                'id' => $id,
                'space_id' => $e['space_id'],
                'operation_id' => $operationId,
                'status' => $status,
                'kind' => $e['kind'],
                'currency' => $e['currency'],
                'occurred_at' => $this->fmt(CarbonImmutable::parse($e['occurred_at'])),
                'recorded_at' => $now,
                'description' => mb_substr((string) ($e['description'] ?? ''), 0, 500),
                'reverses_entry_id' => $e['reverses_entry_id'] ?? null,
                'reversal_reason' => $e['reversal_reason'] ?? null,
                'validation_errors' => $validationErrors === null ? null : json_encode($validationErrors, JSON_THROW_ON_ERROR),
                'created_by' => $userId,
                'device_id' => $deviceId,
                'sync_op_id' => $syncOpId,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($posting['lines'] as $i => $line) {
                DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'entry_id' => $id,
                    'space_id' => $e['space_id'],
                    'line_no' => $i + 1,
                    'ledger_account_id' => $line['ledger_account_id'],
                    'debit' => Decimal::norm($line['debit']),
                    'credit' => Decimal::norm($line['credit']),
                    'category_id' => $line['category_id'] ?? null,
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            $this->audit->log('journal_entry', $id, 'draft_created', $e['space_id'], $userId, $deviceId,
                after: ['status' => $status, 'kind' => $e['kind'], 'currency' => $e['currency']]);

            return $id;
        });
    }

    /**
     * Confirma un asiento. Idempotente: confirmar uno ya confirmado devuelve su número.
     *
     * @return array{entry_id:string,number:string,accounting_date:string,period_id:string,period_shifted:bool}
     * @throws LedgerException si no pasa las validaciones
     */
    public function confirm(string $entryId, string $userId, ?string $deviceId = null, ?string $accountingDate = null): array
    {
        return DB::transaction(function () use ($entryId, $userId, $deviceId, $accountingDate) {
            $entry = DB::table('journal_entries')->where('id', $entryId)->lockForUpdate()->first();
            if ($entry === null) {
                throw LedgerException::of('El asiento no existe.', 'not_found');
            }
            if ($entry->status === 'confirmed') {
                return [
                    'entry_id' => $entryId, 'number' => $entry->number, 'accounting_date' => $entry->accounting_date,
                    'period_id' => $entry->period_id, 'period_shifted' => $entry->accounting_date_requested !== null,
                ];
            }

            $lines = DB::table('journal_lines')->where('entry_id', $entryId)->orderBy('line_no')->get();
            $errors = $this->validator->validate(
                ['space_id' => $entry->space_id, 'currency' => $entry->currency, 'kind' => $entry->kind],
                $lines->map(fn ($l) => [
                    'ledger_account_id' => $l->ledger_account_id, 'debit' => (string) $l->debit,
                    'credit' => (string) $l->credit, 'category_id' => $l->category_id,
                ])->all(),
                $this->loadAccounts($lines->pluck('ledger_account_id')->unique()->all()),
                $this->loadCategories($lines->pluck('category_id')->filter()->unique()->all()),
            );
            if ($errors !== []) {
                throw new LedgerException($errors, 'validation_failed');
            }

            $requested = CarbonImmutable::parse($accountingDate ?? $entry->occurred_at, 'UTC')->startOfDay();
            [$period, $effective] = $this->resolvePeriod($entry->space_id, $requested);
            $shifted = !$effective->equalTo($requested);

            $number = $this->nextNumber($entry->space_id, $effective->year);
            $now = $this->now();

            DB::table('journal_entries')->where('id', $entryId)->update([
                'status' => 'confirmed',
                'number' => $number,
                'confirmed_at' => $now,
                'accounting_date' => $effective->toDateString(),
                'accounting_date_requested' => $shifted ? $requested->toDateString() : null,
                'period_id' => $period,
                'validation_errors' => null,
                'version' => DB::raw('version + 1'),
                'updated_at' => $now,
            ]);

            $this->audit->log('journal_entry', $entryId, 'confirmed', $entry->space_id, $userId, $deviceId,
                before: ['status' => $entry->status],
                after: ['status' => 'confirmed', 'number' => $number, 'accounting_date' => $effective->toDateString()],
                reason: $shifted ? "Período cerrado: se imputó al primer período abierto ({$effective->toDateString()})" : null,
            );

            return [
                'entry_id' => $entryId, 'number' => $number, 'accounting_date' => $effective->toDateString(),
                'period_id' => $period, 'period_shifted' => $shifted,
            ];
        });
    }

    /**
     * Reversión total o parcial. Nunca modifica el asiento original (spec §6.3).
     *
     * @param array<string,string>|null $amountsByLine apunte original (id) => importe a revertir; null = todo lo pendiente
     * @return array{entry_id:string,number:string,accounting_date:string,period_id:string,period_shifted:bool}
     */
    public function reverse(string $entryId, string $reason, string $userId, ?string $deviceId = null, ?array $amountsByLine = null, ?string $accountingDate = null, ?string $newEntryId = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw LedgerException::of('El motivo de la reversión es obligatorio.', 'reason_required');
        }

        return DB::transaction(function () use ($entryId, $reason, $userId, $deviceId, $amountsByLine, $accountingDate, $newEntryId) {
            $orig = DB::table('journal_entries')->where('id', $entryId)->lockForUpdate()->first();
            if ($orig === null || $orig->status !== 'confirmed') {
                throw LedgerException::of('Solo se pueden revertir asientos confirmados.', 'not_confirmed');
            }
            if ($orig->kind === 'reversal') {
                throw LedgerException::of('No se revierte una reversión: registre un nuevo asiento.', 'reversal_of_reversal');
            }

            $lines = DB::table('journal_lines')->where('entry_id', $entryId)->orderBy('line_no')->get();
            $reversedByLine = DB::table('reversal_allocations')
                ->whereIn('original_line_id', $lines->pluck('id'))
                ->selectRaw('original_line_id, SUM(amount) as total')
                ->groupBy('original_line_id')->pluck('total', 'original_line_id');

            $original = [];
            $remaining = [];
            foreach ($lines as $l) {
                $original[] = [
                    'id' => $l->id, 'ledger_account_id' => $l->ledger_account_id,
                    'debit' => (string) $l->debit, 'credit' => (string) $l->credit, 'category_id' => $l->category_id,
                ];
                $amount = Decimal::isZero((string) $l->debit) ? (string) $l->credit : (string) $l->debit;
                $remaining[$l->id] = Decimal::sub($amount, (string) ($reversedByLine[$l->id] ?? '0'));
            }

            try {
                $rev = Posting::reversalLines($original, $remaining, $amountsByLine);
            } catch (\InvalidArgumentException $e) {
                throw LedgerException::of($e->getMessage(), 'invalid_reversal');
            }

            $id = $this->createEntry([
                'entry' => [
                    'space_id' => $orig->space_id,
                    'currency' => $orig->currency,
                    'kind' => 'reversal',
                    'occurred_at' => $this->now(),
                    'description' => "Reversión de {$orig->number}: {$reason}",
                    'reverses_entry_id' => $entryId,
                    'reversal_reason' => $reason,
                ],
                'lines' => $rev['lines'],
            ], $userId, $deviceId, operationId: $orig->operation_id, entryId: $newEntryId);

            foreach ($rev['allocations'] as $lineId => $amount) {
                DB::table('reversal_allocations')->insert([
                    'id' => (string) Str::uuid(), 'reversal_entry_id' => $id, 'original_line_id' => $lineId,
                    'amount' => $amount, 'created_at' => $this->now(),
                ]);
            }

            $result = $this->confirm($id, $userId, $deviceId, $accountingDate ?? now()->utc()->toDateString());

            $this->audit->log('journal_entry', $entryId, 'reversed', $orig->space_id, $userId, $deviceId,
                reason: $reason, related: ['reversal_entry_id' => $id, 'reversal_number' => $result['number'], 'partial' => $amountsByLine !== null]);

            return $result;
        });
    }

    // ---------------------------------------------------------------- internos

    /** @return array{0:string,1:CarbonImmutable} id del período y fecha contable efectiva */
    private function resolvePeriod(string $spaceId, CarbonImmutable $date): array
    {
        $cursor = $date->startOfMonth();
        for ($i = 0; $i <= self::MAX_PERIOD_SHIFT_MONTHS; $i++) {
            $period = DB::table('accounting_periods')
                ->where('space_id', $spaceId)->where('year', $cursor->year)->where('month', $cursor->month)
                ->lockForUpdate()->first();

            if ($period === null) {
                $id = (string) Str::uuid();
                $now = $this->now();
                DB::table('accounting_periods')->insert([
                    'id' => $id, 'space_id' => $spaceId, 'year' => $cursor->year, 'month' => $cursor->month,
                    'status' => 'open', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $period = (object) ['id' => $id, 'status' => 'open'];
            }

            if ($period->status === 'open') {
                // Si no hubo desplazamiento se conserva el día; si se desplazó, es el primer día del período abierto.
                return [$period->id, $i === 0 ? $date : $cursor];
            }
            $cursor = $cursor->addMonth();
        }

        throw LedgerException::of('No hay períodos abiertos disponibles para imputar el asiento.', 'no_open_period');
    }

    private function nextNumber(string $spaceId, int $year): string
    {
        DB::table('entry_sequences')->insertOrIgnore(['space_id' => $spaceId, 'year' => $year, 'last_number' => 0]);

        $row = DB::table('entry_sequences')->where('space_id', $spaceId)->where('year', $year)->lockForUpdate()->first();
        $next = ((int) $row->last_number) + 1;
        DB::table('entry_sequences')->where('space_id', $spaceId)->where('year', $year)->update(['last_number' => $next]);

        $code = DB::table('spaces')->where('id', $spaceId)->value('code');

        return sprintf('%s-%d-%06d', $code, $year, $next);
    }

    /** @return array<string,array{space_id:string,type:string,is_group:bool,is_active:bool,currency:?string}> */
    private function loadAccounts(array $ids): array
    {
        return DB::table('ledger_accounts')->whereIn('id', $ids)->get()->mapWithKeys(fn ($a) => [
            $a->id => [
                'space_id' => $a->space_id, 'type' => $a->type, 'is_group' => (bool) $a->is_group,
                'is_active' => (bool) $a->is_active, 'currency' => $a->currency,
            ],
        ])->all();
    }

    /** @return array<string,array{space_id:?string,kind:string,is_active:bool}> */
    private function loadCategories(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('categories')->whereIn('id', $ids)->get()->mapWithKeys(fn ($c) => [
            $c->id => ['space_id' => $c->space_id, 'kind' => $c->kind, 'is_active' => (bool) $c->is_active],
        ])->all();
    }

    private function now(): string
    {
        return $this->fmt(CarbonImmutable::now('UTC'));
    }

    private function fmt(CarbonImmutable $d): string
    {
        return $d->utc()->format('Y-m-d H:i:s.v');
    }
}
