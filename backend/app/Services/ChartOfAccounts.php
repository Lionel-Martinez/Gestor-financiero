<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cuentas contables de sistema por espacio (patrimonio inicial, transferencias interespacio, puentes FX, etc.).
 * Se crean bajo demanda de forma idempotente.
 */
final class ChartOfAccounts
{
    /** rol => [código, nombre, tipo, ¿una por moneda?] */
    private const ROLES = [
        'equity_opening' => ['PAT-INI', 'Patrimonio inicial', 'equity', false],
        'interspace_clearing' => ['TIE', 'Transferencias interespacio', 'asset', false],
        'fx_bridge' => ['FXB', 'Cuenta puente de conversión', 'equity', true],
        'fx_difference' => ['DIF', 'Diferencias de cambio', 'expense', false],
        'fee' => ['COM', 'Comisiones y cargos', 'expense', false],
        'income_general' => ['ING', 'Ingresos', 'income', false],
        'expense_general' => ['GAS', 'Gastos', 'expense', false],
    ];

    /**
     * Crea todas las cuentas de sistema de un espacio (incluidas las puentes por cada moneda activa) para que la app
     * las reciba en la primera sincronización y pueda operar offline sin esperar al servidor.
     */
    public function ensureBase(string $spaceId): void
    {
        foreach (array_keys(self::ROLES) as $role) {
            if ($role === 'fx_bridge') {
                foreach (DB::table('currencies')->where('is_active', true)->pluck('code') as $currency) {
                    $this->system($spaceId, $role, $currency);
                }
                continue;
            }
            $this->system($spaceId, $role);
        }
    }

    public function system(string $spaceId, string $role, ?string $currency = null): string
    {
        [$code, $name, $type, $perCurrency] = self::ROLES[$role] ?? throw new \InvalidArgumentException("Rol de cuenta desconocido: {$role}");
        if ($perCurrency) {
            $currency ?? throw new \InvalidArgumentException('La cuenta puente requiere moneda.');
            $code .= '-' . $currency;
            $name .= " ({$currency})";
        } else {
            $currency = null;
        }

        $existing = DB::table('ledger_accounts')->where('space_id', $spaceId)->where('code', $code)->value('id');
        if ($existing) {
            return $existing;
        }

        $id = (string) Str::uuid();
        $now = now()->utc()->format('Y-m-d H:i:s.v');
        DB::table('ledger_accounts')->insert([
            'id' => $id, 'space_id' => $spaceId, 'code' => $code, 'name' => $name, 'type' => $type,
            'system_role' => $role, 'is_group' => false, 'currency' => $currency, 'is_active' => true,
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }
}
