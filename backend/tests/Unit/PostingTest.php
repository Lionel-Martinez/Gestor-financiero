<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Decimal;
use App\Domain\Ledger\Posting;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PostingTest extends TestCase
{
    private function sums(array $lines): array
    {
        $d = '0.0000';
        $c = '0.0000';
        foreach ($lines as $l) {
            $d = Decimal::add($d, $l['debit']);
            $c = Decimal::add($c, $l['credit']);
        }

        return [$d, $c];
    }

    private function assertBalanced(array $posting): void
    {
        [$d, $c] = $this->sums($posting['lines']);
        $this->assertSame($d, $c);
    }

    public function test_ingreso_y_gasto_equilibrados(): void
    {
        $in = Posting::income('s', 'ARS', 'cash', 'inc', '1500.25', 'cat', '2026-11-21T10:00:00Z', 'Sueldo');
        $this->assertBalanced($in);
        $this->assertSame('1500.2500', $in['lines'][0]['debit']);
        $this->assertSame('cat', $in['lines'][1]['category_id']);

        $out = Posting::expense('s', 'ARS', 'cash', 'exp', '300', 'cat', '2026-11-21T10:00:00Z');
        $this->assertBalanced($out);
        $this->assertSame('300.0000', $out['lines'][1]['credit']);
    }

    public function test_transferencia_propia_no_usa_categorias(): void
    {
        $t = Posting::ownTransfer('s', 'ARS', 'a', 'b', '50', '2026-11-21T10:00:00Z');
        $this->assertBalanced($t);
        foreach ($t['lines'] as $l) {
            $this->assertSame(null, $l['category_id']);
        }
    }

    public function test_transferencia_propia_misma_cuenta_falla(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Posting::ownTransfer('s', 'ARS', 'a', 'a', '50', '2026-11-21T10:00:00Z');
    }

    public function test_transferencia_entre_espacios_genera_un_asiento_por_espacio(): void
    {
        $t = Posting::interSpaceTransfer('per', 'per_cash', 'per_tie', 'lms', 'lms_cash', 'lms_tie', 'ARS', '1000', '2026-11-21T10:00:00Z');
        $this->assertBalanced($t['out']);
        $this->assertBalanced($t['in']);
        $this->assertSame('per', $t['out']['entry']['space_id']);
        $this->assertSame('lms', $t['in']['entry']['space_id']);
        // El espacio origen no registra ingreso/gasto: solo caja y cuenta interespacio.
        $accounts = array_column($t['out']['lines'], 'ledger_account_id');
        sort($accounts);
        $this->assertSame(['per_cash', 'per_tie'], $accounts);
    }

    public function test_transferencia_entre_espacios_exige_espacios_distintos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Posting::interSpaceTransfer('x', 'a', 'b', 'x', 'c', 'd', 'ARS', '1', '2026-11-21T10:00:00Z');
    }

    public function test_conversion_dos_asientos_en_su_moneda_y_cotizacion_implicita(): void
    {
        $c = Posting::conversion('s', 'USD', 'usd_cash', 'bridge_usd', '100', 'ARS', 'ars_cash', 'bridge_ars', '125050', '2026-11-21T10:00:00Z', 'Compra USD', '2.5', 'fee_exp');
        $this->assertBalanced($c['source']);
        $this->assertBalanced($c['target']);
        $this->assertBalanced($c['commission']);
        $this->assertSame('USD', $c['source']['entry']['currency']);
        $this->assertSame('ARS', $c['target']['entry']['currency']);
        $this->assertSame('1250.50000000', $c['rate']);
    }

    public function test_conversion_misma_moneda_falla(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Posting::conversion('s', 'ARS', 'a', 'b', '1', 'ARS', 'c', 'd', '1', '2026-11-21T10:00:00Z');
    }

    public function test_apertura_activo_y_pasivo_usan_patrimonio_inicial(): void
    {
        $a = Posting::opening('s', 'ARS', 'bank', 'asset', 'eq', '5000', '2026-11-21T00:00:00Z');
        $this->assertBalanced($a);
        $this->assertSame('opening', $a['entry']['kind']);
        $this->assertSame('5000.0000', $a['lines'][0]['debit']);
        $this->assertSame('bank', $a['lines'][0]['ledger_account_id']);

        $l = Posting::opening('s', 'ARS', 'card', 'liability', 'eq', '800', '2026-11-21T00:00:00Z');
        $this->assertBalanced($l);
        $this->assertSame('eq', $l['lines'][0]['ledger_account_id']);   // Debe patrimonio
        $this->assertSame('800.0000', $l['lines'][1]['credit']);        // Haber pasivo
    }

    public function test_apertura_rechaza_cuentas_de_resultado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Posting::opening('s', 'ARS', 'x', 'income', 'eq', '1', '2026-11-21T00:00:00Z');
    }

    public function test_reversion_total_invierte_debe_y_haber(): void
    {
        $orig = [
            ['id' => 'l1', 'ledger_account_id' => 'exp', 'debit' => '100.0000', 'credit' => '0.0000', 'category_id' => 'cat'],
            ['id' => 'l2', 'ledger_account_id' => 'cash', 'debit' => '0.0000', 'credit' => '100.0000', 'category_id' => null],
        ];
        $r = Posting::reversalLines($orig, ['l1' => '100.0000', 'l2' => '100.0000']);
        $this->assertSame('0.0000', $r['lines'][0]['debit']);
        $this->assertSame('100.0000', $r['lines'][0]['credit']);
        $this->assertSame('100.0000', $r['lines'][1]['debit']);
        $this->assertSame(['l1' => '100.0000', 'l2' => '100.0000'], $r['allocations']);
        $this->assertBalanced($r);
    }

    public function test_reversion_parcial_y_exceso(): void
    {
        $orig = [
            ['id' => 'l1', 'ledger_account_id' => 'exp', 'debit' => '100.0000', 'credit' => '0.0000', 'category_id' => 'cat'],
            ['id' => 'l2', 'ledger_account_id' => 'cash', 'debit' => '0.0000', 'credit' => '100.0000', 'category_id' => null],
        ];
        $remaining = ['l1' => '100.0000', 'l2' => '100.0000'];

        $p = Posting::reversalLines($orig, $remaining, ['l1' => '40', 'l2' => '40']);
        $this->assertBalanced($p);
        $this->assertSame('40.0000', $p['lines'][0]['credit']);

        $this->expectException(InvalidArgumentException::class);
        Posting::reversalLines($orig, ['l1' => '60.0000', 'l2' => '60.0000'], ['l1' => '70', 'l2' => '70']);
    }

    public function test_reversion_sin_pendiente_falla(): void
    {
        $orig = [['id' => 'l1', 'ledger_account_id' => 'a', 'debit' => '10.0000', 'credit' => '0.0000', 'category_id' => null]];
        $this->expectException(InvalidArgumentException::class);
        Posting::reversalLines($orig, ['l1' => '0.0000']);
    }
}
