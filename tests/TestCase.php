<?php

namespace Tests;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * Legacy feature tests exercise a single business organization. The
     * explicit cross-tenant suite opts out and creates two independent ones.
     */
    protected bool $createLegacyTestTenant = true;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->createLegacyTestTenant || ! Schema::hasTable('tenants')) {
            return;
        }

        DB::table('tenants')->updateOrInsert(
            ['slug' => 'legacy-qa'],
            ['name' => 'Empresa QA (exemplo)', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]
        );
        $id = DB::table('tenants')->where('slug', 'legacy-qa')->value('id');
        app(TenantContext::class)->set((int) $id);
    }
}
