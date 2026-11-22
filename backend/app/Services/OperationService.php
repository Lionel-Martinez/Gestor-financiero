<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ledger\Posting;
use App\Exceptions\ConflictException;
use App\Exceptions\LedgerException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Casos de uso financieros. Traduce una operación de alto nivel (la misma que la app encola offline)
 * en asientos balanceados y los confirma. El llamador debe ejecutarlo dentro de una transacción:
 * si algo falla, no queda ningún asiento a medias.
 */
final class OperationService
{
    private const LIQUID_KINDS = ['bank', 'wallet', 'virtual', 'cash', 'usd_cash', 'digital_usd', 'other'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $coa,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $p payload
     * @return array<string,mixed> resultado serializable (ids y números de asiento)
     */
    public function apply(string $userId, string $deviceId, string $kind, array $p, ?string $syncOpId = null): array
    {
        try {
            return match ($kind) {
                'income', 'expense' => $this->incomeOrExpense($userId, $deviceId, $kind, $p, $syncOpId),
                'transfer' => $this->transfer($userId, $deviceId, $p, $syncOpId),
                'interspace_transfer' => $this->interSpaceTransfer($userId, $deviceId, $p, $syncOpId),
                'conversion' => $this->conversion($userId, $deviceId, $p, $syncOpId),
                'account_create' => $this->createAccount($userId, $deviceId, $p),
                'category_create' => $this->createCategory($userId, $deviceId, $p),
                'reverse' => $this->reverse($userId, $deviceId, $p, $syncOpId),
                default => throw LedgerException::of("Tipo de operación no soportado: {$kind}", 'unsupported_kind'),
            };
        } catch (InvalidArgumentException $e) {
            throw LedgerException::of($e->getMessage(), 'invalid_payload');
        }
    }

    /**
     * Cambios no financieros (nombre, alias, activación). Conflicto si la versión base no coincide:
     * nunca se sobrescribe automáticamente (spec §9.3).
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public function updateEntity(string $userId, string $deviceId, string $entity, string $id, array $changes, ?int $baseVersion): array
    {
        [$table, $allowed] = match ($entity) {
            'financial_account' => ['financial_accounts', ['name', 'alias', 'is_active', 'is_archived']],
            'category' => ['categories', ['name', 'is_active', 'group_key']],
            default => throw LedgerException::of("Entidad no editable: {$entity}", 'unsupported_entity'),
        };

        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        if ($row === null) {
            throw LedgerException::of('El registro no existe.', 'not_found');
        }
        $this->assertSpaceAccess($userId, $row->space_id);

        if ($baseVersion !== null && (int) $row->version !== $baseVersion) {
            throw new ConflictException((array) $row);
        }

        $set = array_intersect_key($changes, array_flip($allowed));
        if ($set === []) {
            throw LedgerException::of('No hay campos editables en el cambio.', 'nothing_to_update');
        }

        $now = now()->utc()->format('Y-m-d H:i:s.v');
        DB::table($table)->where('id', $id)->update([...$set, 'version' => $row->version + 1, 'updated_at' => $now]);

        // Desactivar/archivar deja tombstone para que los dispositivos offline reciban el evento.
        $action = isset($set['is_archived']) && $set['is_archived'] ? 'archive' : (array_key_exists('is_active', $set) && !$set['is_active'] ? 'deactivate' : null);
        if ($action !== null) {
            DB::table('tombstones')->updateOrInsert(
                ['entity' => $entity, 'entity_id' => $id],
                ['id' => (string) Str::uuid(), 'space_id' => $row->space_id, 'version' => $row->version + 1, 'action' => $action, 'deleted_at' => $now],
            );
        }

        $this->audit->log($entity, $id, 'updated', $row->space_id, $userId, $deviceId, before: (array) $row, after: $set);

        return ['id' => $id, 'version' => $row->version + 1];
    }

    // ------------------------------------------------------------ operaciones

    private function incomeOrExpense(string $userId, string $deviceId, string $kind, array $p, ?string $syncOpId): array
    {
        $this->need($p, ['space_id', 'financial_account_id', 'currency', 'amount', 'category_id', 'occurred_at']);
        $this->assertSpaceAccess($userId, $p['space_id']);

        $funds = $this->fundsAccount($p['space_id'], $p['financial_account_id'], $p['currency']);
        $posting = $kind === 'income'
            ? Posting::income($p['space_id'], $p['currency'], $funds, $this->coa->system($p['space_id'], 'income_general'), $p['amount'], $p['category_id'], $p['occurred_at'], $p['description'] ?? '')
            : Posting::expense($p['space_id'], $p['currency'], $funds, $this->coa->system($p['space_id'], 'expense_general'), $p['amount'], $p['category_id'], $p['occurred_at'], $p['description'] ?? '');

        $operationId = $this->operation($userId, $deviceId, $kind, $p['occurred_at']);
        $id = $this->ledger->createEntry($posting, $userId, $deviceId, $operationId, $syncOpId, $p['entry_id'] ?? null);
        $conf = $this->ledger->confirm($id, $userId, $deviceId);

        return ['operation_id' => $operationId, 'entries' => [['id' => $id, 'number' => $conf['number']]]];
    }

    private function transfer(string $userId, string $deviceId, array $p, ?string $syncOpId): array
    {
        $this->need($p, ['space_id', 'from_financial_account_id', 'to_financial_account_id', 'currency', 'amount', 'occurred_at']);
        $this->assertSpaceAccess($userId, $p['space_id']);

        $from = $this->fundsAccount($p['space_id'], $p['from_financial_account_id'], $p['currency']);
        $to = $this->fundsAccount($p['space_id'], $p['to_financial_account_id'], $p['currency']);
        $posting = Posting::ownTransfer($p['space_id'], $p['currency'], $from, $to, $p['amount'], $p['occurred_at'], $p['description'] ?? '');

        $operationId = $this->operation($userId, $deviceId, 'transfer', $p['occurred_at']);
        $id = $this->ledger->createEntry($posting, $userId, $deviceId, $operationId, $syncOpId, $p['entry_id'] ?? null);
        $conf = $this->ledger->confirm($id, $userId, $deviceId);

        return ['operation_id' => $operationId, 'entries' => [['id' => $id, 'number' => $conf['number']]]];
    }

    private function interSpaceTransfer(string $userId, string $deviceId, array $p, ?string $syncOpId): array
    {
        $this->need($p, ['from_space_id', 'from_financial_account_id', 'to_space_id', 'to_financial_account_id', 'currency', 'amount', 'occurred_at']);
        $this->assertSpaceAccess($userId, $p['from_space_id']);
        $this->assertSpaceAccess($userId, $p['to_space_id']);

        $t = Posting::interSpaceTransfer(
            $p['from_space_id'], $this->fundsAccount($p['from_space_id'], $p['from_financial_account_id'], $p['currency']), $this->coa->system($p['from_space_id'], 'interspace_clearing'),
            $p['to_space_id'], $this->fundsAccount($p['to_space_id'], $p['to_financial_account_id'], $p['currency']), $this->coa->system($p['to_space_id'], 'interspace_clearing'),
            $p['currency'], $p['amount'], $p['occurred_at'], $p['description'] ?? '',
        );

        // Ambos asientos comparten operation_id: identificador global de la transferencia entre espacios.
        $operationId = $this->operation($userId, $deviceId, 'interspace_transfer', $p['occurred_at']);
        $out = $this->ledger->createEntry($t['out'], $userId, $deviceId, $operationId, $syncOpId, $p['entry_id_out'] ?? null);
        $in = $this->ledger->createEntry($t['in'], $userId, $deviceId, $operationId, null, $p['entry_id_in'] ?? null);
        $cOut = $this->ledger->confirm($out, $userId, $deviceId);
        $cIn = $this->ledger->confirm($in, $userId, $deviceId);

        $this->audit->log('operation', $operationId, 'interspace_transfer', null, $userId, $deviceId,
            after: ['amount' => $p['amount'], 'currency' => $p['currency']], related: ['out' => $cOut['number'], 'in' => $cIn['number']]);

        return ['operation_id' => $operationId, 'entries' => [['id' => $out, 'number' => $cOut['number']], ['id' => $in, 'number' => $cIn['number']]]];
    }

    private function conversion(string $userId, string $deviceId, array $p, ?string $syncOpId): array
    {
        $this->need($p, ['space_id', 'from_financial_account_id', 'from_currency', 'from_amount', 'to_financial_account_id', 'to_currency', 'to_amount', 'occurred_at']);
        $this->assertSpaceAccess($userId, $p['space_id']);
        $s = $p['space_id'];

        $c = Posting::conversion(
            $s,
            $p['from_currency'], $this->fundsAccount($s, $p['from_financial_account_id'], $p['from_currency']), $this->coa->system($s, 'fx_bridge', $p['from_currency']), $p['from_amount'],
            $p['to_currency'], $this->fundsAccount($s, $p['to_financial_account_id'], $p['to_currency']), $this->coa->system($s, 'fx_bridge', $p['to_currency']), $p['to_amount'],
            $p['occurred_at'], $p['description'] ?? '',
            $p['commission_amount'] ?? null, isset($p['commission_amount']) ? $this->coa->system($s, 'fee') : null,
        );

        $operationId = $this->operation($userId, $deviceId, 'conversion', $p['occurred_at']);
        $src = $this->ledger->createEntry($c['source'], $userId, $deviceId, $operationId, $syncOpId);
        $dst = $this->ledger->createEntry($c['target'], $userId, $deviceId, $operationId);
        $fee = $c['commission'] !== null ? $this->ledger->createEntry($c['commission'], $userId, $deviceId, $operationId) : null;

        $cs = $this->ledger->confirm($src, $userId, $deviceId);
        $cd = $this->ledger->confirm($dst, $userId, $deviceId);
        $cf = $fee !== null ? $this->ledger->confirm($fee, $userId, $deviceId) : null;

        DB::table('fx_conversions')->insert([
            'id' => (string) Str::uuid(), 'operation_id' => $operationId, 'space_id' => $s,
            'source_entry_id' => $src, 'target_entry_id' => $dst, 'commission_entry_id' => $fee,
            'from_currency' => $p['from_currency'], 'from_amount' => $p['from_amount'],
            'to_currency' => $p['to_currency'], 'to_amount' => $p['to_amount'],
            'rate' => $c['rate'], 'rate_type' => $p['rate_type'] ?? null, 'rate_source' => $p['rate_source'] ?? null,
            'rate_quoted_at' => isset($p['rate_quoted_at']) ? CarbonImmutable::parse($p['rate_quoted_at'])->utc()->format('Y-m-d H:i:s.v') : null,
            'exchange_rate_id' => $p['exchange_rate_id'] ?? null,
            'created_at' => now()->utc()->format('Y-m-d H:i:s.v'),
        ]);

        return [
            'operation_id' => $operationId, 'rate' => $c['rate'],
            'entries' => array_values(array_filter([
                ['id' => $src, 'number' => $cs['number']], ['id' => $dst, 'number' => $cd['number']],
                $cf ? ['id' => $fee, 'number' => $cf['number']] : null,
            ])),
        ];
    }

    private function createAccount(string $userId, string $deviceId, array $p): array
    {
        $this->need($p, ['space_id', 'name', 'kind', 'currencies']);
        $this->assertSpaceAccess($userId, $p['space_id']);
        if (!is_array($p['currencies']) || $p['currencies'] === []) {
            throw LedgerException::of('La cuenta necesita al menos una moneda.', 'invalid_payload');
        }

        $id = $p['id'] ?? (string) Str::uuid();
        $now = now()->utc()->format('Y-m-d H:i:s.v');
        $isCard = $p['kind'] === 'credit_card';

        DB::table('financial_accounts')->insert([
            'id' => $id, 'space_id' => $p['space_id'], 'name' => $p['name'], 'alias' => $p['alias'] ?? null,
            'kind' => $p['kind'], 'is_active' => true, 'is_archived' => false, 'version' => 1,
            'origin_device_id' => $deviceId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $opened = [];
        foreach ($p['currencies'] as $cur) {
            $currency = $cur['currency'] ?? throw LedgerException::of('Falta la moneda.', 'invalid_payload');
            // El dispositivo genera los UUID (también sin conexión); el servidor los respeta si vienen.
            $ledgerId = $cur['ledger_account_id'] ?? (string) Str::uuid();
            DB::table('ledger_accounts')->insert([
                'id' => $ledgerId, 'space_id' => $p['space_id'],
                'code' => 'FA-' . substr($id, 0, 8) . '-' . $currency, 'name' => "{$p['name']} ({$currency})",
                // Las tarjetas de crédito son pasivos independientes: no representan dinero disponible.
                'type' => $isCard ? 'liability' : 'asset', 'currency' => $currency, 'is_group' => false, 'is_active' => true,
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('financial_account_currencies')->insert([
                'id' => $cur['fa_currency_id'] ?? (string) Str::uuid(), 'space_id' => $p['space_id'], 'financial_account_id' => $id,
                'currency' => $currency, 'ledger_account_id' => $ledgerId, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $balance = (string) ($cur['opening_balance'] ?? '0');
            if (!\App\Domain\Decimal::isZero($balance)) {
                // El saldo inicial genera un asiento de apertura contra patrimonio inicial; nunca es un ingreso.
                $posting = Posting::opening($p['space_id'], $currency, $ledgerId, $isCard ? 'liability' : 'asset',
                    $this->coa->system($p['space_id'], 'equity_opening'), $balance, $p['occurred_at'] ?? $now);
                $entryId = $this->ledger->createEntry($posting, $userId, $deviceId, entryId: $cur['opening_entry_id'] ?? null);
                $conf = $this->ledger->confirm($entryId, $userId, $deviceId);
                $opened[] = ['id' => $entryId, 'number' => $conf['number'], 'currency' => $currency];
            }
        }

        $this->audit->log('financial_account', $id, 'created', $p['space_id'], $userId, $deviceId, after: ['name' => $p['name'], 'kind' => $p['kind']]);

        return ['id' => $id, 'version' => 1, 'opening_entries' => $opened];
    }

    private function createCategory(string $userId, string $deviceId, array $p): array
    {
        $this->need($p, ['space_id', 'name', 'kind']);
        $this->assertSpaceAccess($userId, $p['space_id']);
        if (!in_array($p['kind'], ['income', 'expense', 'both'], true)) {
            throw LedgerException::of('Tipo de categoría inválido.', 'invalid_payload');
        }

        if (!empty($p['parent_id'])) {
            $parent = DB::table('categories')->where('id', $p['parent_id'])->first();
            if ($parent === null || ($parent->space_id !== null && $parent->space_id !== $p['space_id'])) {
                throw LedgerException::of('La categoría padre no existe o pertenece a otro espacio.', 'invalid_parent');
            }
            if ($parent->parent_id !== null) {
                throw LedgerException::of('Las categorías admiten como máximo dos niveles.', 'max_depth');
            }
        }

        $id = $p['id'] ?? (string) Str::uuid();
        $now = now()->utc()->format('Y-m-d H:i:s.v');
        DB::table('categories')->insert([
            'id' => $id, 'space_id' => $p['space_id'], 'parent_id' => $p['parent_id'] ?? null, 'name' => $p['name'],
            'kind' => $p['kind'], 'group_key' => $p['group_key'] ?? null, 'is_active' => true, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->audit->log('category', $id, 'created', $p['space_id'], $userId, $deviceId, after: ['name' => $p['name']]);

        return ['id' => $id, 'version' => 1];
    }

    private function reverse(string $userId, string $deviceId, array $p, ?string $syncOpId): array
    {
        $this->need($p, ['entry_id', 'reason']);
        $spaceId = DB::table('journal_entries')->where('id', $p['entry_id'])->value('space_id');
        if ($spaceId === null) {
            throw LedgerException::of('El asiento no existe.', 'not_found');
        }
        $this->assertSpaceAccess($userId, $spaceId);

        $r = $this->ledger->reverse($p['entry_id'], $p['reason'], $userId, $deviceId, $p['amounts'] ?? null, $p['accounting_date'] ?? null, $p['reversal_entry_id'] ?? null);

        return ['entries' => [['id' => $r['entry_id'], 'number' => $r['number']]]];
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<string> $keys */
    private function need(array $p, array $keys): void
    {
        $missing = array_values(array_filter($keys, fn ($k) => !array_key_exists($k, $p) || $p[$k] === null || $p[$k] === ''));
        if ($missing !== []) {
            throw LedgerException::of('Faltan campos: ' . implode(', ', $missing), 'invalid_payload');
        }
    }

    private function assertSpaceAccess(string $userId, string $spaceId): void
    {
        if (!DB::table('spaces')->where('id', $spaceId)->where('user_id', $userId)->exists()) {
            throw LedgerException::of('Espacio inexistente o sin acceso.', 'forbidden');
        }
    }

    private function fundsAccount(string $spaceId, string $financialAccountId, string $currency): string
    {
        $row = DB::table('financial_account_currencies as c')
            ->join('financial_accounts as a', fn ($j) => $j->on('a.id', '=', 'c.financial_account_id')->on('a.space_id', '=', 'c.space_id'))
            ->where('c.space_id', $spaceId)->where('a.id', $financialAccountId)->where('c.currency', $currency)
            ->select('c.ledger_account_id', 'a.is_active', 'a.is_archived', 'a.kind')
            ->first();

        if ($row === null) {
            throw LedgerException::of("La cuenta no existe en este espacio o no opera en {$currency}.", 'account_not_found');
        }
        if (!$row->is_active || $row->is_archived) {
            throw LedgerException::of('La cuenta está inactiva o archivada.', 'account_inactive');
        }

        return $row->ledger_account_id;
    }

    private function operation(string $userId, string $deviceId, string $kind, string $occurredAt): string
    {
        $id = (string) Str::uuid();
        DB::table('operations')->insert([
            'id' => $id, 'user_id' => $userId, 'kind' => $kind, 'device_id' => $deviceId,
            'occurred_at' => CarbonImmutable::parse($occurredAt)->utc()->format('Y-m-d H:i:s.v'),
            'created_at' => now()->utc()->format('Y-m-d H:i:s.v'),
        ]);

        return $id;
    }

    /** Tipos considerados dinero líquido para el "disponible real". */
    public static function liquidKinds(): array
    {
        return self::LIQUID_KINDS;
    }
}
