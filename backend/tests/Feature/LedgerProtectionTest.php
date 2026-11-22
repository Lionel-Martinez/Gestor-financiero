<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChartOfAccounts;
use App\Services\OperationService;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica que MySQL/MariaDB rechaza por sí mismo lo que el ledger prohíbe, aunque la aplicación se saltee sus servicios.
 * Se omite en SQLite (no instala triggers). Usar una base de pruebas aparte: RefreshDatabase borra todas las tablas.
 *
 *   DB_CONNECTION=mysql DB_DATABASE=gestor_financiero_test php artisan test --filter=LedgerProtectionTest
 */
final class LedgerProtectionTest extends TestCase
{
    use RefreshDatabase;

    private string $per;
    private string $lms;
    private string $entryId;
    private string $perCash;
    private string $lmsCash;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Los triggers solo existen en MySQL/MariaDB.');
        }

        (new BaseDataSeeder())->run();
        $user = User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'una-clave-larga-123']);
        $now = now()->utc()->format('Y-m-d H:i:s.v');
        foreach (['PER', 'LMS'] as $code) {
            $id = (string) Str::uuid();
            DB::table('spaces')->insert(['id' => $id, 'user_id' => $user->id, 'code' => $code, 'name' => $code, 'created_at' => $now, 'updated_at' => $now]);
            app(ChartOfAccounts::class)->ensureBase($id);
            $code === 'PER' ? $this->per = $id : $this->lms = $id;
        }
        $device = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $device, 'user_id' => $user->id, 'name' => 't', 'platform' => 'other', 'created_at' => $now, 'updated_at' => $now]);

        $ops = app(OperationService::class);
        $mk = fn (string $space) => $ops->apply($user->id, $device, 'account_create', [
            'space_id' => $space, 'name' => 'Banco', 'kind' => 'bank', 'currencies' => [['currency' => 'ARS', 'opening_balance' => '1000']],
        ])['id'];
        $perAcc = $mk($this->per);
        $lmsAcc = $mk($this->lms);
        $this->perCash = DB::table('financial_account_currencies')->where('financial_account_id', $perAcc)->value('ledger_account_id');
        $this->lmsCash = DB::table('financial_account_currencies')->where('financial_account_id', $lmsAcc)->value('ledger_account_id');
        $this->entryId = DB::table('journal_entries')->where('space_id', $this->per)->where('status', 'confirmed')->value('id');
    }

    private function assertBlocked(callable $fn, string $needle): void
    {
        try {
            $fn();
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }
        $this->fail("MySQL debió rechazar la operación ({$needle}).");
    }

    public function test_asiento_confirmado_no_se_modifica_ni_elimina(): void
    {
        $this->assertBlocked(fn () => DB::table('journal_entries')->where('id', $this->entryId)->update(['description' => 'hack']), 'LEDGER_IMMUTABLE');
        $this->assertBlocked(fn () => DB::table('journal_entries')->where('id', $this->entryId)->delete(), 'LEDGER_IMMUTABLE');
        $this->assertBlocked(fn () => DB::table('journal_entries')->where('id', $this->entryId)->update(['status' => 'draft']), 'LEDGER_IMMUTABLE');
    }

    public function test_apuntes_de_asiento_confirmado_son_inalterables(): void
    {
        $this->assertBlocked(fn () => DB::table('journal_lines')->where('entry_id', $this->entryId)->update(['debit' => '1']), 'LEDGER_IMMUTABLE');
        $this->assertBlocked(fn () => DB::table('journal_lines')->where('entry_id', $this->entryId)->delete(), 'LEDGER_IMMUTABLE');
        $this->assertBlocked(fn () => DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid(), 'entry_id' => $this->entryId, 'space_id' => $this->per, 'line_no' => 99,
            'ledger_account_id' => $this->perCash, 'debit' => '5', 'credit' => '0',
        ]), 'LEDGER_IMMUTABLE');
    }

    public function test_auditoria_y_cotizaciones_son_append_only(): void
    {
        $this->assertBlocked(fn () => DB::table('audit_log')->update(['reason' => 'x']), 'AUDIT_IMMUTABLE');
        $this->assertBlocked(fn () => DB::table('audit_log')->delete(), 'AUDIT_IMMUTABLE');

        DB::table('exchange_rates')->insert(['id' => (string) Str::uuid(), 'base_currency' => 'USD', 'quote_currency' => 'ARS', 'rate_type' => 'oficial',
            'rate' => '1000.00000000', 'source' => 'test', 'origin' => 'manual', 'quoted_at' => now(), 'created_at' => now()]);
        $this->assertBlocked(fn () => DB::table('exchange_rates')->update(['rate' => '1']), 'RATES_IMMUTABLE');
    }

    public function test_no_se_crea_un_asiento_ya_confirmado_ni_se_confirma_desequilibrado(): void
    {
        $base = fn (array $o = []) => $o + [
            'id' => (string) Str::uuid(), 'space_id' => $this->per, 'status' => 'draft', 'kind' => 'ordinary', 'currency' => 'ARS',
            'occurred_at' => now(), 'recorded_at' => now(), 'created_by' => DB::table('users')->value('id'), 'description' => 'x',
        ];
        $this->assertBlocked(fn () => DB::table('journal_entries')->insert($base(['status' => 'confirmed'])), 'LEDGER_FLOW');

        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert($base(['id' => $id]));
        DB::table('journal_lines')->insert(['id' => (string) Str::uuid(), 'entry_id' => $id, 'space_id' => $this->per, 'line_no' => 1, 'ledger_account_id' => $this->perCash, 'debit' => '10', 'credit' => '0']);
        $period = DB::table('accounting_periods')->where('space_id', $this->per)->value('id');
        $this->assertBlocked(fn () => DB::table('journal_entries')->where('id', $id)->update([
            'status' => 'confirmed', 'number' => 'PER-2026-999999', 'confirmed_at' => now(), 'accounting_date' => now()->toDateString(), 'period_id' => $period,
        ]), 'LEDGER_UNBALANCED');
    }

    public function test_check_de_importes_y_aislamiento_entre_espacios(): void
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $id, 'space_id' => $this->per, 'status' => 'draft', 'kind' => 'ordinary', 'currency' => 'ARS', 'occurred_at' => now(),
            'recorded_at' => now(), 'created_by' => DB::table('users')->value('id'), 'description' => 'x',
        ]);
        $line = fn (array $o) => DB::table('journal_lines')->insert($o + ['id' => (string) Str::uuid(), 'entry_id' => $id, 'space_id' => $this->per, 'line_no' => 1, 'ledger_account_id' => $this->perCash, 'debit' => '10', 'credit' => '0']);

        $this->assertBlocked(fn () => $line(['debit' => '10', 'credit' => '10']), 'chk_lines_amounts');      // debe y haber a la vez
        $this->assertBlocked(fn () => $line(['debit' => '-5']), 'chk_lines_amounts');                         // negativo
        $this->assertBlocked(fn () => $line(['ledger_account_id' => $this->lmsCash]), 'foreign key');         // cuenta de otro espacio
    }
}
