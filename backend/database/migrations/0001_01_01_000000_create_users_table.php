<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sustituye la migración de usuarios del skeleton de Laravel: identidad UUID v4 (CHAR(36)).
 * API pura: sin tabla de sesiones web.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->text('two_factor_secret')->nullable();          // cifrado por la app (cast encrypted)
            $t->json('two_factor_recovery_codes')->nullable();  // solo hashes SHA-256
            $t->dateTime('two_factor_confirmed_at', 3)->nullable();
            $t->string('reference_currency', 10)->default('ARS');   // moneda base de reportes (supuesto S-6)
            $t->string('reference_rate_type', 20)->default('oficial');
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->dateTime('created_at', 3)->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->char('tokenable_id', 36);
            $t->string('tokenable_type');
            $t->index(['tokenable_type', 'tokenable_id']);
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->dateTime('last_used_at', 3)->nullable();
            $t->dateTime('expires_at', 3)->nullable()->index();
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
