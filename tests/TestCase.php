<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fixtures legadas usam apenas uma organização. O tenant 1 de QA
        // é criado depois do RefreshDatabase e nunca em produção.
        try {
            if (app()->environment('testing') && Schema::hasTable('tenants') &&
                ! DB::table('tenants')->where('id', 1)->exists()) {
                DB::table('tenants')->insert([
                    'id' => 1, 'slug' => 'testing-fixtures', 'name' => 'Tenant de testes',
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        } catch (\Illuminate\Database\QueryException) {
            // Suites de readiness testam propositalmente falha de conexão.
        }
    }
}
