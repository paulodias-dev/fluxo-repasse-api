<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class NativeReleaseClosureTest extends TestCase
{
    use RefreshDatabase;
    private function account(): array
    {
        config()->set('jwt.secret',str_repeat('native-closure-qa-',4));
        request()->attributes->remove('verified_tenant_id');
        $u=User::factory()->create(['password'=>'Strong-Passphrase-123!']);
        $jwt=$this->postJson('/api/v1/auth/login',['email'=>$u->email,'password'=>'Strong-Passphrase-123!'])->assertOk()->json('access_token');
        return [$u,$jwt];
    }
    public function test_deletion_is_self_scoped_idempotent_and_cancellable(): void
    {
        [$a,$jwtA]=$this->account();[$b,$jwtB]=$this->account();
        $this->getJson('/api/v1/auth/privacy/deletion')->assertUnauthorized();
        $this->withToken($jwtA)->postJson('/api/v1/auth/privacy/deletion',['confirm'=>true,'user_id'=>$b->id])->assertUnprocessable();
        $this->withToken($jwtA)->postJson('/api/v1/auth/privacy/deletion',['confirm'=>true])->assertOk()->assertJsonPath('data.status','pending');
        $this->withToken($jwtA)->postJson('/api/v1/auth/privacy/deletion',['confirm'=>true])->assertOk();
        $this->assertDatabaseCount('account_deletion_requests',1);
        $this->withToken($jwtB)->getJson('/api/v1/auth/privacy/deletion')->assertOk()->assertJsonPath('data',null);
        $this->withToken($jwtB)->deleteJson('/api/v1/auth/privacy/deletion')->assertOk()->assertJsonPath('data',null);
        $this->withToken($jwtA)->deleteJson('/api/v1/auth/privacy/deletion')->assertOk()->assertJsonPath('data.status','cancelled');
        $this->assertDatabaseHas('users',['id'=>$a->id,'is_active'=>true]);
    }
    public function test_bi_requires_permission(): void
    {
        [, $jwt]=$this->account();
        $this->withToken($jwt)->getJson('/api/v1/operations/native-bi?from=2026-11-01&to=2026-11-25')->assertForbidden();
    }
}
