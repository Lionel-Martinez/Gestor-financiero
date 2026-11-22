<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ChartOfAccounts;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Alta de usuario (V1 es de un solo usuario: no hay registro público). Crea además los espacios Personal y LM Studio. */
final class CreateUser extends Command
{
    protected $signature = 'gf:create-user {email} {name} {--password=}';
    protected $description = 'Crea un usuario con sus espacios Personal (PER) y LM Studio (LMS)';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Contraseña');
        if (strlen((string) $password) < 12) {
            $this->error('La contraseña debe tener al menos 12 caracteres.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($password) {
            (new BaseDataSeeder())->run();
            $user = User::create(['name' => $this->argument('name'), 'email' => $this->argument('email'), 'password' => $password]);
            $now = now()->utc()->format('Y-m-d H:i:s.v');
            foreach (['PER' => 'Personal', 'LMS' => 'LM Studio'] as $code => $name) {
                $spaceId = (string) Str::uuid();
                DB::table('spaces')->insert(['id' => $spaceId, 'user_id' => $user->id, 'code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
                app(ChartOfAccounts::class)->ensureBase($spaceId);
            }
        });

        $this->info('Usuario y espacios creados.');

        return self::SUCCESS;
    }
}
