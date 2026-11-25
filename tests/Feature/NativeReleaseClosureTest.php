<?php
namespace Tests\Feature;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class NativeReleaseClosureTest extends TestCase
{
    use RefreshDatabase;
    private function account(bool $allowed = false): array
    {
        config()->set('jwt.secret',str_repeat('native-closure-qa-',4));
        request()->attributes->remove('verified_tenant_id');
        $u=User::factory()->create(['password'=>'Strong-Passphrase-123!']);
        if($allowed){$role=Role::query()->firstOrCreate(['tenant_id'=>$u->tenant_id,'slug'=>'native_bi_qa'],['name'=>'Operador']);$permission=Permission::query()->firstOrCreate(['key'=>'cases.manage']);$role->permissions()->syncWithoutDetaching([$permission->id]);$u->roles()->syncWithoutDetaching([$role->id]);}
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
    public function test_bi_counts_only_sales_in_the_own_tenant_and_period(): void
    {
        [$a,$jwtA]=$this->account(true);[$b,$jwtB]=$this->account(true);
        foreach([[$jwtA,100000,160000],[$jwtB,100000,900000]] as [$jwt,$purchase,$sale]){
            $id=$this->withToken($jwt)->postJson('/api/v1/repasse-cases',['title'=>'Veículo QA','plate'=>'ABC1D23','brand'=>'Toyota','model'=>'Corolla','purchase_amount_cents'=>$purchase])->assertCreated()->json('data.id');
            DB::table('repasse_cases')->where('id',$id)->update(['purchased_on'=>'2026-10-01','sold_on'=>'2026-11-01','sale_amount_cents'=>$sale]);
        }
        $this->withToken($jwtA)->getJson('/api/v1/operations/native-bi?from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertJsonPath('data.sold_count',1)->assertJsonPath('data.result_cents',60000)->assertJsonPath('data.known_count',1)->assertJsonPath('data.settlements',null);
    }

    public function test_bi_requires_permission(): void
    {
        [, $jwt]=$this->account();
        $this->withToken($jwt)->getJson('/api/v1/operations/native-bi?from=2026-11-01&to=2026-11-25')->assertForbidden();
    }
}
