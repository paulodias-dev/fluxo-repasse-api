<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use App\Services\MercadoSubscriptions;

final class PlatformBillingController
{
    public function users(Request $request)
    {
        $v = $request->validate(['provider'=>'sometimes|string|max:30','active_since'=>'sometimes|date','created_vehicle'=>'sometimes|in:0,1']);
        $created = DB::table('repasse_cases')->selectRaw('1')->whereColumn('created_by','users.id')->whereColumn('repasse_cases.tenant_id','users.tenant_id');
        $q = DB::table('users')->join('tenants','tenants.id','=','users.tenant_id')
            ->select('users.id','users.name','users.email','tenant_id','tenants.name as company','first_observed_login','last_observed_login','last_login_method','last_activity')
            ->selectSub($created->clone()->limit(1),'created_vehicle');
        if (isset($v['provider'])) $q->where('last_login_method',$v['provider']);
        if (isset($v['active_since'])) $q->where('last_activity','>=',$v['active_since']);
        if (isset($v['created_vehicle'])) $q->whereExists($created, 'and', ! (bool) $v['created_vehicle']);
        return $q->orderBy('users.id')->paginate(50);
    }

    public function plans()
    {
        return DB::table('saas_plans')->orderBy('id')->get();
    }

    public function save(Request $request, ?int $plan = null)
    {
        $v = $request->validate(['name'=>'required|string|max:120','monthly_cents'=>'required|integer|min:0|max:99999999',
            'vehicle_limit'=>'present|nullable|integer|min:0|max:1000000','photo_limit'=>'present|nullable|integer|min:0|max:1000000',
            'available'=>'required|boolean']);
        if ($plan === null) $plan = DB::table('saas_plans')->insertGetId($v);
        else {
            $row = DB::table('saas_plans')->find($plan);
            abort_unless($row,404);
            abort_if($row->default_free && ($v['monthly_cents'] || ! $v['available']),422,'O plano inicial deve ser gratuito e disponível.');
            DB::table('saas_plans')->where('id',$plan)->update($v);
        }
        return response()->json(DB::table('saas_plans')->find($plan));
    }

    public function impact(Request $request, int $plan)
    {
        $p = DB::table('saas_plans')->find($plan);
        abort_unless($p,404);
        $limits = $request->validate(['vehicle_limit'=>'sometimes|integer|min:0','photo_limit'=>'sometimes|integer|min:0']);
        $vehicles = DB::table('repasse_cases')->selectRaw('tenant_id, count(*) as used')->whereNull('sold_on')->where('status','draft')->groupBy('tenant_id');
        $photos = DB::table('repasse_case_evidence')->selectRaw('tenant_id, repasse_case_id, count(*) as used')->whereNull('archived_at')->where('document_type','foto_veiculo')->where('mime','like','image/%')->groupBy('tenant_id','repasse_case_id');
        $max = DB::query()->fromSub($photos,'photos')->selectRaw('tenant_id, max(used) as used')->groupBy('tenant_id');
        $q = DB::table('tenants')->leftJoinSub($vehicles,'v','v.tenant_id','=','tenants.id')
            ->leftJoinSub($max,'p','p.tenant_id','=','tenants.id')
            ->selectRaw('tenants.id, tenants.name, coalesce(v.used,0) as vehicles, coalesce(p.used,0) as max_photos')
            ->whereRaw('coalesce((select plan_id from saas_subscriptions where tenant_id = tenants.id and (environment = ? or environment in (select environment from saas_gateways where active = 1)) and paid_until > ? order by created_at desc limit 1), tenants.saas_plan_id, case when tenants.saas_managed = 1 then (select id from saas_plans where default_free = 1) end) = ?', ['production',now(),$plan]);
        $vl=$limits['vehicle_limit'] ?? $p->vehicle_limit;
        $pl=$limits['photo_limit'] ?? $p->photo_limit;
        return $q->where(function ($q) use ($vl,$pl) {
            $q->whereRaw('1=0');
            if ($vl !== null) $q->orWhere('v.used','>',$vl);
            if ($pl !== null) $q->orWhere('p.used','>',$pl);
        })->orderBy('tenants.id')->paginate(50);
    }

    public function companies(Request $request)
    {
        $input = $request->validate(['search' => ['sometimes', 'string', 'max:120']]);
        $q = DB::table('tenants')->select('id', 'name', 'saas_managed', 'saas_plan_id');
        $search = trim((string) ($input['search'] ?? ''));
        if ($search !== '') {
            $q->where('name', 'like', '%'.$search.'%');
        }
        return $q->orderBy('id')->paginate(50);
    }

    public function migrateCompany(Request $request, int $company)
    {
        $v=$request->validate(['plan_id'=>'required|integer|exists:saas_plans,id']);
        abort_unless(DB::table('saas_plans')->where('id',$v['plan_id'])->where('monthly_cents',0)->where('available',true)->exists(),422);
        abort_unless(DB::table('tenants')->where('id',$company)->exists(),404);
        DB::table('tenants')->where('id',$company)->update(['saas_managed'=>true,'saas_plan_id'=>$v['plan_id']]);
        return response()->noContent();
    }

    public function gateways()
    {
        return DB::table('saas_gateways')->select('environment','active','return_url')->selectRaw('token is not null as configured')->get();
    }

    public function configure(Request $request, string $environment)
    {
        $v=$request->validate(['token'=>'sometimes|required|string|max:1024','secret'=>'sometimes|required|string|max:1024',
            'return_url'=>'sometimes|required|url:https|max:255','active'=>'sometimes|boolean']);
        DB::transaction(function () use ($v,$environment) {
            $rows=DB::table('saas_gateways')->orderBy('environment')->lockForUpdate()->get();
            $row=$rows->firstWhere('environment',$environment);
            abort_unless($row,404);
            foreach (['token','secret'] as $key) if (isset($v[$key])) $v[$key]=Crypt::encryptString($v[$key]);
            if ($v['active'] ?? false) {
                abort_unless(($v['token'] ?? $row->token) && ($v['secret'] ?? $row->secret) && ($v['return_url'] ?? $row->return_url),422);
                DB::table('saas_gateways')->where('environment','!=',$environment)->update(['active'=>false]);
            }
            if ($v) DB::table('saas_gateways')->where('environment',$environment)->update($v);
        });
        return response()->noContent();
    }

    public function probe(string $environment)
    {
        MercadoSubscriptions::call($environment,'get','/users/me');
        return ['connected'=>true];
    }

    public function ledger(int $company)
    {
        abort_unless(DB::table('tenants')->where('id',$company)->exists(),404);
        return ['subscriptions'=>DB::table('saas_subscriptions')->where('tenant_id',$company)->select('id','plan_id','monthly_cents','environment','status','paid_until')->latest('created_at')->paginate(30,['*'],'subscriptions_page'),
            'charges'=>DB::table('saas_charges')->where('tenant_id',$company)->latest('id')->paginate(30,['*'],'charges_page')];
    }
}
