<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\TenantTable;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operational read model for the existing documentary workflow.
 *
 * A documentary review (including "reviewed") is never a payment approval.
 * No writes, no synthesized financial obligations, no additional grants.
 * All joins are scoped by the authenticated vehicle and the route's RBAC.
 */
final class VehicleAccountabilityController extends Controller
{
    private const REVIEW_STATES = [
        'all', 'not_requested', 'pending', 'reviewed',
        'changes_requested', 'withdrawn', 'stale', 'archived',
    ];

    public function index(Request $request, int $case): JsonResponse
    {
        $input = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'archived'])],
            'review_state' => ['sometimes', Rule::in(self::REVIEW_STATES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $vehicle = TenantTable::table('repasse_cases')->where('id', $case)
            ->select('id', 'title', 'status', 'plate', 'brand', 'model', 'updated_at')
            ->firstOrFail();

        $base = $this->portfolio($case);

        $whole = DB::query()->fromSub($base, 'portfolio');
        $totalsRow = (clone $whole)->selectRaw("
            COUNT(*) AS scenarios_total,
            COALESCE(SUM(CASE WHEN review_state <> 'archived' THEN 1 ELSE 0 END), 0) AS scenarios_active,
            COALESCE(SUM(CASE WHEN review_state <> 'archived' AND difference_cents = 0 THEN 1 ELSE 0 END), 0) AS scenarios_balanced,
            COALESCE(SUM(CASE WHEN review_state = 'not_requested' THEN 1 ELSE 0 END), 0) AS not_requested,
            COALESCE(SUM(CASE WHEN review_state = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN review_state = 'reviewed' THEN 1 ELSE 0 END), 0) AS reviewed,
            COALESCE(SUM(CASE WHEN review_state = 'changes_requested' THEN 1 ELSE 0 END), 0) AS changes_requested,
            COALESCE(SUM(CASE WHEN review_state = 'withdrawn' THEN 1 ELSE 0 END), 0) AS withdrawn,
            COALESCE(SUM(CASE WHEN review_state = 'stale' THEN 1 ELSE 0 END), 0) AS stale,
            COALESCE(SUM(CASE WHEN review_state = 'archived' THEN 1 ELSE 0 END), 0) AS archived
        ")->first();
        $totals = [];
        foreach ((array) $totalsRow as $key => $value) $totals[$key] = (int) $value;

        $table = $this->filtered($base, $input);

        $page = $table->orderByDesc('portfolio.updated_at')->orderByDesc('portfolio.id')
            ->paginate(20);
        $page->through(static function (object $row): object {
            foreach ([
                'id', 'revision', 'gross_amount_cents', 'allocation_count',
                'allocated_cents', 'difference_cents',
                'last_review_id', 'last_review_revision',
                'reviewed_scenario_revision', 'reviewer_id', 'created_by',
            ] as $key) {
                if ($row->{$key} !== null) $row->{$key} = (int) $row->{$key};
            }

            return $row;
        });

        $documents = TenantTable::table('repasse_case_evidence')->where('repasse_case_id', $case)
            ->whereNull('archived_at')->count();
        $parties = TenantTable::table('repasse_case_parties as cp')
            ->join('parties as p', 'p.id', '=', 'cp.party_id')
            ->where('cp.repasse_case_id', $case)->where('p.is_active', true)->count();
        $pendingTasks = TenantTable::table('repasse_case_tasks')
            ->where('repasse_case_id', $case)->whereIn('status', ['pending', 'doing'])->count();
        $pendingChecklist = TenantTable::table('repasse_case_checklist')
            ->where('repasse_case_id', $case)->whereNull('checked_at')->count();
        $packages = TenantTable::table('repasse_case_packages')
            ->where('repasse_case_id', $case)
            ->select('id', 'title', 'manifest_sha256', 'created_at')
            ->orderByDesc('id')->first();
        $packageCount = TenantTable::table('repasse_case_packages')
            ->where('repasse_case_id', $case)->count();

        return response()->json([
            'data' => [
                'vehicle' => $vehicle,
                'summary' => $totals + [
                    'active_evidence' => $documents,
                    'active_parties' => $parties,
                    'open_tasks' => $pendingTasks,
                    'pending_checklist' => $pendingChecklist,
                    'packages_total' => $packageCount,
                ],
                'scenarios' => $page,
                'latest_package' => $packages,
                'as_of' => now()->toIso8601String(),
                'financial_authorization' => false,
                'payment_enabled' => false,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * All views, exports and counters use the SAME documentary state
     * projection. Latest review is fixed by MAX(id), not by a random join.
     */
    private function portfolio(int $case): Builder
    {
        // Each scenario receives at most one latest review and one allocation
        // aggregate. Filtering occurs OUTSIDE this projection so the same
        // review-state logic is used for KPIs and paginated table rows.
        $latestReview = TenantTable::table('repasse_scenario_reviews')
            ->select('scenario_id')->selectRaw('MAX(id) AS latest_id')
            ->groupBy('scenario_id');
        $allocations = TenantTable::table('repasse_scenario_allocations')
            ->select('repasse_scenario_id')->selectRaw('COUNT(*) AS allocation_count')
            ->selectRaw('COALESCE(SUM(amount_cents), 0) AS allocated_cents')
            ->groupBy('repasse_scenario_id');

        $state = "CASE
            WHEN s.archived_at IS NOT NULL OR c.status <> 'draft' THEN 'archived'
            WHEN r.id IS NULL THEN 'not_requested'
            WHEN r.scenario_revision <> s.revision OR r.scenario_digest <> s.current_digest THEN 'stale'
            WHEN r.status = 'pending' THEN 'pending'
            WHEN r.status = 'reviewed' THEN 'reviewed'
            WHEN r.status = 'changes_requested' THEN 'changes_requested'
            WHEN r.status = 'withdrawn' THEN 'withdrawn'
            ELSE 'not_requested' END";

        return TenantTable::table('repasse_scenarios as s')
            ->join('repasse_cases as c', 'c.id', '=', 's.repasse_case_id')
            ->leftJoinSub($latestReview, 'last_review', static function ($join): void {
                $join->on('last_review.scenario_id', '=', 's.id');
            })
            ->leftJoin('repasse_scenario_reviews as r', 'r.id', '=', 'last_review.latest_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.assigned_to')
            ->leftJoinSub($allocations, 'alloc', static function ($join): void {
                $join->on('alloc.repasse_scenario_id', '=', 's.id');
            })
            ->where('s.repasse_case_id', $case)
            ->select([
                's.id', 's.title', 's.revision', 's.gross_amount_cents',
                's.archived_at', 's.updated_at', 's.created_by',
                'r.id as last_review_id', 'r.status as last_review_status',
                'r.revision as last_review_revision',
                'r.scenario_revision as reviewed_scenario_revision',
                'r.assigned_to as reviewer_id', 'u.name as reviewer_name',
                'r.due_on as review_due_on', 'r.created_at as review_created_at',
            ])
            ->selectRaw('COALESCE(alloc.allocation_count, 0) AS allocation_count')
            ->selectRaw('COALESCE(alloc.allocated_cents, 0) AS allocated_cents')
            ->selectRaw('CAST(s.gross_amount_cents AS DECIMAL(25, 0)) - COALESCE(alloc.allocated_cents, 0) AS difference_cents')
            ->selectRaw("{$state} AS review_state");

    }

    private function filtered(Builder $base, array $input): Builder
    {
        $table = DB::query()->fromSub($base, 'portfolio');
        if (($input['status'] ?? 'all') === 'draft') {
            $table->whereNull('portfolio.archived_at');
        } elseif (($input['status'] ?? 'all') === 'archived') {
            $table->whereNotNull('portfolio.archived_at');
        }
        if (($input['review_state'] ?? 'all') !== 'all') {
            $table->where('portfolio.review_state', $input['review_state']);
        }
        if (trim($input['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($input['search']));
            $table->where('portfolio.title', 'like', '%'.$term.'%');
        }

        return $table;
    }

    public function export(Request $request, int $case): StreamedResponse
    {
        $input = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'archived'])],
            'review_state' => ['sometimes', Rule::in(self::REVIEW_STATES)],
        ]);
        TenantTable::table('repasse_cases')->where('id', $case)->firstOrFail();
        $query = $this->filtered($this->portfolio($case), $input);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Veículo: cenário ID', 'Título', 'Revisão atual',
                'Total previsto em centavos', 'Alocado em centavos',
                'Diferença em centavos', 'Destinatários', 'Estado documental',
                'Parecer ID', 'Revisão do parecer', 'Revisor', 'Prazo',
                'Nota: não autoriza pagamentos',
            ], ';');
            $query->chunkById(200, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->id, $this->safe((string) $row->title),
                        $row->revision, $row->gross_amount_cents,
                        $row->allocated_cents, $row->difference_cents,
                        $row->allocation_count, $row->review_state,
                        $row->last_review_id, $row->reviewed_scenario_revision,
                        $this->safe($row->reviewer_name), $row->review_due_on,
                        'SIMULAÇÃO DOCUMENTAL - SEM LIQUIDAÇÃO',
                    ], ';');
                }
            }, 'portfolio.id', 'id');
            fclose($out);
        }, "veiculo-{$case}-prestacao-documental.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safe(?string $value): string
    {
        $clean = ltrim(str_replace(["\r", "\n", "\t"], ' ', $value ?? ''));

        return preg_match('/^[=+@\-]/u', $clean) ? "'".$clean : $clean;
    }
}
