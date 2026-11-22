<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantRoles;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlatformBillingTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Tenant
    {
        $this->seed(AdminUserSeeder::class);
        $tenant=Tenant::create(['name'=>'Teste','slug'=>'plan-test','is_active'=>true]);
        $u=User::create(['tenant_id'=>$tenant->id,'name'=>'Admin','email'=>'billing@example.invalid',
            'password'=>'Senha&Aleatoria12345','email_verified_at'=>now()]);
        $roles=TenantRoles::provision($tenant->id);
        $u->roles()->sync([$roles['tenant_admin']->id]);
        $this->withToken(auth('api')->login($u));
        return $tenant;
    }

    public function test_quota_replay_reduction_and_tenant_admin_boundary(): void
    {
        $this->company();
        DB::table('saas_plans')->where('default_free',true)->update(['vehicle_limit'=>1]);
        $payload=['title'=>'Primeiro','request_key'=>(string)Str::uuid()];
        $this->postJson('/api/v1/repasse-cases',$payload)->assertCreated();
        $this->postJson('/api/v1/repasse-cases',$payload)->assertOk();
        DB::table('saas_plans')->where('default_free',true)->update(['vehicle_limit'=>0]);
        $this->postJson('/api/v1/repasse-cases',['title'=>'Segundo'])->assertConflict()->assertJsonPath('quota','vehicle_limit');
        $this->getJson('/api/v1/billing/overview')->assertOk()->assertJsonPath('active_vehicles',1);
        $this->getJson('/api/v1/platform/users')->assertForbidden();
    }

    public function test_only_verified_charge_unlocks_plan_and_replay_does_not_extend_it(): void
    {
        $tenant=$this->company();
        $id=(string)Str::uuid();
        $plan=DB::table('saas_plans')->insertGetId(['name'=>'Pago','monthly_cents'=>1990]);
        DB::table('saas_subscriptions')->insert(['tenant_id'=>$tenant->id,'id'=>$id,'plan_id'=>$plan,
            'monthly_cents'=>1990,'environment'=>'test','provider_id'=>'agreement','status'=>'authorized','created_at'=>now(),'updated_at'=>now()]);
        DB::table('saas_gateways')->where('environment','test')->update(['token'=>Crypt::encryptString('fake'),'secret'=>Crypt::encryptString('secret'),'active'=>true]);
        $this->getJson('/api/v1/billing/overview')->assertJsonPath('plan.name','Gratuito');
        $url='/api/v1/billing/notifications/test?data.id=123';
        $this->postJson($url,['type'=>'subscription_authorized_payment'])->assertForbidden();
        Http::fake(['*/authorized_payments/123'=>Http::response(['preapproval_id'=>'agreement','payment'=>['id'=>77],'debit_date'=>now()->toIso8601String()]),
            '*/v1/payments/77'=>Http::response(['id'=>77,'currency_id'=>'BRL','live_mode'=>false,'status'=>'approved','transaction_amount'=>19.90])]);
        $ts=(string)time();
        $sig=hash_hmac('sha256',"id:123;request-id:test;ts:{$ts};",'secret');
        $headers=['x-request-id'=>'test','x-signature'=>"ts={$ts},v1={$sig}"];
        $this->postJson($url,['type'=>'subscription_authorized_payment'],$headers)->assertNoContent();
        $until=DB::table('saas_subscriptions')->where('id',$id)->value('paid_until');
        $this->postJson($url,['type'=>'subscription_authorized_payment'],$headers)->assertNoContent();
        $this->assertDatabaseCount('saas_charges',1);
        $this->assertDatabaseHas('saas_subscriptions',['id'=>$id,'paid_until'=>$until]);
        $this->getJson('/api/v1/billing/overview')->assertJsonPath('plan.name','Pago');
    }
}
