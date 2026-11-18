<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogo técnico global, sem criar credenciais de produção.
        $this->call(AdminUserSeeder::class);

        // Instalação limpa de QA/desenvolvimento com duas empresas e dados reais
        // na estrutura da aplicação, mas inteiramente FICTÍCIOS no conteúdo.
        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoTenantSeeder::class);
        }
    }
}
