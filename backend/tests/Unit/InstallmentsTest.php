<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Cards\Installments;
use App\Domain\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InstallmentsTest extends TestCase
{
    public function test_compra_antes_del_cierre_entra_en_el_resumen_del_mes(): void
    {
        $s = Installments::schedule('2026-11-10', 20, 5, '30000', 3);   // cierra 20, vence 5 (mes siguiente)
        $this->assertSame(['2026-11', '2026-12', '2027-01'], array_column($s, 'statement_month'));
        $this->assertSame(['2026-12-05', '2027-01-05', '2027-02-05'], array_column($s, 'due_date'));
        $this->assertSame(['10000.0000', '10000.0000', '10000.0000'], array_column($s, 'amount'));
    }

    public function test_compra_despues_del_cierre_pasa_al_resumen_siguiente(): void
    {
        $this->assertSame('2026-11', Installments::schedule('2026-11-20', 20, 5, '100', 1)[0]['statement_month']);   // el día de cierre cuenta
        $this->assertSame('2026-12', Installments::schedule('2026-11-21', 20, 5, '100', 1)[0]['statement_month']);
        $this->assertSame('2027-01', Installments::schedule('2026-12-25', 20, 5, '100', 1)[0]['statement_month']);   // cruza de año
    }

    public function test_vencimiento_el_mismo_mes_si_cae_despues_del_cierre(): void
    {
        $s = Installments::schedule('2026-11-10', 10, 25, '100', 1);   // cierra 10, vence 25 del mismo mes
        $this->assertSame('2026-11-25', $s[0]['due_date']);
    }

    public function test_el_resto_va_a_la_ultima_cuota_y_la_suma_es_exacta(): void
    {
        $s = Installments::schedule('2026-11-10', 20, 5, '100', 3);
        $this->assertSame(['33.3333', '33.3333', '33.3334'], array_column($s, 'amount'));
        $total = '0.0000';
        foreach ($s as $row) {
            $total = Decimal::add($total, $row['amount']);
        }
        $this->assertSame('100.0000', $total);
    }

    public function test_rechaza_datos_invalidos(): void
    {
        foreach ([['2026-02-30', 20, 5, '10', 1], ['2026-11-10', 29, 5, '10', 1], ['2026-11-10', 20, 0, '10', 1], ['2026-11-10', 20, 5, '10', 0], ['2026-11-10', 20, 5, '10', 61], ['2026-11-10', 20, 5, '0', 1], ['2026-11-10', 20, 5, '-5', 2]] as $args) {
            try {
                Installments::schedule(...$args);
                $this->fail('Debió rechazar ' . json_encode($args));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
