<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\CompanyPlan;
use App\Services\MercadoSubscriptions;
use App\Support\TenantContext;
use App\Support\TenantDB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CompanyBillingController
{
    public function overview(Request $request)
    {
        $data=$request->validate(['vehicle_id'=>'sometimes|integer|min:1']);
        $case=$data['vehicle_id'] ?? null;
        if ($case) TenantDB::table('repasse_cases')->where('id',$case)->firstOrFail();
        return ['plan'=>CompanyPlan::current(),'active_vehicles'=>CompanyPlan::vehicles(),
            'vehicle_photos'=>$case ? CompanyPlan::photos($case) : null,
            'offers'=>DB::table('saas_plans')->where('available',true)->orderBy('monthly_cents')->get(),
            'subscription'=>DB::table('saas_subscriptions')->where('tenant_id',TenantContext::requireId())
                ->latest('created_at')->first(['id','plan_id','status','paid_until','monthly_cents'])];
    }

    public function checkout(Request $request)
    {
        $v=$request->validate(['plan_id'=>'required|integer']);
        $user=$request->user('api');
        abort_unless($user->roles()->where('roles.tenant_id',TenantContext::requireId())->where('slug','tenant_admin')->exists(),403);
        $sub=DB::transaction(function () use ($v) {
            $tenant=TenantContext::requireId();
            DB::table('tenants')->where('id',$tenant)->lockForUpdate()->firstOrFail();
            $open=DB::table('saas_subscriptions')->where('tenant_id',$tenant)->whereNotIn('status',['cancelled','expired'])->first();
            if ($open) {
                abort_if((int)$open->plan_id !== (int)$v['plan_id'],409,'Já existe uma assinatura em andamento.');
                return $open;
            }
            $p=DB::table('saas_plans')->where('id',$v['plan_id'])->where('available',true)->where('monthly_cents','>',0)->firstOrFail();
            $g=DB::table('saas_gateways')->where('active',true)->first();
            abort_unless($g,503,'Mercado Pago não configurado.');
            $id=(string)Str::uuid();
            DB::table('saas_subscriptions')->insert(['id'=>$id,'tenant_id'=>$tenant,'plan_id'=>$p->id,'monthly_cents'=>$p->monthly_cents,
                'environment'=>$g->environment,'created_at'=>now(),'updated_at'=>now()]);
            return DB::table('saas_subscriptions')->find($id);
        });
        return DB::transaction(function () use ($sub,$user) {
            $s=DB::table('saas_subscriptions')->where('id',$sub->id)->lockForUpdate()->firstOrFail();
            abort_if($s->status === 'cancelled',409);
            if (! $s->provider_id) {
                $g=DB::table('saas_gateways')->where('environment',$s->environment)->firstOrFail();
                $p=DB::table('saas_plans')->find($s->plan_id);
                // Recupera um acordo criado antes de uma resposta perdida.
                $matches=MercadoSubscriptions::call($s->environment,'get','/preapproval/search',[
                    'external_reference'=>$s->id,
                ]);
                $remote=collect($matches['results'] ?? [])->firstWhere('external_reference',$s->id);
                $remote ??= MercadoSubscriptions::call($s->environment,'post','/preapproval',[
                    'external_reference'=>$s->id,'reason'=>$p->name,'payer_email'=>$user->email,'back_url'=>$g->return_url,
                    'status'=>'pending','auto_recurring'=>['frequency'=>1,'frequency_type'=>'months',
                        'transaction_amount'=>$s->monthly_cents/100,'currency_id'=>'BRL'],
                ],$s->id);
                abort_unless(!empty($remote['id']) && !empty($remote['init_point']),502);
                $s->checkout_url=$remote['init_point'];
                DB::table('saas_subscriptions')->where('id',$s->id)->update(['provider_id'=>$remote['id'],'checkout_url'=>$s->checkout_url,'updated_at'=>now()]);
            }
            return ['id'=>$s->id,'status'=>$s->status,'checkout_url'=>$s->checkout_url];
        });
    }

    public function cancel(Request $request, string $subscription)
    {
        abort_unless($request->user('api')->roles()->where('roles.tenant_id',TenantContext::requireId())
            ->where('slug','tenant_admin')->exists(),403);
        return DB::transaction(function () use ($subscription) {
            $sub=DB::table('saas_subscriptions')->where('tenant_id',TenantContext::requireId())
                ->where('id',$subscription)->lockForUpdate()->firstOrFail();
            if ($sub->status !== 'cancelled' && $sub->provider_id) {
                $remote=MercadoSubscriptions::call($sub->environment,'put','/preapproval/'.rawurlencode($sub->provider_id),['status'=>'cancelled']);
                abort_unless(($remote['status'] ?? null) === 'cancelled',502);
            }
            DB::table('saas_subscriptions')->where('id',$sub->id)->update(['status'=>'cancelled','updated_at'=>now()]);
            return response()->noContent();
        });
    }

    public function webhook(Request $request,string $environment)
    {
        MercadoSubscriptions::notify($request,$environment);
        return response()->noContent();
    }
}
