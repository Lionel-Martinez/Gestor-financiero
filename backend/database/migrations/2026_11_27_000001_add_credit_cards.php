<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarjetas de crédito, versión 1 (decisión D-5): día de cierre y de vencimiento por tarjeta y cronograma de cuotas sin interés.
 * Las cuotas no son asientos: solo ordenan lo que se debe pagar en cada resumen. El gasto se reconoce completo al comprar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $t) {
            $t->unsignedTinyInteger('closing_day')->nullable()->after('kind');
            $t->unsignedTinyInteger('due_day')->nullable()->after('closing_day');
        });

        Schema::create('card_installments', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('space_id', 36);
            $t->char('financial_account_id', 36);
            $t->char('entry_id', 36);                       // asiento de la compra (gasto completo)
            $t->unsignedSmallInteger('installment_no');
            $t->unsignedSmallInteger('installments_total');
            $t->char('currency', 6);
            $t->decimal('amount', 19, 4);
            $t->char('statement_month', 7);                 // YYYY-MM del resumen en el que se cobra
            $t->date('due_date');
            $t->enum('status', ['open', 'paid', 'cancelled'])->default('open');
            $t->char('paid_entry_id', 36)->nullable();      // asiento del pago del resumen
            $t->dateTime('paid_at', 3)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['entry_id', 'installment_no']);
            $t->index(['financial_account_id', 'currency', 'statement_month', 'status'], 'ci_statement_idx');
            $t->foreign(['financial_account_id', 'space_id'], 'ci_account_space_fk')->references(['id', 'space_id'])->on('financial_accounts')->restrictOnDelete();
            $t->foreign(['entry_id', 'space_id'], 'ci_entry_space_fk')->references(['id', 'space_id'])->on('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_installments');
        Schema::table('financial_accounts', function (Blueprint $t) {
            $t->dropColumn(['closing_day', 'due_day']);
        });
    }
};
