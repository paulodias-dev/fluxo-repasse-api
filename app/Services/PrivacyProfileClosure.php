<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Encerramento do perfil e acesso, não eliminação integral de dados históricos.
 * A execução depende de política aprovada e revisão humana de retenção.
 */
final class PrivacyProfileClosure
{
    public function inspect(int $id, bool $apply = false): array
    {
        return DB::transaction(function () use ($id, $apply): array {
            $request = DB::table('account_deletion_requests')->where('id', $id)->first();
            if (! $request) {
                throw new RuntimeException('Solicitação não encontrada.');
            }
            $user = DB::table('users')->where('id', $request->user_id)
                ->where('tenant_id', $request->tenant_id)->lockForUpdate()->firstOrFail();
            $request = DB::table('account_deletion_requests')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($request->status === 'profile_closed' && $user->privacy_closed_at !== null) {
                return ['status' => 'profile_closed', 'blockers' => []];
            }
            $blockers = [];
            if (! in_array($request->status, ['pending', 'processing'], true)) {
                $blockers[] = 'request_not_pending';
            }
            $policy = config('services.privacy_closure.policy_version');
            if (! config('services.privacy_closure.enabled') || ! is_string($policy) || trim($policy) === '' || strlen($policy) > 80) {
                $blockers[] = 'approved_policy_required';
            }
            // Conservative: never close an administrative account in this workflow.
            // Transfer responsibilities and remove protected roles in the existing admin flow first.
            $privileged = DB::table('role_user as ru')->join('roles as r', 'r.id', '=', 'ru.role_id')
                ->where('ru.user_id', $user->id)->where(function ($q): void {
                    $q->whereIn('r.slug', ['platform_admin', 'tenant_admin'])
                        ->orWhereExists(function ($p): void {
                            $p->selectRaw('1')->from('permission_role as pr')
                                ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                                ->whereColumn('pr.role_id', 'r.id')->whereIn('p.key', ['users.manage', 'roles.manage']);
                        });
                })->exists();
            if ($privileged) { $blockers[] = 'administrator_requires_handover'; }
            if (DB::table('repasse_cases')->where('tenant_id', $user->tenant_id)
                ->where('created_by', $user->id)->where('status', 'draft')->whereNull('admin_deleted_at')->exists()) {
                $blockers[] = 'active_owned_vehicles';
            }
            if ($user->avatar_path !== null) { $blockers[] = 'private_avatar_requires_verified_removal'; }
            if (DB::table('social_accounts')->where('user_id', $user->id)->where('provider', 'apple')
                ->whereNull('apple_revoked_at')->exists()) { $blockers[] = 'apple_revocation_required'; }
            if (DB::table('social_accounts')->where('user_id', $user->id)->where('provider', 'apple')
                ->whereNotNull('apple_refresh_ciphertext')->exists()) { $blockers[] = 'apple_credential_still_present'; }
            if (! $apply || $blockers) {
                return ['status' => $request->status, 'blockers' => $blockers];
            }
            $now = now();
            // No deletion of vehicles, money, historical messages, evidence, tenants or audit history.
            DB::table('native_push_devices')->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('social_accounts')->where('user_id', $user->id)->delete();
            DB::table('role_user')->where('user_id', $user->id)->delete();
            DB::table('user_connections')->where(fn ($q) => $q->where('user_low_id', $user->id)->orWhere('user_high_id', $user->id))
                ->whereIn('status', ['pending','accepted'])->update(['status'=>'blocked','favorite_low'=>false,'favorite_high'=>false,'updated_at'=>$now]);
            DB::table('vehicle_access_grants')->where('recipient_id',$user->id)->where('status','active')
                ->update(['status'=>'revoked','revoked_at'=>$now,'updated_at'=>$now]);
            DB::table('vehicle_collaboration_requests')->where('status','pending')
                ->where(fn ($q) => $q->where('sender_id',$user->id)->orWhere('recipient_id',$user->id)->orWhere('recipient_email',$user->email))
                ->update(['status'=>'revoked','token_hash'=>null,'resolved_at'=>$now,'updated_at'=>$now]);
            DB::table('collab_conversation_members')->where('user_id',$user->id)->whereNull('removed_at')
                ->update(['removed_at'=>$now,'updated_at'=>$now]);
            DB::table('collab_notifications')->where('recipient_id',$user->id)->delete();
            // Preserve an opaque foreign key for accounting; remove direct profile identifiers.
            DB::table('users')->where('id',$user->id)->where('tenant_id',$user->tenant_id)->update([
                'name'=>'Perfil encerrado', 'email'=>'closed-'.Str::uuid().'@invalid.example',
                'phone'=>null,'company_name'=>null,'preferences'=>null,'avatar_external_url'=>null,
                'email_verified_at'=>null,'password'=>Hash::make(Str::random(96)),'remember_token'=>null,
                'is_active'=>false,'auth_version'=>(int)$user->auth_version+1,'privacy_closed_at'=>$now,'updated_at'=>$now,
            ]);
            DB::table('account_deletion_requests')->where('id',$id)->update([
                'status'=>'profile_closed','profile_closed_at'=>$now,'policy_version'=>$policy,'updated_at'=>$now,
            ]);
            DB::table('auth_security_events')->insert([
                'tenant_id'=>$user->tenant_id,'user_id'=>$user->id,'actor_user_id'=>null,
                'event_type'=>'account_profile_closed','ip_hash'=>null,'created_at'=>$now,
            ]);
            return ['status'=>'profile_closed','blockers'=>[]];
        }, 3);
    }
}
