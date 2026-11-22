<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\LedgerException;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\LedgerService;
use App\Services\OperationService;
use Carbon\CarbonImmutable;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pruebas de integración del ledger. Requieren Laravel instalado (ver install.sh).
 * Ideal correrlas contra MySQL 8.4 (docker compose) para ejercitar también los triggers.
 */
final class LedgerFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $per;
    private string $lms;
    private string $device;
    private OperationService $ops;

    protected function setUp(): void
    {
        parent::setUp();
        (new BaseDataSeeder())->run();
        $this->user = User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'una-clave-larga-123']);
        $now = now()->utc()->format('Y-m-d H:i:s.v');
        foreach (['PER' => 'Personal', 'LMS' => 'LM Studio'] as $code => $name) {
            $id = (string) Str::uuid();
            DB::table('spaces')->insert(['id' => $id, 'user_id' => $this->user->id, 'code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            $code === 'PER' ? $this->per = $id : $this->lms = $id;
        }
        $this->device = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $this->device, 'user_id' => $this->user->id, 'name' => 'test', 'platform' => 'other', 'created_at' => $now, 'updated_at' => $now]);
        $this->ops = app(OperationService::class);
    }

    private function account(string $space, string $name, string $kind, string $currency, string $opening): string
    {
        return $this->ops->apply($this->user->id, $this->device, 'account_create', [
            'space_id' => $space, 'name' => $name, 'kind' => $kind, 'currencies' => [['currency' => $currency, 'opening_balance' => $opening]],
        ])['id'];
    }

    private function category(string $key): string
    {
        return DB::table('categories')->whereNull('space_id')->where('group_key', $key)->value('id');
    }

    private function summary(array $spaces): array
    {
        return app(DashboardService::class)->summary($this->user->id, $spaces, CarbonImmutable::now('UTC')->startOfMonth(), CarbonImmutable::now('UTC')->endOfMonth(), 'ARS', 'oficial');
    }

    public function test_apertura_no_es_ingreso_y_gasto_reduce_disponible(): void
    {
        $bank = $this->account($this->per, 'Banco', 'bank', 'ARS', '10000');
        $s = $this->summary([$this->per]);
        $this->assertSame('10000.0000', $s['available']['by_currency']['ARS']);
        $this->assertSame([], $s['income']['by_currency']);           // el saldo inicial no es un ingreso

        $r = $this->ops->apply($this->user->id, $this->device, 'expense', [
            'space_id' => $this->per, 'financial_account_id' => $bank, 'currency' => 'ARS', 'amount' => '2500.50',
            'category_id' => $this->category('alimentacion'), 'occurred_at' => now()->toIso8601String(),
        ]);
        $this->assertMatchesRegularExpression('/^PER-\d{4}-000002$/', $r['entries'][0]['number']);   // la apertura fue el 000001
        $s = $this->summary([$this->per]);
        $this->assertSame('7499.5000', $s['available']['by_currency']['ARS']);
        $this->assertSame('2500.5000', $s['expense']['by_currency']['ARS']);
    }

    public function test_transferencia_propia_no_altera_ingresos_ni_gastos(): void
    {
        $a = $this->account($this->per, 'Banco', 'bank', 'ARS', '1000');
        $b = $this->account($this->per, 'Efectivo', 'cash', 'ARS', '0');
        $this->ops->apply($this->user->id, $this->device, 'transfer', [
            'space_id' => $this->per, 'from_financial_account_id' => $a, 'to_financial_account_id' => $b,
            'currency' => 'ARS', 'amount' => '400', 'occurred_at' => now()->toIso8601String(),
        ]);
        $s = $this->summary([$this->per]);
        $this->assertSame([], $s['income']['by_currency']);
        $this->assertSame([], $s['expense']['by_currency']);
        $this->assertSame('1000.0000', $s['available']['by_currency']['ARS']);
    }

    public function test_transferencia_entre_espacios_se_consolida_sin_duplicar(): void
    {
        $p = $this->account($this->per, 'Banco P', 'bank', 'ARS', '5000');
        $l = $this->account($this->lms, 'Banco L', 'bank', 'ARS', '0');
        $r = $this->ops->apply($this->user->id, $this->device, 'interspace_transfer', [
            'from_space_id' => $this->per, 'from_financial_account_id' => $p, 'to_space_id' => $this->lms,
            'to_financial_account_id' => $l, 'currency' => 'ARS', 'amount' => '1500', 'occurred_at' => now()->toIso8601String(),
        ]);
        $this->assertCount(2, $r['entries']);

        $general = $this->summary([$this->per, $this->lms]);
        $this->assertSame('5000.0000', $general['available']['by_currency']['ARS']);   // el total no cambia
        $this->assertSame([], $general['income']['by_currency']);                      // no es ingreso
        $this->assertSame([], $general['expense']['by_currency']);                     // ni gasto
        $this->assertSame('3500.0000', $this->summary([$this->per])['available']['by_currency']['ARS']);
        $this->assertSame('1500.0000', $this->summary([$this->lms])['available']['by_currency']['ARS']);
    }

    public function test_reversion_total_y_parcial_sin_tocar_el_original(): void
    {
        $bank = $this->account($this->per, 'Banco', 'bank', 'ARS', '1000');
        $r = $this->ops->apply($this->user->id, $this->device, 'expense', [
            'space_id' => $this->per, 'financial_account_id' => $bank, 'currency' => 'ARS', 'amount' => '100',
            'category_id' => $this->category('ocio'), 'occurred_at' => now()->toIso8601String(),
        ]);
        $entryId = $r['entries'][0]['id'];
        $before = (array) DB::table('journal_entries')->where('id', $entryId)->first();

        $lines = DB::table('journal_lines')->where('entry_id', $entryId)->get();
        $half = $lines->mapWithKeys(fn ($l) => [$l->id => '40'])->all();
        app(LedgerService::class)->reverse($entryId, 'Error de carga', $this->user->id, $this->device, $half);
        app(LedgerService::class)->reverse($entryId, 'Resto', $this->user->id, $this->device);   // los 60 restantes

        $this->assertSame($before, (array) DB::table('journal_entries')->where('id', $entryId)->first());   // original intacto
        $this->assertSame('1000.0000', $this->summary([$this->per])['available']['by_currency']['ARS']);
        $v = DB::table('v_entry_reversal')->where('entry_id', $entryId)->first();
        $this->assertSame(0, \App\Domain\Decimal::cmp((string) $v->total_amount, (string) $v->reversed_amount));         // revertido por completo

        $this->expectException(LedgerException::class);
        app(LedgerService::class)->reverse($entryId, 'Otra vez', $this->user->id, $this->device);
    }

    public function test_periodo_cerrado_imputa_al_primer_periodo_abierto(): void
    {
        $bank = $this->account($this->per, 'Banco', 'bank', 'ARS', '1000');
        $now = CarbonImmutable::now('UTC');
        DB::table('accounting_periods')->where('space_id', $this->per)->where('year', $now->year)->where('month', $now->month)
            ->update(['status' => 'closed']);

        $r = $this->ops->apply($this->user->id, $this->device, 'expense', [
            'space_id' => $this->per, 'financial_account_id' => $bank, 'currency' => 'ARS', 'amount' => '10',
            'category_id' => $this->category('otros_gastos'), 'occurred_at' => $now->toIso8601String(),
        ]);
        $e = DB::table('journal_entries')->where('id', $r['entries'][0]['id'])->first();
        $this->assertSame($now->addMonth()->startOfMonth()->toDateString(), $e->accounting_date);
        $this->assertSame($now->toDateString(), $e->accounting_date_requested);     // se conserva la fecha pedida
        $this->assertSame(substr($e->occurred_at, 0, 10), $now->toDateString());    // y la fecha real de ocurrencia
    }

    public function test_numeracion_independiente_por_espacio(): void
    {
        $p = $this->account($this->per, 'P', 'bank', 'ARS', '10');
        $l = $this->account($this->lms, 'L', 'bank', 'ARS', '10');
        $np = DB::table('journal_entries')->where('space_id', $this->per)->value('number');
        $nl = DB::table('journal_entries')->where('space_id', $this->lms)->value('number');
        $this->assertStringStartsWith('PER-', $np);
        $this->assertStringStartsWith('LMS-', $nl);
        $this->assertStringEndsWith('-000001', $np);
        $this->assertStringEndsWith('-000001', $nl);
    }

    public function test_asiento_desequilibrado_no_se_confirma_y_no_deja_rastro(): void
    {
        $bank = $this->account($this->per, 'Banco', 'bank', 'ARS', '1000');
        $ledgerId = DB::table('financial_account_currencies')->where('financial_account_id', $bank)->value('ledger_account_id');
        $ledger = app(LedgerService::class);
        $id = $ledger->createEntry(['entry' => ['space_id' => $this->per, 'currency' => 'ARS', 'kind' => 'adjustment', 'occurred_at' => now()->toIso8601String(), 'description' => 'roto'],
            'lines' => [['ledger_account_id' => $ledgerId, 'debit' => '10', 'credit' => '0', 'category_id' => null]]], $this->user->id);
        try {
            $ledger->confirm($id, $this->user->id);
            $this->fail('Debió rechazar el asiento');
        } catch (LedgerException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
        }
        $this->assertSame('draft', DB::table('journal_entries')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('journal_entries')->where('id', $id)->value('number'));   // los borradores no consumen número
    }

    public function test_idempotencia_de_sync_y_rechazo_de_categoria_ajena(): void
    {
        $bank = $this->account($this->per, 'Banco', 'bank', 'ARS', '1000');
        $sync = app(\App\Services\SyncService::class);
        $op = ['id' => (string) Str::uuid(), 'entity' => 'operation', 'action' => 'expense', 'client_created_at' => now()->toIso8601String(), 'payload' => [
            'space_id' => $this->per, 'financial_account_id' => $bank, 'currency' => 'ARS', 'amount' => '50',
            'category_id' => $this->category('servicios'), 'occurred_at' => now()->toIso8601String(),
        ]];

        $first = $sync->push($this->user->id, $this->device, [$op])[0];
        $second = $sync->push($this->user->id, $this->device, [$op])[0];
        $this->assertSame('applied', $first['status']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['result'], $second['result']);
        $this->assertSame('950.0000', $this->summary([$this->per])['available']['by_currency']['ARS']);    // aplicado una sola vez

        // Categoría exclusiva de LM Studio usada en Personal → rechazada
        $foreign = (string) Str::uuid();
        DB::table('categories')->insert(['id' => $foreign, 'space_id' => $this->lms, 'name' => 'Solo LMS', 'kind' => 'expense', 'is_active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $bad = $op;
        $bad['id'] = (string) Str::uuid();
        $bad['payload']['category_id'] = $foreign;
        $res = $sync->push($this->user->id, $this->device, [$bad])[0];
        $this->assertSame('rejected', $res['status']);
        $this->assertSame('950.0000', $this->summary([$this->per])['available']['by_currency']['ARS']);
    }

    public function test_conflicto_de_version_conserva_ambas_versiones(): void
    {
        $bank = $this->account($this->per, 'Banco', 'bank', 'ARS', '0');
        $sync = app(\App\Services\SyncService::class);
        $mk = fn (string $name, int $base) => ['id' => (string) Str::uuid(), 'entity' => 'financial_account', 'action' => 'update', 'base_version' => $base,
            'client_created_at' => now()->toIso8601String(), 'payload' => ['id' => $bank, 'changes' => ['name' => $name]]];

        $this->assertSame('applied', $sync->push($this->user->id, $this->device, [$mk('Desde A', 1)])[0]['status']);
        $b = $sync->push($this->user->id, $this->device, [$mk('Desde B', 1)])[0];     // B partió de la versión 1, ya está en la 2
        $this->assertSame('conflict', $b['status']);
        $this->assertSame('Desde A', DB::table('financial_accounts')->where('id', $bank)->value('name'));   // no se sobrescribe
        $this->assertSame(1, DB::table('sync_conflicts')->where('status', 'open')->count());
    }
}
