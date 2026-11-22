<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ledger\EntryValidator;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Sanctum 4 no carga migraciones por sí solo: la tabla personal_access_tokens (con tokenable_id UUID)
        // está en la migración de usuarios de este proyecto.
        $this->app->singleton(EntryValidator::class);
    }

    public function boot(): void
    {
    }
}
