<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\LedgerException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sincronización híbrida: el dispositivo encola operaciones con un id propio (UUID) y las envía en orden.
 *
 *  - Idempotencia: el id de la operación es PK de sync_operations; reenviar devuelve el resultado guardado.
 *  - Una operación aplicada es atómica: o quedan todos sus asientos o ninguno.
 *  - Rechazo (validación contable) y conflicto (versión base distinta) se registran y se informan; no se aplican.
 *  - Los conflictos conservan ambas versiones y esperan resolución explícita.
 */
final class SyncService
{
    private const PAGE_SIZE = 500;

    public function __construct(private readonly OperationService $operations) {}

    /**
     * @param list<array<string,mixed>> $ops
     * @return list<array<string,mixed>>
     */
    public function push(string $userId, string $deviceId, array $ops): array
    {
        $results = [];
        foreach ($ops as $op) {
            $results[] = $this->pushOne($userId, $deviceId, $op);
        }

        return $results;
    }

    /** @param array<string,mixed> $op */
    private function pushOne(string $userId, string $deviceId, array $op): array
    {
        $opId = (string) ($op['id'] ?? '');
        if (!Str::isUuid($opId) || empty($op['entity']) || empty($op['action']) || !is_array($op['payload'] ?? null)) {
            return ['id' => $opId, 'status' => 'rejected', 'error' => 'Operación mal formada.', 'duplicate' => false];
        }

        $existing = DB::table('sync_operations')->where('id', $opId)->first();
        if ($existing !== null) {
            return $this->stored($existing, true);
        }

        $now = now()->utc()->format('Y-m-d H:i:s.v');
        $base = [
            'id' => $opId, 'user_id' => $userId, 'device_id' => $deviceId,
            'space_id' => $op['payload']['space_id'] ?? $op['payload']['from_space_id'] ?? null,
            'entity' => $op['entity'], 'entity_id' => $op['payload']['id'] ?? null, 'action' => $op['action'],
            'payload' => json_encode($op['payload'], JSON_THROW_ON_ERROR),
            'base_version' => $op['base_version'] ?? null,
            'client_created_at' => CarbonImmutable::parse($op['client_created_at'] ?? 'now')->utc()->format('Y-m-d H:i:s.v'),
            'received_at' => $now,
        ];

        try {
            // La fila de sync_operations entra en la misma transacción que los asientos: si algo falla, no queda nada a medias.
            return DB::transaction(function () use ($userId, $deviceId, $op, $base, $opId) {
                DB::table('sync_operations')->insert($base + ['status' => 'applied']);
                $result = $this->dispatch($userId, $deviceId, $op, $opId);
                DB::table('sync_operations')->where('id', $opId)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);

                return ['id' => $opId, 'status' => 'applied', 'result' => $result, 'duplicate' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Carrera con otro envío de la misma operación: devolvemos lo que ganó.
            $won = DB::table('sync_operations')->where('id', $opId)->first();
            if ($won !== null) {
                return $this->stored($won, true);
            }

            return $this->record($base, 'rejected', null, 'Clave duplicada (id de entidad ya existente).');
        } catch (ConflictException $c) {
            $this->record($base, 'conflict', null, $c->getMessage());
            DB::table('sync_conflicts')->insert([
                'id' => (string) Str::uuid(), 'sync_operation_id' => $opId, 'space_id' => $base['space_id'],
                'entity' => $base['entity'], 'entity_id' => $base['entity_id'],
                'server_state' => json_encode($c->serverState, JSON_THROW_ON_ERROR),
                'client_state' => $base['payload'], 'status' => 'open', 'created_at' => $now,
            ]);

            return ['id' => $opId, 'status' => 'conflict', 'error' => $c->getMessage(), 'server_state' => $c->serverState, 'duplicate' => false];
        } catch (LedgerException $e) {
            return $this->record($base, 'rejected', null, implode(' | ', $e->errors), $e->errorCode);
        } catch (Throwable $e) {
            report($e);

            return $this->record($base, 'rejected', null, 'Error interno al aplicar la operación.', 'internal_error');
        }
    }

    /** @return array<string,mixed> */
    private function dispatch(string $userId, string $deviceId, array $op, string $opId): array
    {
        $p = $op['payload'];

        return match ($op['entity'] . ':' . $op['action']) {
            'financial_account:create' => $this->operations->apply($userId, $deviceId, 'account_create', $p, $opId),
            'category:create' => $this->operations->apply($userId, $deviceId, 'category_create', $p, $opId),
            'financial_account:update' => $this->operations->updateEntity($userId, $deviceId, 'financial_account', (string) ($p['id'] ?? ''), (array) ($p['changes'] ?? []), $op['base_version'] ?? null),
            'category:update' => $this->operations->updateEntity($userId, $deviceId, 'category', (string) ($p['id'] ?? ''), (array) ($p['changes'] ?? []), $op['base_version'] ?? null),
            default => $op['entity'] === 'operation'
                ? $this->operations->apply($userId, $deviceId, (string) $op['action'], $p, $opId)
                : throw LedgerException::of("Operación de sincronización no soportada: {$op['entity']}:{$op['action']}", 'unsupported_kind'),
        };
    }

    /** Registra una operación no aplicada (fuera de la transacción revertida). */
    private function record(array $base, string $status, ?array $result, string $error, ?string $code = null): array
    {
        DB::table('sync_operations')->updateOrInsert(['id' => $base['id']], $base + ['status' => $status, 'error' => $error]);

        return ['id' => $base['id'], 'status' => $status, 'error' => $error, 'code' => $code, 'duplicate' => false];
    }

    private function stored(object $row, bool $duplicate): array
    {
        return [
            'id' => $row->id, 'status' => $row->status,
            'result' => $row->result ? json_decode($row->result, true) : null,
            'error' => $row->error, 'duplicate' => $duplicate,
        ];
    }

    /**
     * Cambios del servidor desde el cursor. Se solapan unos segundos con el cursor anterior a propósito:
     * el cliente hace upsert por UUID, así que recibir un registro dos veces es inofensivo y evita perder escrituras tardías.
     *
     * @return array<string,mixed>
     */
    public function pull(string $userId, ?string $since): array
    {
        $serverTime = CarbonImmutable::now('UTC');
        $cursor = $since !== null ? CarbonImmutable::parse($since, 'UTC')->subSeconds(2) : CarbonImmutable::createFromTimestampUTC(0);
        $cursorStr = $cursor->format('Y-m-d H:i:s.v');

        $spaces = DB::table('spaces')->where('user_id', $userId)->get();
        $spaceIds = $spaces->pluck('id')->all();

        $entries = DB::table('journal_entries')->whereIn('space_id', $spaceIds)->where('status', 'confirmed')
            ->where('updated_at', '>', $cursorStr)->orderBy('updated_at')->orderBy('id')->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $entries->count() > self::PAGE_SIZE;
        $entries = $entries->take(self::PAGE_SIZE);

        $nextCursor = $hasMore ? CarbonImmutable::parse($entries->last()->updated_at, 'UTC') : $serverTime;

        return [
            'server_time' => $serverTime->format('Y-m-d\TH:i:s.v\Z'),
            'next_cursor' => $nextCursor->format('Y-m-d\TH:i:s.v\Z'),
            'has_more' => $hasMore,
            'spaces' => $spaces->map(fn ($s) => (array) $s)->all(),
            'currencies' => DB::table('currencies')->get()->map(fn ($r) => (array) $r)->all(),
            'financial_accounts' => $this->since('financial_accounts', $spaceIds, $cursorStr),
            'financial_account_currencies' => $this->since('financial_account_currencies', $spaceIds, $cursorStr),
            'ledger_accounts' => $this->since('ledger_accounts', $spaceIds, $cursorStr),
            'categories' => DB::table('categories')->where(fn ($q) => $q->whereNull('space_id')->orWhereIn('space_id', $spaceIds))
                ->where('updated_at', '>', $cursorStr)->get()->map(fn ($r) => (array) $r)->all(),
            'entries' => $entries->map(fn ($e) => (array) $e + [
                'lines' => DB::table('journal_lines')->where('entry_id', $e->id)->orderBy('line_no')->get()->map(fn ($l) => (array) $l)->all(),
            ])->values()->all(),
            'exchange_rates' => DB::table('exchange_rates')->where('created_at', '>', $cursorStr)->get()->map(fn ($r) => (array) $r)->all(),
            'tombstones' => DB::table('tombstones')->where(fn ($q) => $q->whereNull('space_id')->orWhereIn('space_id', $spaceIds))
                ->where('deleted_at', '>', $cursorStr)->get()->map(fn ($r) => (array) $r)->all(),
            'open_conflicts' => DB::table('sync_conflicts')->whereIn('space_id', $spaceIds)->where('status', 'open')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function since(string $table, array $spaceIds, string $cursor): array
    {
        return DB::table($table)->whereIn('space_id', $spaceIds)->where('updated_at', '>', $cursor)->get()->map(fn ($r) => (array) $r)->all();
    }
}
