<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entidades base: dispositivos, espacios, monedas, períodos, plan de cuentas, categorías y cuentas financieras.
 * Convenciones: UUID v4 CHAR(36), importes DECIMAL(19,4), cotizaciones DECIMAL(20,8), fechas DATETIME(3) UTC.
 * Las claves únicas (id, space_id) permiten claves foráneas compuestas que refuerzan la separación de espacios.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('user_id', 36);
            $t->string('name');
            $t->string('platform', 20);                        // android | ios | other
            $t->dateTime('last_seen_at', 3)->nullable();
            $t->dateTime('revoked_at', 3)->nullable();        // cierre remoto de sesión
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('spaces', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('user_id', 36);
            $t->string('code', 3);                             // PER | LMS (prefijo de numeración)
            $t->string('name');
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['user_id', 'code']);
            $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('currencies', function (Blueprint $t) {
            $t->string('code', 10)->primary();                 // ARS, USD, USDT, USDC, EUR...
            $t->string('name');
            $t->unsignedTinyInteger('minor_units')->default(2);
            $t->enum('kind', ['fiat', 'crypto'])->default('fiat');
            $t->boolean('is_active')->default(true);
        });

        Schema::create('accounting_periods', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('space_id', 36);
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->enum('status', ['open', 'closed'])->default('open');
            $t->dateTime('closed_at', 3)->nullable();
            $t->char('closed_by', 36)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['space_id', 'year', 'month']);
            $t->unique(['id', 'space_id']);
            $t->foreign('space_id')->references('id')->on('spaces')->restrictOnDelete();
        });

        Schema::create('ledger_accounts', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('space_id', 36);
            $t->char('parent_id', 36)->nullable();
            $t->string('code', 40);
            $t->string('name');
            $t->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $t->string('system_role', 30)->nullable();         // equity_opening | interspace_clearing | fx_bridge | fx_difference | fee | income_general | expense_general
            $t->boolean('is_group')->default(false);
            $t->string('currency', 10)->nullable();            // null = admite cualquier moneda
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['space_id', 'code']);
            $t->unique(['id', 'space_id']);
            $t->foreign('space_id')->references('id')->on('spaces')->restrictOnDelete();
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->foreign(['parent_id', 'space_id'])->references(['id', 'space_id'])->on('ledger_accounts')->restrictOnDelete();
        });

        Schema::create('categories', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('space_id', 36)->nullable();               // null = categoría global predeterminada
            $t->char('parent_id', 36)->nullable();              // máximo dos niveles (validado en servicio)
            $t->string('name');
            $t->enum('kind', ['income', 'expense', 'both'])->default('expense');
            $t->string('group_key', 60)->nullable();            // agrupación equivalente para reportes generales
            $t->boolean('is_active')->default(true);            // nunca se elimina físicamente si está en uso
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->foreign('space_id')->references('id')->on('spaces')->restrictOnDelete();
            $t->foreign('parent_id')->references('id')->on('categories')->restrictOnDelete();
            $t->index(['space_id', 'is_active']);
        });

        Schema::create('financial_accounts', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('space_id', 36);
            $t->string('name');
            $t->string('alias')->nullable();
            $t->enum('kind', ['bank', 'wallet', 'virtual', 'cash', 'usd_cash', 'digital_usd', 'credit_card', 'other']);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_archived')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->char('origin_device_id', 36)->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['id', 'space_id']);
            $t->foreign('space_id')->references('id')->on('spaces')->restrictOnDelete();
        });

        // Una cuenta puede operar en varias monedas; cada moneda tiene su propia cuenta contable y saldo independiente.
        Schema::create('financial_account_currencies', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('space_id', 36);
            $t->char('financial_account_id', 36);
            $t->string('currency', 10);
            $t->char('ledger_account_id', 36);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['financial_account_id', 'currency'], 'fac_account_currency_unique');   // nombres explícitos: MySQL limita los identificadores a 64 caracteres
            $t->unique('ledger_account_id');
            $t->foreign(['financial_account_id', 'space_id'], 'fac_account_space_fk')->references(['id', 'space_id'])->on('financial_accounts')->restrictOnDelete();
            $t->foreign(['ledger_account_id', 'space_id'], 'fac_ledger_space_fk')->references(['id', 'space_id'])->on('ledger_accounts')->restrictOnDelete();
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['financial_account_currencies', 'financial_accounts', 'categories', 'ledger_accounts', 'accounting_periods', 'currencies', 'spaces', 'devices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
