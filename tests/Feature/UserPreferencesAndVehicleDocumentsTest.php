<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class UserPreferencesAndVehicleDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('macro-57-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function token(bool $manageVehicles = false): array
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        if ($manageVehicles) {
            $role = Role::query()->firstOrCreate(['slug' => 'vehicle_operator'], ['name' => 'Operador']);
            $permission = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        $jwt = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');

        return [$user, $jwt];
    }

    public function test_ui_preferences_are_persisted_per_authenticated_user_and_support_partial_updates(): void
    {
        [$first, $firstToken] = $this->token();
        [$second, $secondToken] = $this->token();

        $this->getJson('/api/v1/auth/preferences')->assertUnauthorized();
        $this->patchJson('/api/v1/auth/preferences', ['theme_mode' => 'dark'])->assertUnauthorized();

        $this->withToken($firstToken)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.preferences.theme_mode', 'system')
            ->assertJsonPath('data.preferences.sidebar_collapsed', false)
            ->assertJsonPath('data.preferences.vehicle_view', 'list');

        $this->withToken($firstToken)->patchJson('/api/v1/auth/preferences', [
            'theme_mode' => 'dark', 'sidebar_collapsed' => true, 'vehicle_view' => 'grid',
        ])->assertOk()
            ->assertJsonPath('data.theme_mode', 'dark')
            ->assertJsonPath('data.sidebar_collapsed', true)
            ->assertJsonPath('data.vehicle_view', 'grid');

        $this->withToken($firstToken)->patchJson('/api/v1/auth/preferences', [
            'theme_mode' => 'system',
        ])->assertOk()->assertJsonPath('data.vehicle_view', 'grid')
            ->assertJsonPath('data.sidebar_collapsed', true);

        $this->withToken($firstToken)->patchJson('/api/v1/auth/preferences', [
            'theme_mode' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors('theme_mode');

        $this->withToken($firstToken)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.preferences.theme_mode', 'system')
            ->assertJsonPath('data.preferences.sidebar_collapsed', true)
            ->assertJsonPath('data.preferences.vehicle_view', 'grid');
        $this->withToken($secondToken)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.preferences.theme_mode', 'system')
            ->assertJsonPath('data.preferences.sidebar_collapsed', false)
            ->assertJsonPath('data.preferences.vehicle_view', 'list');

        $this->assertSame('grid', $first->fresh()->preferences['vehicle_view']);
        $this->assertNull($second->fresh()->preferences);
    }

    public function test_financial_dates_and_vehicle_document_classifications_are_enforced(): void
    {
        Storage::fake('local');
        [, $token] = $this->token(true);
        $case = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Veículo com documentação',
        ])->assertCreated()->json('data.id');
        $base = "/api/v1/repasse-cases/{$case}";

        $entry = [
            'kind' => 'expense', 'status' => 'realized',
            'category' => 'Oficina', 'description' => 'Revisão mecânica', 'amount_cents' => 25000,
        ];
        $this->withToken($token)->postJson("$base/finance/entries", $entry)
            ->assertUnprocessable()->assertJsonValidationErrors('effective_on');
        $this->withToken($token)->postJson("$base/finance/entries", [
            ...$entry, 'effective_on' => '2026-11-19',
        ])->assertCreated()->assertJsonPath('data.effective_on', '2026-11-19');

        $this->withToken($token)->post("$base/evidence", [
            'file' => UploadedFile::fake()->image('sem-classe.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('document_type');

        $photoId = $this->withToken($token)->post("$base/evidence", [
            'document_type' => 'foto_veiculo',
            'file' => UploadedFile::fake()->image('veiculo.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.document_type', 'foto_veiculo')->json('data.id');

        $this->withToken($token)->post("$base/evidence", [
            'document_type' => 'outros',
            'file' => UploadedFile::fake()->create('avaliacao.pdf', 24, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonValidationErrors('document_label');

        $this->withToken($token)->post("$base/evidence", [
            'document_type' => 'outros',
            'document_label' => 'Avaliação do motor',
            'file' => UploadedFile::fake()->create('avaliacao.pdf', 24, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.document_label', 'Avaliação do motor');

        $this->withToken($token)->getJson("$base/evidence")->assertOk()
            ->assertJsonPath('data.0.document_type', 'outros')
            ->assertJsonPath('data.1.document_type', 'foto_veiculo');

        $this->withToken($token)->getJson('/api/v1/operations/vehicles')->assertOk()
            ->assertJsonPath('data.0.image_document_id', $photoId);
        $this->withToken($token)->get("$base/evidence/$photoId/download")->assertOk();
    }
}
