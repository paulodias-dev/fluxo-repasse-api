<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RepasseCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('repasse-cases-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function token(bool $allowed): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        if ($allowed) {
            $role = Role::query()->create(['slug' => 'case_operator', 'name' => 'Operador de dossiês']);
            $permission = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    public function test_private_endpoints_require_valid_permissions(): void
    {
        $this->getJson('/api/v1/repasse-cases')->assertUnauthorized();
        $token = $this->token(false);
        $this->withToken($token)->getJson('/api/v1/repasse-cases')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Sem autorização',
        ])->assertForbidden();
    }

    public function test_create_edit_participants_archive_reopen_and_events(): void
    {
        $party = Party::query()->create(['type' => 'company', 'name' => 'Empresa real de teste']);
        $token = $this->token(true);
        $url = '/api/v1/repasse-cases';
        $id = $this->withToken($token)->postJson($url, [
            'title' => 'Caso documental', 'description' => 'Aguardando definição das regras',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->withToken($token)->postJson("$url/$id/parties", [
            'party_id' => $party->id, 'description' => 'Participante identificado',
        ])->assertCreated()->assertJsonCount(1, 'data.parties');
        $this->withToken($token)->postJson("$url/$id/parties", [
            'party_id' => $party->id,
        ])->assertUnprocessable();
        $this->withToken($token)->patchJson("$url/$id", [
            'title' => 'Caso documental editado',
        ])->assertOk()->assertJsonPath('changed', true);
        $this->archiveVehicleForQa($token, "$url/$id");
        $this->withToken($token)->postJson("$url/$id/parties", [
            'party_id' => $party->id,
        ])->assertUnprocessable();
        $this->withToken($token)->patchJson("$url/$id", [
            'status' => 'draft',
        ])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->withToken($token)->deleteJson("$url/$id/parties/{$party->id}")
            ->assertOk()->assertJsonCount(0, 'data.parties');
        $this->withToken($token)->getJson("$url/$id/events")
            ->assertOk()->assertJsonCount(6, 'events.data');
        $this->assertDatabaseCount('repasse_cases', 1);
        $this->assertDatabaseCount('repasse_case_parties', 0);
    }

    public function test_rejects_inactive_participants_and_forbidden_financial_fields(): void
    {
        $party = Party::query()->create(['type' => 'person', 'name' => 'Inativo']);
        $party->is_active = false;
        $party->save();
        $token = $this->token(true);
        $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Incorreto', 'amount' => 1000, 'commission' => 20,
        ])->assertUnprocessable();
        $id = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Caso sem valor',
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/v1/repasse-cases/$id/parties", [
            'party_id' => $party->id,
        ])->assertUnprocessable();
    }
}
