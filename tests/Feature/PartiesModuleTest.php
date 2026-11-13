<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PartiesModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('parties-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function userToken(bool $canManage = false): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);

        if ($canManage) {
            $role = Role::query()->create(['slug' => 'party_operator', 'name' => 'Operador de cadastro']);
            $permission = Permission::query()->firstOrCreate(['key' => 'parties.manage']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    public function test_every_party_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/parties')->assertUnauthorized();
        $this->postJson('/api/v1/parties', [])->assertUnauthorized();
        $this->getJson('/api/v1/parties/1')->assertUnauthorized();
        $this->patchJson('/api/v1/parties/1', [])->assertUnauthorized();
        $this->patchJson('/api/v1/parties/1/status', [])->assertUnauthorized();
        $this->getJson('/api/v1/parties/1/events')->assertUnauthorized();
    }

    public function test_valid_session_without_parties_permission_is_denied(): void
    {
        $token = $this->userToken();
        $this->withToken($token)->getJson('/api/v1/parties')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/parties', [
            'type' => 'person', 'name' => 'Contato sem acesso',
        ])->assertForbidden();
        $this->assertDatabaseCount('parties', 0);
    }

    public function test_authorized_operator_creates_person_and_company_without_financial_data(): void
    {
        $token = $this->userToken(true);
        $this->withToken($token)->postJson('/api/v1/parties', [
            'type' => 'person', 'name' => 'Pessoa de Teste',
            'email' => '  PESSOA@EXAMPLE.TEST ', 'phone' => '79999999999',
        ])->assertCreated()->assertJsonPath('data.email', 'pessoa@example.test')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.amount');

        $this->withToken($token)->postJson('/api/v1/parties', [
            'type' => 'company', 'name' => 'Empresa de Teste',
        ])->assertCreated()->assertJsonPath('data.type', 'company');

        $this->assertDatabaseCount('parties', 2);
        $this->assertDatabaseHas('party_events', ['event_type' => 'party_created']);
    }

    public function test_list_search_type_and_status_filters_work(): void
    {
        Party::query()->create(['type' => 'person', 'name' => 'Aline Exemplo', 'email' => 'aline@example.test']);
        Party::query()->create(['type' => 'company', 'name' => 'Empresa Exemplo']);
        $token = $this->userToken(true);

        $this->withToken($token)->getJson('/api/v1/parties?search=Aline&type=person&status=active')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Aline Exemplo');
        $this->withToken($token)->getJson('/api/v1/parties?type=company')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_update_and_noop_preserve_audit_consistency(): void
    {
        $party = Party::query()->create(['type' => 'person', 'name' => 'Nome Original']);
        $token = $this->userToken(true);

        $this->withToken($token)->patchJson('/api/v1/parties/'.$party->id, [
            'name' => 'Nome Revisado', 'notes' => 'Observação interna',
        ])->assertOk()->assertJsonPath('changed', true);

        $this->withToken($token)->patchJson('/api/v1/parties/'.$party->id, [
            'name' => 'Nome Revisado',
        ])->assertOk()->assertJsonPath('changed', false);

        $this->assertSame('Nome Revisado', $party->fresh()->name);
        $this->assertDatabaseCount('party_events', 1);
    }

    public function test_status_change_preserves_history_without_deleting_records(): void
    {
        $party = Party::query()->create(['type' => 'company', 'name' => 'Cadastro Permanente']);
        $token = $this->userToken(true);

        $this->withToken($token)->patchJson('/api/v1/parties/'.$party->id.'/status', [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('changed', true)
            ->assertJsonPath('data.is_active', false);

        $this->withToken($token)->patchJson('/api/v1/parties/'.$party->id.'/status', [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('changed', false);

        $this->withToken($token)->patchJson('/api/v1/parties/'.$party->id.'/status', [
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.is_active', true);

        $this->assertDatabaseCount('parties', 1);
        $this->withToken($token)->getJson('/api/v1/parties/'.$party->id.'/events')
            ->assertOk()->assertJsonCount(2, 'events.data')
            ->assertJsonPath('events.data.0.event_type', 'party_reactivated')
            ->assertJsonMissingPath('events.data.0.notes');
    }

    public function test_financial_and_permission_fields_are_rejected(): void
    {
        $token = $this->userToken(true);
        $this->withToken($token)->postJson('/api/v1/parties', [
            'type' => 'person', 'name' => 'Teste não criado',
            'pix_key' => 'chave-qualquer', 'amount' => 500,
            'permissions' => ['users.manage'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['pix_key', 'amount', 'permissions']);

        $this->assertDatabaseCount('parties', 0);
    }

    public function test_validation_rejects_unsupported_types_and_empty_names(): void
    {
        $token = $this->userToken(true);
        $this->withToken($token)->postJson('/api/v1/parties', [
            'type' => 'recipient', 'name' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['type', 'name']);
    }
}
