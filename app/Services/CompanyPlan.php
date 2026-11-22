<?php

namespace App\Services;

use App\Support\TenantContext;
use App\Support\TenantDB;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;

final class CompanyPlan
{
    public static function current(): ?object
    {
        $tenant = TenantContext::requireId();
        $paid = DB::table('saas_subscriptions')->where('tenant_id', $tenant)
            ->where(function ($q) {
                $q->where('environment','production')->orWhereIn('environment',DB::table('saas_gateways')->select('environment')->where('active',true));
            })->where('paid_until', '>', now())->latest('created_at')->value('plan_id');
        $id = $paid ?? DB::table('tenants')->where('id', $tenant)->value('saas_plan_id');
        if ($id === null && DB::table('tenants')->where('id',$tenant)->value('saas_managed')) {
            return DB::table('saas_plans')->where('default_free',true)->firstOrFail();
        }
        return DB::table('saas_plans')->where('id', $id)->first();
    }

    public static function vehicles(): int
    {
        return TenantDB::table('repasse_cases')->where('status','draft')->whereNull('sold_on')->count();
    }

    public static function photos(int $case): int
    {
        return TenantDB::table('repasse_case_evidence')->where('repasse_case_id', $case)
            ->where('document_type','foto_veiculo')->where('mime','like','image/%')->whereNull('archived_at')->count();
    }

    public static function check(?int $case = null): void
    {
        $plan = self::current();
        $key = $case === null ? 'vehicle_limit' : 'photo_limit';
        $limit = $plan?->{$key};
        if ($limit === null) return;
        $used = $case === null ? self::vehicles() : self::photos($case);
        if ($used >= $limit) {
            throw new HttpResponseException(response()->json([
                'message' => 'Limite do plano atingido.', 'code' => 'plan_limit_reached',
                'quota' => $key, 'limit' => $limit, 'used' => $used,
            ], 409));
        }
    }
}
