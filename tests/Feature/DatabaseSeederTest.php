<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\CommercialTermsService;
use App\Services\DistributionTemplateService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_loads_demo_data_in_testing_without_environment_flags(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('repasse_cases', 6);
    }

    public function test_demo_revision_hashes_survive_json_object_key_normalization(): void
    {
        $this->seed(DatabaseSeeder::class);
        $tenant = Tenant::query()->where('slug', 'demo-alfa')->firstOrFail();
        request()->attributes->set('verified_tenant_id', (int) $tenant->id);

        try {
            $templateVersion = DB::table('repasse_distribution_versions')
                ->where('tenant_id', $tenant->id)->firstOrFail();
            $this->normalizeJsonColumn('repasse_distribution_versions', (int) $templateVersion->id, 'snapshot');

            app(DistributionTemplateService::class)->version(
                (int) $templateVersion->template_id,
                (int) $templateVersion->revision
            );

            $term = DB::table('repasse_commercial_terms')
                ->where('tenant_id', $tenant->id)->firstOrFail();
            $this->normalizeJsonColumn('repasse_commercial_terms', (int) $term->id, 'template_snapshot');
            $this->normalizeJsonColumn('repasse_commercial_terms', (int) $term->id, 'conditions');

            $termVersion = DB::table('repasse_commercial_term_versions')
                ->where('tenant_id', $tenant->id)->firstOrFail();
            $this->normalizeJsonColumn('repasse_commercial_term_versions', (int) $termVersion->id, 'snapshot');

            $shown = app(CommercialTermsService::class)->show(
                (int) $term->repasse_case_id,
                (int) $term->id
            );
            $this->assertSame((int) $term->id, $shown['id']);
            app(CommercialTermsService::class)->version(
                (int) $term->repasse_case_id,
                (int) $term->id,
                (int) $termVersion->revision
            );
        } finally {
            request()->attributes->remove('verified_tenant_id');
        }
    }

    private function normalizeJsonColumn(string $table, int $id, string $column): void
    {
        $raw = DB::table($table)->where('id', $id)->value($column);
        $value = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        DB::table($table)->where('id', $id)->update([
            $column => json_encode($this->sortJsonObjects($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function sortJsonObjects(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortJsonObjects($item);
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
