<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function test_normaliza_a_escala_4(): void
    {
        $this->assertSame('10.0000', Decimal::norm('10'));
        $this->assertSame('10.5000', Decimal::norm('10.5'));
        $this->assertSame('0.0000', Decimal::norm('-0'));
        $this->assertSame('10.5000', Decimal::norm('10.500000'));
    }

    public function test_rechaza_decimales_de_mas_y_basura(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::norm('10.00001');
    }

    public function test_rechaza_notacion_cientifica(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::norm('1e3');
    }

    public function test_suma_sin_error_de_punto_flotante(): void
    {
        $this->assertSame('0.3000', Decimal::add('0.1', '0.2'));
        $this->assertSame('1000000000000000.0001', Decimal::add('999999999999999.9999', '0.0002'));
    }

    public function test_resta_y_signos(): void
    {
        $this->assertSame('-5.0000', Decimal::sub('5', '10'));
        $this->assertSame('0.0000', Decimal::sub('7.25', '7.25'));
        $this->assertSame('12.7500', Decimal::sub('10', '-2.75'));
    }

    public function test_comparacion(): void
    {
        $this->assertSame(0, Decimal::cmp('1.0', '1.0000'));
        $this->assertSame(1, Decimal::cmp('2', '1.9999'));
        $this->assertSame(-1, Decimal::cmp('-3', '-2'));
        $this->assertSame(-1, Decimal::cmp('-1', '1'));
        $this->assertTrue(Decimal::isZero('0.0000'));
        $this->assertTrue(Decimal::isNegative('-0.0001'));
    }

    public function test_multiplicacion_con_cotizacion_y_redondeo_half_up(): void
    {
        // 100 USD * 1250.50 = 125050.00
        $this->assertSame('125050.0000', Decimal::mul('100', '1250.5'));
        // 1.0001 * 0.00005000 = 0.0000500050 -> 0.0001 (half-up)
        $this->assertSame('0.0001', Decimal::mul('1.0001', '0.00005'));
        // 1 * 0.00004999 -> 0.0000
        $this->assertSame('0.0000', Decimal::mul('1', '0.00004999'));
        $this->assertSame('-125050.0000', Decimal::mul('-100', '1250.5'));
    }
}
