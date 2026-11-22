<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\EntryValidator;
use PHPUnit\Framework\TestCase;

final class EntryValidatorTest extends TestCase
{
    private const PER = 'space-per';
    private const LMS = 'space-lms';

    /** @return array<string,array<string,mixed>> */
    private function accounts(): array
    {
        return [
            'cash' => ['space_id' => self::PER, 'type' => 'asset', 'is_group' => false, 'is_active' => true, 'currency' => 'ARS'],
            'cash_usd' => ['space_id' => self::PER, 'type' => 'asset', 'is_group' => false, 'is_active' => true, 'currency' => 'USD'],
            'income' => ['space_id' => self::PER, 'type' => 'income', 'is_group' => false, 'is_active' => true, 'currency' => null],
            'expense' => ['space_id' => self::PER, 'type' => 'expense', 'is_group' => false, 'is_active' => true, 'currency' => null],
            'group' => ['space_id' => self::PER, 'type' => 'asset', 'is_group' => true, 'is_active' => true, 'currency' => null],
            'inactive' => ['space_id' => self::PER, 'type' => 'asset', 'is_group' => false, 'is_active' => false, 'currency' => 'ARS'],
            'lms_cash' => ['space_id' => self::LMS, 'type' => 'asset', 'is_group' => false, 'is_active' => true, 'currency' => 'ARS'],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function categories(): array
    {
        return [
            'food' => ['space_id' => null, 'kind' => 'expense', 'is_active' => true],
            'salary' => ['space_id' => null, 'kind' => 'income', 'is_active' => true],
            'lms_only' => ['space_id' => self::LMS, 'kind' => 'expense', 'is_active' => true],
            'old' => ['space_id' => null, 'kind' => 'expense', 'is_active' => false],
        ];
    }

    private function line(string $acc, string $d, string $c, ?string $cat = null): array
    {
        return ['ledger_account_id' => $acc, 'debit' => $d, 'credit' => $c, 'category_id' => $cat];
    }

    private function check(array $lines, string $currency = 'ARS', string $kind = 'ordinary'): array
    {
        return (new EntryValidator())->validate(
            ['space_id' => self::PER, 'currency' => $currency, 'kind' => $kind],
            $lines,
            $this->accounts(),
            $this->categories(),
        );
    }

    public function test_gasto_valido(): void
    {
        $this->assertSame([], $this->check([$this->line('expense', '100', '0', 'food'), $this->line('cash', '0', '100')]));
    }

    public function test_ingreso_valido(): void
    {
        $this->assertSame([], $this->check([$this->line('cash', '5000.50', '0'), $this->line('income', '0', '5000.50', 'salary')]));
    }

    public function test_asiento_desequilibrado_no_valida(): void
    {
        $errors = $this->check([$this->line('expense', '100', '0', 'food'), $this->line('cash', '0', '99.9999')]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('no está equilibrado', $errors[0]);
    }

    public function test_requiere_dos_apuntes(): void
    {
        $errors = $this->check([$this->line('cash', '10', '0')]);
        $this->assertNotEmpty($errors);
    }

    public function test_apunte_con_debe_y_haber_a_la_vez(): void
    {
        $errors = $this->check([$this->line('cash', '10', '10'), $this->line('expense', '0', '0', 'food')]);
        $this->assertNotEmpty($errors);
    }

    public function test_no_mezcla_monedas(): void
    {
        $errors = $this->check([$this->line('cash_usd', '10', '0'), $this->line('income', '0', '10', 'salary')], 'ARS');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('moneda', $errors[0]);
    }

    public function test_cuenta_agrupadora_e_inactiva_rechazadas(): void
    {
        $this->assertNotEmpty($this->check([$this->line('group', '10', '0'), $this->line('income', '0', '10', 'salary')]));
        $this->assertNotEmpty($this->check([$this->line('inactive', '10', '0'), $this->line('income', '0', '10', 'salary')]));
    }

    public function test_cuenta_de_otro_espacio_rechazada(): void
    {
        $errors = $this->check([$this->line('lms_cash', '10', '0'), $this->line('income', '0', '10', 'salary')]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('otro espacio', $errors[0]);
    }

    public function test_gasto_sin_categoria_rechazado_salvo_fx_o_ajuste(): void
    {
        $lines = [$this->line('expense', '10', '0'), $this->line('cash', '0', '10')];
        $this->assertNotEmpty($this->check($lines));
        $this->assertSame([], $this->check($lines, 'ARS', 'fx'));
        $this->assertSame([], $this->check($lines, 'ARS', 'adjustment'));
    }

    public function test_activo_no_acepta_categoria(): void
    {
        $errors = $this->check([$this->line('cash', '10', '0', 'food'), $this->line('income', '0', '10', 'salary')]);
        $this->assertNotEmpty($errors);
    }

    public function test_categoria_de_otro_espacio_inactiva_o_de_tipo_incorrecto(): void
    {
        $base = fn (string $cat) => $this->check([$this->line('expense', '10', '0', $cat), $this->line('cash', '0', '10')]);
        $this->assertNotEmpty($base('lms_only'));
        $this->assertNotEmpty($base('old'));
        $this->assertNotEmpty($base('salary'));
        $this->assertNotEmpty($base('inexistente'));
    }

    public function test_importe_con_mas_de_4_decimales_rechazado(): void
    {
        $this->assertNotEmpty($this->check([$this->line('expense', '10.00001', '0', 'food'), $this->line('cash', '0', '10.00001')]));
    }

    public function test_importe_cero_rechazado(): void
    {
        $this->assertNotEmpty($this->check([$this->line('expense', '0', '0', 'food'), $this->line('cash', '0', '0')]));
    }
}
