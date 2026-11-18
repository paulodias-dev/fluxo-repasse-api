<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Contas criadas antes do cadastro público já foram provisionadas por
        // um administrador/seed. Elas não devem perder o acesso ao ativar a
        // exigência de confirmação para novos cadastros.
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Não desfazemos a confirmação de contas existentes ao reverter a migration.
    }
};
