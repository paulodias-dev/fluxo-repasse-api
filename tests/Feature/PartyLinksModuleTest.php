<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PartyLinksModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('party-link-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function token(bool $permission): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        if ($permission) {
            $role = Role::query()->create(['slug' => 'links_operator', 'name' => 'Operador de vínculos']);
            $access = Permission::query()->firstOrCreate(['key' => 'parties.manage']);
            $role->permissions()->syncWithoutDetaching([$access->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    public function test_links_require_authentication_and_permission(): void
    {
        $party = Party::query()->create(['type' => 'company', 'name' => 'Principal']);
        $this->getJson("/api/v1/parties/{$party->id}/links")->assertUnauthorized();
        $this->withToken($this->token(false))->getJson("/api/v1/parties/{$party->id}/links")->assertForbidden();
    }

    public function test_create_list_update_close_and_audit_without_deletion(): void
    {
        $a = Party::query()->create(['type' => 'company', 'name' => 'A']);
        $b = Party::query()->create(['type' => 'person', 'name' => 'B']);
        $token = $this->token(true);
        $url = "/api/v1/parties/{$a->id}/links";
        $id = $this->withToken($token)->postJson($url, [
            'related_party_id' => $b->id, 'description' => 'Contato de referência',
        ])->assertCreated()->assertJsonPath('data.related_name', 'B')->json('data.id');
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->patchJson("$url/$id", ['description' => 'Contato operacional'])
            ->assertOk()->assertJsonPath('changed', true);
        $this->withToken($token)->patchJson("$url/$id", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseCount('party_links', 1);
        $this->assertDatabaseCount('party_events', 3);
    }

    public function test_self_link_duplicate_and_inactive_party_are_rejected(): void
    {
        $a = Party::query()->create(['type' => 'person', 'name' => 'A']);
        $b = Party::query()->create(['type' => 'company', 'name' => 'B']);
        $token = $this->token(true);
        $url = "/api/v1/parties/{$a->id}/links";
        $this->withToken($token)->postJson($url, [
            'related_party_id' => $a->id, 'description' => 'Mesmo cadastro',
        ])->assertUnprocessable();
        $payload = ['related_party_id' => $b->id, 'description' => 'Relação administrativa'];
        $this->withToken($token)->postJson($url, $payload)->assertCreated();
        $this->withToken($token)->postJson($url, $payload)->assertUnprocessable();
        $b->update(['is_active' => false]);
        $this->withToken($token)->postJson($url, [
            'related_party_id' => $b->id, 'description' => 'Outra relação',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('party_links', 1);
    }

    public function test_link_cannot_be_changed_through_unrelated_party(): void
    {
        $a = Party::query()->create(['type' => 'company', 'name' => 'A']);
        $b = Party::query()->create(['type' => 'person', 'name' => 'B']);
        $c = Party::query()->create(['type' => 'company', 'name' => 'C']);
        $token = $this->token(true);
        $id = $this->withToken($token)->postJson("/api/v1/parties/{$a->id}/links", [
            'related_party_id' => $b->id, 'description' => 'Referência',
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->patchJson("/api/v1/parties/{$c->id}/links/$id", [
            'is_active' => false,
        ])->assertNotFound();
    }
}
