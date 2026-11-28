<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Decimal;
use App\Exceptions\LedgerException;
use App\Models\User;
use App\Services\OperationService;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tarjetas de crédito y compras en cuotas sin interés (decisión D-5). */
final class CardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $per;
    private string $device;
    private OperationService $ops;

    protected function setUp(): void
    {
        parent::setUp();
        (new BaseDataSeeder())->run();
        $this->user = User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'una-clave-larga-123']);
        $now = now()->utc()->format('Y-m-d H:i:s.v');
        $this->per = (string) Str::uuid();
        DB::table('spaces')->insert(['id' => $this->per, 'user_id' => $this->user->id, 'code' => 'PER', 'name' => 'Personal', 'created_at' => $now, 'updated_at' => $now]);
        $this->device = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $this->device, 'user_id' => $this->user->id, 'name' => 'test', 'platform' => 'other', 'created_at' => $now, 'updated_at' => $now]);
        $this->ops = app(OperationService::class);
    }

    private function account(string $name, string $kind, string $opening = '0', array $extra = []): string
    {
        return $this->ops->apply($this->user->id, $this->device, 'account_create', [
            'space_id' => $this->per, 'name' => $name, 'kind' => $kind, 'currencies' => [['currency' => 'ARS', 'opening_balance' => $opening]],
        ] + $extra)['id'];
    }

    private function card(): string
    {
        return $this->account('Visa', 'credit_card', '0', ['closing_day' => 20, 'due_day' => 5]);
    }

    private function category(): string
    {
        return DB::table('categories')->whereNull('space_id')->where('group_key', 'ocio')->value('id');
    }

    /** Deuda de la tarjeta según el ledger (haber − debe de su cuenta pasivo). */
    private function debt(string $card): string
    {
        $ledger = DB::table('financial_account_currencies')->where('financial_account_id', $card)->value('ledger_account_id');
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.ledger_account_id', $ledger)->where('e.status', 'confirmed')->get(['l.debit', 'l.credit']);
        $total = '0.0000';
        foreach ($rows as $r) {
            $total = Decimal::add(Decimal::sub($total, (string) $r->debit), (string) $r->credit);
        }

        return $total;
    }

    private function buy(string $card, string $amount, int $n, string $date = '2026-11-10'): array
    {
        return $this->ops->apply($this->user->id, $this->device, 'card_purchase', [
            'space_id' => $this->per, 'financial_account_id' => $card, 'currency' => 'ARS', 'amount' => $amount, 'installments' => $n,
            'category_id' => $this->category(), 'occurred_at' => $date . 'T12:00:00Z', 'purchase_date' => $date,
        ]);
    }

    public function test_compra_en_cuotas_reconoce_el_gasto_completo_y_arma_el_cronograma(): void
    {
        $card = $this->card();
        $r = $this->buy($card, '30000', 3);

        $this->assertCount(1, $r['entries']);
        $this->assertSame('30000.0000', $this->debt($card));                       // la deuda es el precio completo
        $rows = DB::table('card_installments')->orderBy('installment_no')->get();
        $this->assertSame(['2026-11', '2026-12', '2027-01'], $rows->pluck('statement_month')->all());
        $this->assertSame(['10000.0000', '10000.0000', '10000.0000'], $rows->pluck('amount')->map(fn ($a) => Decimal::norm((string) $a))->all());
        $this->assertSame(['open'], $rows->pluck('status')->unique()->values()->all());
    }

    public function test_tarjeta_sin_cierre_ni_vencimiento_no_admite_compras(): void
    {
        $card = $this->account('Master', 'credit_card');
        try {
            $this->buy($card, '100', 1);
            $this->fail('Debió rechazar la compra.');
        } catch (LedgerException $e) {
            $this->assertSame('card_not_configured', $e->errorCode);
        }
        $this->assertSame(0, DB::table('card_installments')->count());
    }

    public function test_solo_las_tarjetas_admiten_compras_en_cuotas(): void
    {
        $bank = $this->account('Banco', 'bank', '1000');
        $this->expectException(LedgerException::class);
        $this->buy($bank, '100', 2);
    }

    public function test_pagar_el_resumen_cancela_solo_esas_cuotas(): void
    {
        $card = $this->card();
        $bank = $this->account('Banco', 'bank', '50000');
        $this->buy($card, '30000', 3);                                              // resúmenes 2026-11, 2026-12, 2027-01
        $this->buy($card, '4000', 1, '2026-11-15');                                 // también entra en 2026-11

        $r = $this->ops->apply($this->user->id, $this->device, 'card_payment', [
            'space_id' => $this->per, 'financial_account_id' => $card, 'from_financial_account_id' => $bank,
            'currency' => 'ARS', 'statement_month' => '2026-11', 'occurred_at' => '2026-12-04T12:00:00Z',
        ]);

        $this->assertSame('14000.0000', $r['amount']);                              // 10000 + 4000
        $this->assertSame(2, $r['installments_paid']);
        $this->assertSame('20000.0000', $this->debt($card));                        // quedan 2 cuotas de 10000
        $this->assertSame(2, DB::table('card_installments')->where('status', 'paid')->count());
        $this->assertSame(2, DB::table('card_installments')->where('status', 'open')->count());

        $this->expectException(LedgerException::class);                              // ya no queda nada por pagar en ese resumen
        $this->ops->apply($this->user->id, $this->device, 'card_payment', [
            'space_id' => $this->per, 'financial_account_id' => $card, 'from_financial_account_id' => $bank,
            'currency' => 'ARS', 'statement_month' => '2026-11', 'occurred_at' => '2026-12-04T12:00:00Z',
        ]);
    }

    public function test_revertir_la_compra_cancela_las_cuotas_y_con_cuotas_pagadas_se_rechaza(): void
    {
        $card = $this->card();
        $bank = $this->account('Banco', 'bank', '50000');
        $a = $this->buy($card, '9000', 3);
        $b = $this->buy($card, '6000', 2, '2026-11-12');

        $this->ops->apply($this->user->id, $this->device, 'reverse', ['entry_id' => $a['entries'][0]['id'], 'reason' => 'Compra cargada dos veces']);
        $this->assertSame(['cancelled'], DB::table('card_installments')->where('entry_id', $a['entries'][0]['id'])->pluck('status')->unique()->values()->all());
        $this->assertSame('6000.0000', $this->debt($card));

        $this->ops->apply($this->user->id, $this->device, 'card_payment', [
            'space_id' => $this->per, 'financial_account_id' => $card, 'from_financial_account_id' => $bank,
            'currency' => 'ARS', 'statement_month' => '2026-11', 'occurred_at' => '2026-12-04T12:00:00Z',
        ]);
        try {
            $this->ops->apply($this->user->id, $this->device, 'reverse', ['entry_id' => $b['entries'][0]['id'], 'reason' => 'Intento con cuotas pagadas']);
            $this->fail('Debió rechazar revertir una compra con cuotas pagadas.');
        } catch (LedgerException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_revertir_el_pago_reabre_las_cuotas(): void
    {
        $card = $this->card();
        $bank = $this->account('Banco', 'bank', '50000');
        $this->buy($card, '8000', 2);
        $pay = $this->ops->apply($this->user->id, $this->device, 'card_payment', [
            'space_id' => $this->per, 'financial_account_id' => $card, 'from_financial_account_id' => $bank,
            'currency' => 'ARS', 'statement_month' => '2026-11', 'occurred_at' => '2026-12-04T12:00:00Z',
        ]);
        $this->assertSame('4000.0000', $pay['amount']);

        $this->ops->apply($this->user->id, $this->device, 'reverse', ['entry_id' => $pay['entries'][0]['id'], 'reason' => 'Pago equivocado']);
        $this->assertSame(['open'], DB::table('card_installments')->pluck('status')->unique()->values()->all());
        $this->assertSame('8000.0000', $this->debt($card));
        $this->assertNull(DB::table('card_installments')->where('installment_no', 1)->value('paid_entry_id'));
    }
}
