<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CommercialTermsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CommercialPortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('commercial-portfolio-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function token(array $grants): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'portfolio_'.$user->id, 'name' => 'Operador QA']);
        foreach ($grants as $grant) {
            $permission = Permission::query()->firstOrCreate(['key' => $grant]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function fixture(string $token, string $title): array
    {
        $vehicle = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => $title,
        ])->assertCreated()->json('data.id');

        $parties = [
            Party::query()->create(['type' => 'company', 'name' => 'Origem '.$title]),
            Party::query()->create(['type' => 'person', 'name' => 'Recebedor A '.$title]),
            Party::query()->create(['type' => 'person', 'name' => 'Recebedor B '.$title]),
        ];
        foreach ($parties as $party) {
            $this->withToken($token)->postJson("/api/v1/repasse-cases/{$vehicle}/parties", [
                'party_id' => $party->id,
            ])->assertCreated();
        }

        $template = $this->withToken($token)->postJson('/api/v1/finance/distribution-templates', [
            'case_id' => $vehicle,
            'name' => 'Modelo QA '.$title, 'description' => null,
            'source_party_id' => $parties[0]->id,
            'lines' => [
                ['party_id' => $parties[1]->id, 'basis_points' => 6000, 'memo' => null],
                ['party_id' => $parties[2]->id, 'basis_points' => 4000, 'memo' => null],
            ],
        ])->assertCreated()->json('data.id');

        $term = $this->withToken($token)->postJson("/api/v1/repasse-cases/{$vehicle}/commercial-terms", [
            'title' => 'Minuta '.$title,
            'description' => null,
            'template_id' => $template,
            'template_revision' => 1,
            'reference_cents' => 125001,
            'conditions' => array_fill_keys(array_keys(CommercialTermsService::FIELDS), null),
            'evidence_ids' => [],
        ])->assertCreated()->json('data.id');

        return [$vehicle, $template, $term];
    }

    public function test_read_and_export_require_jwt_and_two_permissions(): void
    {
        foreach (['/api/v1/commercial-portfolio', '/api/v1/commercial-portfolio/export'] as $path) {
            $this->get($path)->assertUnauthorized();
            foreach ([[], ['cases.manage'], ['scenarios.manage']] as $grants) {
                $this->withToken($this->token($grants))->get($path)->assertForbidden();
            }
        }
    }

    public function test_cross_vehicle_search_and_documentary_alerts_without_payments(): void
    {
        $jwt = $this->token(['cases.manage', 'scenarios.manage']);
        [$first, $firstTemplate, $firstTerm] = $this->fixture($jwt, 'Carro Azul');
        [$second] = $this->fixture($jwt, 'Carro Vermelho');

        $this->withToken($jwt)->getJson('/api/v1/commercial-portfolio')
            ->assertOk()->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.active', 2)
            ->assertJsonPath('data.terms.total', 2)
            ->assertJsonPath('data.terms.data.0.payment_enabled', false);

        $this->withToken($jwt)->getJson('/api/v1/commercial-portfolio?search=Carro%20Azul')
            ->assertOk()->assertJsonPath('data.terms.total', 1)
            ->assertJsonPath('data.terms.data.0.case_id', $first)
            ->assertJsonPath('data.terms.data.0.id', $firstTerm)
            ->assertJsonPath('data.terms.data.0.conditions_filled', 0)
            ->assertJsonCount(10, 'data.terms.data.0.missing_conditions')
            ->assertJsonPath('data.terms.data.0.financial_authorization', false);

        // Existing term remains a historical snapshot when its template advances.
        DB::table('repasse_distribution_templates')->where('id', $firstTemplate)
            ->update(['revision' => 2]);
        DB::table('repasse_cases')->where('id', $second)->update(['status' => 'archived']);

        $this->withToken($jwt)->getJson('/api/v1/commercial-portfolio')
            ->assertOk()->assertJsonPath('data.summary.model_outdated', 1)
            ->assertJsonPath('data.summary.vehicle_archived', 1);

        $csv = $this->withToken($jwt)->get('/api/v1/commercial-portfolio/export?search=Carro%20Azul');
        $csv->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $csvContent = $csv->streamedContent();
        $this->assertStringContainsString('Minuta Carro Azul', $csvContent);
        $this->assertStringNotContainsString('Minuta Carro Vermelho', $csvContent);
        $this->assertDatabaseCount('repasse_commercial_terms', 2);
    }

    public function test_invalid_filters_are_rejected_without_mutations(): void
    {
        $jwt = $this->token(['cases.manage', 'scenarios.manage']);
        foreach ([
            '?status=paid', '?page=-1', '?case_id=abc', '?search='.str_repeat('X', 101),
        ] as $query) {
            $this->withToken($jwt)->getJson('/api/v1/commercial-portfolio'.$query)
                ->assertUnprocessable();
        }
    }
    public function test_preparation_filters_and_global_counts_use_exact_current_review_before_pagination(): void
    {
        $jwt = $this->token(['cases.manage', 'scenarios.manage']);
        [$first, $template, $firstTerm] = $this->fixture($jwt, 'Carro Acompanhado');
        [$second] = $this->fixture($jwt, 'Carro Pendente');

        $commercial = DB::table('repasse_commercial_terms')->where('id', $firstTerm)->first();
        $version = DB::table('repasse_commercial_term_versions')->where('term_id', $firstTerm)
            ->where('revision', 1)->first();
        DB::table('repasse_commercial_reviews')->insert([
            'term_id' => $firstTerm,
            'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'payload_digest' => str_repeat('a', 64),
            'term_revision' => 1,
            'term_sha256' => $commercial->content_sha256,
            'term_version_sha256' => $version->sha256,
            'status' => 'reviewed',
            'request_note' => 'Parecer documental criado para consulta do filtro.',
            'revision' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $base = '/api/v1/commercial-portfolio';
        $this->withToken($jwt)->getJson($base)
            ->assertOk()->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.reviewed_current_revision', 1)
            ->assertJsonPath('data.summary.needs_documentary_review', 1)
            ->assertJsonPath('data.summary.reference_missing', 0);
        $this->withToken($jwt)->getJson("$base?preparation=reviewed")
            ->assertOk()->assertJsonPath('data.terms.total', 1)
            ->assertJsonPath('data.terms.data.0.id', $firstTerm)
            ->assertJsonPath('data.terms.data.0.current_review_status', 'reviewed')
            ->assertJsonPath('data.summary.reviewed_current_revision', 1);
        $this->withToken($jwt)->getJson("$base?preparation=needs_review")
            ->assertOk()->assertJsonPath('data.terms.total', 1);
        $this->withToken($jwt)->getJson("$base?status=active&search=Carro%20Acompanhado&preparation=reviewed")
            ->assertOk()->assertJsonPath('data.terms.total', 1);
        $this->withToken($jwt)->getJson("$base?preparation=not_approved")
            ->assertUnprocessable()->assertJsonValidationErrors('preparation');

        $csv = $this->withToken($jwt)->get("$base/export?preparation=reviewed")
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Carro Acompanhado', $csv);
        $this->assertStringNotContainsString('Carro Pendente', $csv);
        $this->assertStringContainsString('reviewed', $csv);

        // Depois de alterar a minuta, a revisão documental antiga não autoriza
        // uma revisão nova, mesmo que seu status histórico fosse "reviewed".
        $this->withToken($jwt)->putJson(
            "/api/v1/repasse-cases/{$first}/commercial-terms/{$firstTerm}",
            [
                'revision' => 1, 'title' => 'Minuta revisada',
                'description' => null,
                'template_id' => $template, 'template_revision' => 1,
                'reference_cents' => null,
                'conditions' => array_fill_keys(array_keys(CommercialTermsService::FIELDS), null),
                'evidence_ids' => [],
            ]
        )->assertOk()->assertJsonPath('data.revision', 2);
        $this->withToken($jwt)->getJson("$base?preparation=reviewed")
            ->assertOk()->assertJsonPath('data.terms.total', 0);
        $this->withToken($jwt)->getJson("$base?preparation=needs_review")
            ->assertOk()->assertJsonPath('data.terms.total', 2);
        $this->withToken($jwt)->getJson("$base?preparation=reference_missing")
            ->assertOk()->assertJsonPath('data.terms.total', 1)
            ->assertJsonPath('data.terms.data.0.case_id', $first);
        $this->withToken($jwt)->getJson($base)->assertOk()
            ->assertJsonPath('data.summary.reviewed_current_revision', 0)
            ->assertJsonPath('data.summary.reference_missing', 1);
        $this->assertNotSame($first, $second);
    }

}
