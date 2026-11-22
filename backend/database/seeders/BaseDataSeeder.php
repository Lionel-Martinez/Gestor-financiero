<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Monedas y categorías globales predeterminadas. Idempotente. */
final class BaseDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['ARS', 'Peso argentino', 2, 'fiat'], ['USD', 'Dólar estadounidense', 2, 'fiat'], ['EUR', 'Euro', 2, 'fiat'],
            // USDT y USDC se identifican aparte del USD: no se asume paridad exacta.
            ['USDT', 'Tether', 6, 'crypto'], ['USDC', 'USD Coin', 6, 'crypto'], ['BTC', 'Bitcoin', 8, 'crypto'],
        ] as [$code, $name, $units, $kind]) {
            DB::table('currencies')->updateOrInsert(['code' => $code], ['name' => $name, 'minor_units' => $units, 'kind' => $kind, 'is_active' => true]);
        }

        $now = now()->utc()->format('Y-m-d H:i:s.v');
        $global = [
            ['expense', 'Alimentación', 'alimentacion'], ['expense', 'Vivienda y alquiler', 'vivienda'], ['expense', 'Servicios', 'servicios'],
            ['expense', 'Transporte', 'transporte'], ['expense', 'Salud', 'salud'], ['expense', 'Educación', 'educacion'],
            ['expense', 'Ocio y salidas', 'ocio'], ['expense', 'Suscripciones', 'suscripciones'], ['expense', 'Impuestos', 'impuestos'],
            ['expense', 'Intereses y cargos financieros', 'financieros'], ['expense', 'Otros gastos', 'otros_gastos'],
            ['income', 'Sueldo', 'sueldo'], ['income', 'Honorarios', 'honorarios'], ['income', 'Ventas', 'ventas'],
            ['income', 'Intereses ganados', 'intereses'], ['income', 'Otros ingresos', 'otros_ingresos'],
        ];
        foreach ($global as [$kind, $name, $key]) {
            if (!DB::table('categories')->whereNull('space_id')->where('group_key', $key)->exists()) {
                DB::table('categories')->insert([
                    'id' => (string) Str::uuid(), 'space_id' => null, 'name' => $name, 'kind' => $kind, 'group_key' => $key,
                    'is_active' => true, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
