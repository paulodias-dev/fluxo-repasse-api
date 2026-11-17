<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Read-only cross-vehicle cockpit. No payment, transfer or ledger writes.
 * All aggregates are reduced by repasse_case_id BEFORE joining the vehicle,
 * avoiding double counting when a vehicle has many tasks and documents.
 */
final class VehiclePortfolioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'archived'])],
            'attention' => ['sometimes', Rule::in([
                'all', 'overdue', 'open_tasks', 'missing_documents',
                'incomplete_profile', 'planned_expenses',
            ])],
            'sort' => ['sometimes', Rule::in(['updated', 'oldest', 'purchase_desc', 'overdue'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $today = now()->toDateString();

        // Fleet totals intentionally describe the whole authorized portfolio,
        // not just a filtered page; the list uses the requested filters.
        $totals = $this->joined($today)->selectRaw("
            COUNT(*) AS vehicles_total,
            COALESCE(SUM(CASE WHEN c.status = 'draft' THEN 1 ELSE 0 END), 0) AS vehicles_active,
            COALESCE(SUM(CASE WHEN c.status = 'archived' THEN 1 ELSE 0 END), 0) AS vehicles_archived,
            COALESCE(SUM(CASE WHEN c.status = 'draft' AND COALESCE(t.overdue_tasks, 0) > 0
                THEN 1 ELSE 0 END), 0) AS vehicles_overdue,
            COALESCE(SUM(CASE WHEN c.status = 'draft' AND COALESCE(t.open_tasks, 0) > 0
                THEN 1 ELSE 0 END), 0) AS vehicles_with_open_tasks,
            COALESCE(SUM(CASE WHEN c.status = 'draft' AND COALESCE(d.documents_count, 0) = 0
                THEN 1 ELSE 0 END), 0) AS vehicles_without_documents,
            COALESCE(SUM(CASE WHEN c.status = 'draft' AND
                (c.plate IS NULL OR c.plate = '' OR c.brand IS NULL OR c.brand = ''
                 OR c.model IS NULL OR c.model = '')
                THEN 1 ELSE 0 END), 0) AS vehicles_incomplete_profile,
            COALESCE(SUM(CASE WHEN c.status = 'draft' AND COALESCE(f.planned_expenses, 0) > 0
                THEN 1 ELSE 0 END), 0) AS vehicles_with_planned_expenses,
            COALESCE(SUM(COALESCE(c.purchase_amount_cents, 0)), 0) AS purchase_total_cents,
            COALESCE(SUM(COALESCE(c.sale_amount_cents, 0)), 0) AS sale_total_cents,
            COALESCE(SUM(COALESCE(f.realized_expenses, 0)), 0) AS expense_realized_total_cents,
            COALESCE(SUM(COALESCE(f.planned_expenses, 0)), 0) AS expense_planned_total_cents,
            COALESCE(SUM(COALESCE(f.realized_income, 0)), 0) AS income_realized_total_cents,
            COALESCE(SUM(COALESCE(f.planned_income, 0)), 0) AS income_planned_total_cents,
            COALESCE(SUM(CASE WHEN c.purchase_amount_cents IS NOT NULL
                AND c.sale_amount_cents IS NOT NULL THEN 1 ELSE 0 END), 0)
                AS vehicles_with_projected_result,
            COALESCE(SUM(CASE WHEN c.sold_on IS NOT NULL AND c.purchase_amount_cents IS NOT NULL
                AND c.sale_amount_cents IS NOT NULL THEN 1 ELSE 0 END), 0)
                AS vehicles_with_sale_result,
            COALESCE(SUM(CASE WHEN c.sold_on IS NOT NULL AND c.purchase_amount_cents IS NOT NULL
                AND c.sale_amount_cents IS NOT NULL THEN
                CAST(c.sale_amount_cents AS DECIMAL(25, 0))
                + COALESCE(f.realized_income, 0)
                - CAST(c.purchase_amount_cents AS DECIMAL(25, 0))
                - COALESCE(f.realized_expenses, 0)
                ELSE 0 END), 0) AS sale_result_known_total_cents,
            COALESCE(SUM(CASE WHEN c.purchase_amount_cents IS NOT NULL
                AND c.sale_amount_cents IS NOT NULL THEN
                CAST(c.sale_amount_cents AS DECIMAL(25, 0))
                + COALESCE(f.realized_income, 0) + COALESCE(f.planned_income, 0)
                - CAST(c.purchase_amount_cents AS DECIMAL(25, 0))
                - COALESCE(f.realized_expenses, 0) - COALESCE(f.planned_expenses, 0)
                ELSE 0 END), 0) AS projected_result_known_total_cents
        ")->first();

        $query = $this->joined($today)->select([
            'c.id', 'c.title', 'c.status', 'c.plate', 'c.brand', 'c.model',
            'c.model_year', 'c.purchase_location', 'c.seller_name', 'c.buyer_name',
            'c.purchased_on', 'c.delivered_on', 'c.sold_on',
            'c.purchase_amount_cents', 'c.sale_amount_cents', 'c.updated_at',
        ])->selectRaw('
            COALESCE(t.open_tasks, 0) AS open_tasks,
            COALESCE(t.overdue_tasks, 0) AS overdue_tasks,
            COALESCE(k.pending_checklist, 0) AS pending_checklist,
            COALESCE(d.documents_count, 0) AS documents_count,
            COALESCE(f.realized_expenses, 0) AS expense_realized_cents,
            COALESCE(f.planned_expenses, 0) AS expense_planned_cents,
            COALESCE(f.realized_income, 0) AS income_realized_cents,
            COALESCE(f.planned_income, 0) AS income_planned_cents,
            CASE WHEN c.purchase_amount_cents IS NULL THEN NULL
                ELSE CAST(c.purchase_amount_cents AS DECIMAL(25, 0))
                    + COALESCE(f.realized_expenses, 0)
                END AS current_cost_cents,
            CASE WHEN c.purchase_amount_cents IS NULL THEN NULL
                ELSE CAST(c.purchase_amount_cents AS DECIMAL(25, 0))
                    + COALESCE(f.realized_expenses, 0) + COALESCE(f.planned_expenses, 0)
                END AS projected_cost_cents,
            CASE WHEN c.purchase_amount_cents IS NULL OR c.sale_amount_cents IS NULL THEN NULL
                ELSE CAST(c.sale_amount_cents AS DECIMAL(25, 0))
                    + COALESCE(f.realized_income, 0) + COALESCE(f.planned_income, 0)
                    - CAST(c.purchase_amount_cents AS DECIMAL(25, 0))
                    - COALESCE(f.realized_expenses, 0) - COALESCE(f.planned_expenses, 0)
                END AS projected_result_cents,
            CASE WHEN c.sold_on IS NULL OR c.purchase_amount_cents IS NULL
                OR c.sale_amount_cents IS NULL THEN NULL
                ELSE CAST(c.sale_amount_cents AS DECIMAL(25, 0))
                    + COALESCE(f.realized_income, 0)
                    - CAST(c.purchase_amount_cents AS DECIMAL(25, 0))
                    - COALESCE(f.realized_expenses, 0)
                END AS sale_result_cents
        ');

        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('c.status', $filters['status']);
        }
        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($filters['search']));
            $query->where(static function (Builder $q) use ($term): void {
                $q->where('c.title', 'like', '%'.$term.'%')
                    ->orWhere('c.plate', 'like', '%'.$term.'%')
                    ->orWhere('c.chassis', 'like', '%'.$term.'%')
                    ->orWhere('c.brand', 'like', '%'.$term.'%')
                    ->orWhere('c.model', 'like', '%'.$term.'%')
                    ->orWhere('c.seller_name', 'like', '%'.$term.'%')
                    ->orWhere('c.purchase_location', 'like', '%'.$term.'%');
            });
        }

        // Archived vehicles remain consultable, but are not actionable.
        switch ($filters['attention'] ?? 'all') {
            case 'overdue':
                $query->where('c.status', 'draft')->where('t.overdue_tasks', '>', 0);
                break;
            case 'open_tasks':
                $query->where('c.status', 'draft')->where('t.open_tasks', '>', 0);
                break;
            case 'missing_documents':
                $query->where('c.status', 'draft')->whereNull('d.documents_count');
                break;
            case 'incomplete_profile':
                $query->where('c.status', 'draft')->where(static function (Builder $q): void {
                    $q->whereNull('c.plate')->orWhere('c.plate', '')
                        ->orWhereNull('c.brand')->orWhere('c.brand', '')
                        ->orWhereNull('c.model')->orWhere('c.model', '');
                });
                break;
            case 'planned_expenses':
                $query->where('c.status', 'draft')->where('f.planned_expenses', '>', 0);
                break;
        }

        switch ($filters['sort'] ?? 'updated') {
            case 'oldest':
                $query->orderBy('c.created_at')->orderBy('c.id');
                break;
            case 'purchase_desc':
                $query->orderByDesc('c.purchase_amount_cents')->orderByDesc('c.id');
                break;
            case 'overdue':
                $query->orderByDesc('t.overdue_tasks')->orderByDesc('t.open_tasks')
                    ->orderByDesc('c.updated_at')->orderByDesc('c.id');
                break;
            default:
                $query->orderByDesc('c.updated_at')->orderByDesc('c.id');
        }

        $page = $query->paginate(15);
        // SUM() over BIGINT becomes DECIMAL on MySQL: normalize the public
        // contract to integer cents, including NEGATIVE projected results.
        $page->through(static function (object $row): object {
            foreach ([
                'id', 'model_year', 'purchase_amount_cents', 'sale_amount_cents',
                'open_tasks', 'overdue_tasks', 'pending_checklist', 'documents_count',
                'expense_realized_cents', 'expense_planned_cents',
                'income_realized_cents', 'income_planned_cents',
                'current_cost_cents', 'projected_cost_cents', 'projected_result_cents',
                'sale_result_cents',
            ] as $field) {
                if ($row->{$field} !== null) {
                    $row->{$field} = (int) $row->{$field};
                }
            }
            return $row;
        });
        $summary = [];
        foreach ((array) $totals as $key => $value) {
            $summary[$key] = (int) $value;
        }

        return response()->json([
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'summary' => $summary,
            'as_of' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function joined(string $today): Builder
    {
        $tasks = DB::table('repasse_case_tasks')
            ->select('repasse_case_id')
            ->selectRaw("SUM(CASE WHEN status IN ('pending', 'doing') THEN 1 ELSE 0 END) AS open_tasks")
            ->selectRaw(
                "SUM(CASE WHEN status IN ('pending', 'doing') AND due_on < ? THEN 1 ELSE 0 END) AS overdue_tasks",
                [$today]
            )->groupBy('repasse_case_id');

        $checks = DB::table('repasse_case_checklist')
            ->select('repasse_case_id')
            ->selectRaw('COUNT(*) AS pending_checklist')
            ->whereNull('checked_at')->groupBy('repasse_case_id');

        $documents = DB::table('repasse_case_evidence')
            ->select('repasse_case_id')
            ->selectRaw('COUNT(*) AS documents_count')
            ->whereNull('archived_at')->groupBy('repasse_case_id');

        $money = DB::table('repasse_case_financial_entries')
            ->select('repasse_case_id')->whereNull('voided_at')
            ->selectRaw("SUM(CASE WHEN kind = 'expense' AND status = 'realized'
                THEN amount_cents ELSE 0 END) AS realized_expenses")
            ->selectRaw("SUM(CASE WHEN kind = 'expense' AND status = 'planned'
                THEN amount_cents ELSE 0 END) AS planned_expenses")
            ->selectRaw("SUM(CASE WHEN kind = 'income' AND status = 'realized'
                THEN amount_cents ELSE 0 END) AS realized_income")
            ->selectRaw("SUM(CASE WHEN kind = 'income' AND status = 'planned'
                THEN amount_cents ELSE 0 END) AS planned_income")
            ->groupBy('repasse_case_id');

        return DB::table('repasse_cases as c')
            ->leftJoinSub($tasks, 't', static fn ($join) => $join->on('t.repasse_case_id', '=', 'c.id'))
            ->leftJoinSub($checks, 'k', static fn ($join) => $join->on('k.repasse_case_id', '=', 'c.id'))
            ->leftJoinSub($documents, 'd', static fn ($join) => $join->on('d.repasse_case_id', '=', 'c.id'))
            ->leftJoinSub($money, 'f', static fn ($join) => $join->on('f.repasse_case_id', '=', 'c.id'));
    }
}
