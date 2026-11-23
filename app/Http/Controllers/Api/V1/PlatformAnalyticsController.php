<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only, platform-wide aggregates. Never returns operational records,
 * chat messages, personal finance, attachments or other tenants' private data.
 * Access is guarded by PlatformOperator + JWT/session middleware in routes/billing.php.
 */
final class PlatformAnalyticsController
{
    private function filters(Request $request): array
    {
        $input = $request->validate([
            'days' => ['sometimes', 'integer', 'in:7,30,90,365'],
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d'],
            'environment' => ['sometimes', 'in:production,test'],
        ]);
        if (isset($input['days']) && isset($input['from'])) {
            throw ValidationException::withMessages(['days' => 'Use período fixo ou datas personalizadas, não ambos.']);
        }
        $today = CarbonImmutable::today();
        $start = isset($input['from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $input['from'])->startOfDay()
            : $today->subDays(((int) ($input['days'] ?? 30)) - 1);
        $end = isset($input['to'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $input['to'])->endOfDay()
            : $today->endOfDay();
        $days = $start->diffInDays($end->startOfDay()) + 1;
        if ($start->gt($end) || $days < 1 || $days > 366 ||
            $start->gt($today) || $end->startOfDay()->gt($today)) {
            throw ValidationException::withMessages(['from' => 'Selecione de 1 a 366 dias, sem datas futuras.']);
        }

        return [
            'from' => $start,
            'to' => $end,
            'previous_from' => $start->subDays($days),
            'previous_to' => $start->subSecond(),
            'days' => (int) $days,
            'environment' => $input['environment'] ?? 'production',
        ];
    }

    private function windowCount(string $table, array $dates, ?callable $scope = null): int
    {
        $q = DB::table($table)->whereBetween('created_at', $dates);
        if ($scope) $scope($q);

        return $q->count();
    }

    /** An eligible recurring subscription is paid through today and still renewable. */
    private function recurringQuery(string $environment)
    {
        return DB::table('saas_subscriptions as s')
            ->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->where('t.is_active', true)
            ->where('s.environment', $environment)
            ->whereIn('s.status', ['authorized', 'active'])
            ->where('s.paid_until', '>', now())
            ->whereNotExists(function ($query) use ($environment): void {
                $query->selectRaw('1')->from('saas_subscriptions as later')
                    ->whereColumn('later.tenant_id', 's.tenant_id')
                    ->where('later.environment', $environment)
                    ->whereIn('later.status', ['authorized', 'active'])
                    ->where('later.paid_until', '>', now())
                    ->where(function ($order): void {
                        $order->whereColumn('later.created_at', '>', 's.created_at')
                            ->orWhere(function ($tie): void {
                                $tie->whereColumn('later.created_at', 's.created_at')
                                    ->whereColumn('later.id', '>', 's.id');
                            });
                    });
            });
    }

    private function series(array $period): array
    {
        $monthly = $period['days'] > 90;
        $format = $monthly ? 'Y-m' : 'Y-m-d';
        $points = [];
        for ($day = $period['from']; $day->lte($period['to']); $day = $day->addDay()) {
            $key = $day->format($format);
            $points[$key] ??= ['date' => $key, 'companies' => 0, 'users' => 0,
                'vehicles' => 0, 'revenue_cents' => 0];
        }
        foreach (['tenants' => 'companies', 'users' => 'users', 'repasse_cases' => 'vehicles'] as $table => $metric) {
            $rows = DB::table($table)->when($table === 'repasse_cases', fn ($query) => $query->whereNull('admin_deleted_at'))
                ->whereBetween('created_at', [$period['from'], $period['to']])
                ->selectRaw('DATE(created_at) as metric_date, COUNT(*) as aggregate')
                ->groupByRaw('DATE(created_at)')->get();
            foreach ($rows as $row) {
                $key = substr((string) $row->metric_date, 0, $monthly ? 7 : 10);
                if (isset($points[$key])) $points[$key][$metric] += (int) $row->aggregate;
            }
        }
        $charges = DB::table('saas_charges')
            ->where('environment', $period['environment'])->where('status', 'approved')
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->selectRaw('DATE(created_at) as metric_date, SUM(amount_cents) as aggregate')
            ->groupByRaw('DATE(created_at)')->get();
        foreach ($charges as $row) {
            $key = substr((string) $row->metric_date, 0, $monthly ? 7 : 10);
            if (isset($points[$key])) $points[$key]['revenue_cents'] += (int) $row->aggregate;
        }

        return array_values($points);
    }

    private function attention(): array
    {
        $alerts = [];
        $lastActivity = DB::table('users')
            ->selectRaw('tenant_id, MAX(last_activity) as last_activity')
            ->groupBy('tenant_id');
        $idle = DB::table('tenants as t')->leftJoinSub($lastActivity, 'u', 'u.tenant_id', '=', 't.id')
            ->where('t.is_active', true)->where('t.created_at', '<', now()->subDays(15))
            ->where(function ($q): void {
                $q->whereNull('u.last_activity')->orWhere('u.last_activity', '<', now()->subDays(15));
            })->orderBy('u.last_activity')->limit(5)
            ->get(['t.id', 't.name', 'u.last_activity']);
        foreach ($idle as $company) {
            $alerts[] = [
                'kind' => 'idle_company', 'severity' => 'warning', 'company_id' => (int) $company->id,
                'title' => 'Empresa sem atividade recente',
                'detail' => $company->name,
                'since' => $company->last_activity,
            ];
        }
        $expiring = $this->recurringQuery('production')
            ->where('s.paid_until', '<=', now()->addDays(7))
            ->orderBy('s.paid_until')->limit(5)
            ->get(['t.id as company_id', 't.name', 's.paid_until']);
        foreach ($expiring as $row) {
            $alerts[] = [
                'kind' => 'subscription_expiring', 'severity' => 'warning',
                'company_id' => (int) $row->company_id,
                'title' => 'Assinatura próxima do vencimento',
                'detail' => $row->name,
                'since' => $row->paid_until,
            ];
        }

        return $alerts;
    }

    public function overview(Request $request): JsonResponse
    {
        $p = $this->filters($request);
        $current = [$p['from'], $p['to']];
        $previous = [$p['previous_from'], $p['previous_to']];
        $environment = $p['environment'];
        $charges = fn (array $window) => DB::table('saas_charges')
            ->where('environment', $environment)->where('status', 'approved')
            ->whereBetween('created_at', $window);
        $recurring = $this->recurringQuery($environment);
        $activeUsers = DB::table('users')->where('is_active', true)
            ->whereBetween('last_activity', $current)->count();
        $engagedCompanies = DB::table('users')->whereBetween('last_activity', $current)
            ->distinct()->count('tenant_id');
        $metrics = [
            'companies_total' => DB::table('tenants')->count(),
            'companies_active' => DB::table('tenants')->where('is_active', true)->count(),
            'companies_new' => $this->windowCount('tenants', $current),
            'companies_new_previous' => $this->windowCount('tenants', $previous),
            'users_total' => DB::table('users')->count(),
            'users_active' => $activeUsers,
            'users_new' => $this->windowCount('users', $current),
            'users_new_previous' => $this->windowCount('users', $previous),
            'companies_engaged' => $engagedCompanies,
            'vehicles_active' => DB::table('repasse_cases')->whereNull('admin_deleted_at')
                ->where('status', 'draft')->whereNull('sold_on')->count(),
            'vehicles_new' => $this->windowCount('repasse_cases', $current, fn ($q) => $q->whereNull('admin_deleted_at')),
            'vehicles_new_previous' => $this->windowCount('repasse_cases', $previous, fn ($q) => $q->whereNull('admin_deleted_at')),
            'vehicles_sold' => DB::table('repasse_cases')->whereNull('admin_deleted_at')->whereNotNull('sold_on')
                ->whereBetween('sold_on', [$p['from']->toDateString(), $p['to']->toDateString()])->count(),
            'subscriptions_recurring' => (clone $recurring)->count(),
            'mrr_cents' => (int) (clone $recurring)->sum('s.monthly_cents'),
            'revenue_cents' => (int) $charges($current)->sum('amount_cents'),
            'revenue_previous_cents' => (int) $charges($previous)->sum('amount_cents'),
            'charges_approved' => $charges($current)->count(),
            'charges_pending' => DB::table('saas_charges')->where('environment', $environment)
                ->whereIn('status', ['pending', 'in_process'])
                ->whereBetween('created_at', $current)->count(),
        ];
        $plans = DB::table('tenants as t')
            ->leftJoin('saas_plans as p', 'p.id', '=', 't.saas_plan_id')
            ->selectRaw("COALESCE(p.name, CASE WHEN t.saas_managed = 0 THEN 'Legado' ELSE 'Gratuito (base)' END) as name, COUNT(*) as companies")
            ->groupByRaw("COALESCE(p.name, CASE WHEN t.saas_managed = 0 THEN 'Legado' ELSE 'Gratuito (base)' END)")
            ->orderByDesc('companies')->get();

        return response()->json([
            'period' => [
                'from' => $p['from']->toDateString(), 'to' => $p['to']->toDateString(),
                'days' => $p['days'], 'environment' => $environment,
                'generated_at' => now()->toIso8601String(),
            ],
            'metrics' => $metrics,
            'series' => $this->series($p),
            'plans' => $plans,
            'attention' => $this->attention(),
            'definitions' => [
                'mrr' => 'Soma das mensalidades de uma assinatura renovável e vigente por empresa. Não é receita recebida.',
                'revenue' => 'Cobranças com status approved registradas no período, conforme ambiente selecionado.',
                'users_active' => 'Contas ativas cuja última atividade registrada ocorreu no período. Não recompõe DAU/MAU históricos.',
                'plans' => 'Distribuição por plano base cadastrado. Não representa necessariamente o plano pago efetivo.',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function company(Request $request, int $company): JsonResponse
    {
        $p = $this->filters($request);
        $tenant = DB::table('tenants')->where('id', $company)
            ->first(['id', 'name', 'slug', 'is_active', 'saas_managed', 'saas_plan_id', 'created_at']);
        abort_unless($tenant, 404);
        $users = DB::table('users')->where('tenant_id', $company);
        $vehicles = DB::table('repasse_cases')->where('tenant_id', $company)->whereNull('admin_deleted_at');
        $vehicleList = DB::table('repasse_cases as v')
            ->where('v.tenant_id', $company)->whereNull('v.admin_deleted_at')
            ->select([
                'v.id', 'v.title', 'v.status', 'v.plate', 'v.brand', 'v.model',
                'v.model_year', 'v.sold_on', 'v.created_at',
            ])->selectSub(
                DB::table('repasse_case_evidence as photo')
                    ->whereColumn('photo.repasse_case_id', 'v.id')->where('photo.tenant_id', $company)
                    ->whereNull('photo.archived_at')->where('photo.mime', 'like', 'image/%')
                    ->where(function ($type): void {
                        $type->where('photo.document_type', 'foto_veiculo')->orWhereNull('photo.document_type');
                    })->orderByDesc('photo.is_cover')->orderByDesc('photo.id')->limit(1)->select('photo.id'),
                'image_document_id'
            )->orderByDesc('v.updated_at')->orderByDesc('v.id')->limit(100)->get();
        $subscription = DB::table('saas_subscriptions')->where('tenant_id', $company)
            ->where('environment', 'production')->orderByDesc('created_at')->first([
                'id', 'status', 'plan_id', 'monthly_cents', 'paid_until', 'environment',
            ]);
        $paid = $this->recurringQuery('production')->where('s.tenant_id', $company)->first([
            's.id', 's.plan_id', 's.monthly_cents', 's.paid_until',
        ]);
        $planId = $paid?->plan_id ?? $tenant->saas_plan_id;
        $plan = $planId ? DB::table('saas_plans')->where('id', $planId)
            ->first(['id', 'name', 'monthly_cents', 'vehicle_limit', 'photo_limit'])
            : ($tenant->saas_managed ? DB::table('saas_plans')->where('default_free', true)
                ->first(['id', 'name', 'monthly_cents', 'vehicle_limit', 'photo_limit']) : null);
        $maxPhotos = (int) DB::table('repasse_case_evidence')->where('tenant_id', $company)
            ->where('document_type', 'foto_veiculo')->where('mime', 'like', 'image/%')
            ->whereNull('archived_at')->selectRaw('repasse_case_id, COUNT(*) as total')
            ->groupBy('repasse_case_id')->orderByDesc('total')->first()?->total;
        $charges = DB::table('saas_charges')->where('tenant_id', $company)
            ->where('environment', 'production')->where('status', 'approved')
            ->whereBetween('created_at', [$p['from'], $p['to']]);
        return response()->json([
            'company' => $tenant,
            'period' => ['from' => $p['from']->toDateString(), 'to' => $p['to']->toDateString()],
            'usage' => [
                'users_total' => (clone $users)->count(),
                'users_active' => (clone $users)->where('is_active', true)
                    ->whereBetween('last_activity', [$p['from'], $p['to']])->count(),
                'last_activity' => (clone $users)->max('last_activity'),
                'vehicles_total' => (clone $vehicles)->count(),
                'vehicles_active' => (clone $vehicles)->where('status', 'draft')
                    ->whereNull('sold_on')->count(),
                'vehicles_sold' => (clone $vehicles)->whereNotNull('sold_on')->count(),
                'max_photos_per_vehicle' => $maxPhotos,
            ],
            'vehicles' => $vehicleList,
            'billing' => [
                'plan' => $plan, 'legacy' => ! (bool) $tenant->saas_managed,
                'latest_subscription' => $subscription,
                'recurring_cents' => $paid ? (int) $paid->monthly_cents : 0,
                'received_cents' => (int) (clone $charges)->sum('amount_cents'),
                'charges_approved' => (clone $charges)->count(),
                'environment' => 'production',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
