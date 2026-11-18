<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        // Os dados DEMO nunca entram na produção por migrate --seed.
        if (app()->environment('local') ||
            (app()->environment('testing') && env('REPASSE_SEED_DEMO') === 'true')) {
            $this->call(SaasDemoSeeder::class);
        }
    }
}
