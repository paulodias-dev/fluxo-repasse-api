<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantRoles;
use App\Services\PrivacyProfileClosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PrivacyProfileClosureTest extends TestCase
{
    use RefreshDatabase;

    private function requestFor(User $user): int
    {
        return DB::table('account_deletion_requests')->insertGetId([
            'tenant_id'=>$user->tenant_id,'user_id'=>$user->id,'status'=>'pending','requested_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    public function test_disabled_policy_and_privileged_accounts_fail_closed(): void
    {
        $user=User::factory()->create();
        $id=$this->requestFor($user);
        $service=app(PrivacyProfileClosure::class);
        config()->set('services.privacy_closure.enabled', false);
        $this->assertContains('approved_policy_required',$service->inspect($id,true)['blockers']);
        config()->set('services.privacy_closure.enabled',true);
        config()->set('services.privacy_closure.policy_version','qa-policy');
        $roles=TenantRoles::provision((int)$user->tenant_id);
        $user->roles()->sync([$roles['tenant_admin']->id]);
        $this->assertContains('administrator_requires_handover',$service->inspect($id,true)['blockers']);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_preview_then_profile_closure_is_idempotent_and_preserves_other_user(): void
    {
        config()->set('services.privacy_closure.enabled',true);
        config()->set('services.privacy_closure.policy_version','qa-policy');
        $user=User::factory()->create();
        $other=User::factory()->create();
        $id=$this->requestFor($user);
        $service=app(PrivacyProfileClosure::class);
        $this->assertSame([], $service->inspect($id)['blockers']);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame('profile_closed',$service->inspect($id,true)['status']);
        $closed=$user->fresh();
        $this->assertFalse($closed->is_active);
        $this->assertNotSame($user->email,$closed->email);
        $this->assertNotNull($closed->privacy_closed_at);
        $this->assertTrue($other->fresh()->is_active);
        $this->assertSame('profile_closed',$service->inspect($id,true)['status']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $closed->is_active=true;
        $closed->save();
    }
}
