<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        // Os dados DEMO só entram em ambientes locais/de teste, nunca em produção.
        if (app()->environment(['local', 'testing'])) {
            $this->call(SaasDemoSeeder::class);
        }

        $this->call(FinanceCategoriesSeeder::class);
    }
}
