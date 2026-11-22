<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ledger\EntryValidator;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Los usuarios usan UUID: la tabla personal_access_tokens es propia (ver migración de usuarios).
        Sanctum::ignoreMigrations();

        $this->app->singleton(EntryValidator::class);
    }

    public function boot(): void
    {
    }
}
