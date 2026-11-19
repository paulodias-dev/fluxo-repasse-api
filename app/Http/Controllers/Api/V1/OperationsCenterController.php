<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantDB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OperationsCenterController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        if (($from && ! $to) || ($to && ! $from)) {
            abort(422, 'Informe o início e o fim do período.');
        }
        if ($from && $to && $from > $to) {
            abort(422, 'O início do período deve ser anterior ou igual ao fim.');
        }

        $period = $from && $to ? ['from' => $from, 'to' => $to] : null;
        $stats = [
            'cases_total' => TenantDB::table('repasse_cases')->count(),
            'cases_draft' => TenantDB::table('repasse_cases')->where('status', 'draft')->count(),
            'cases_archived' => TenantDB::table('repasse_cases')->where('status', 'archived')->count(),
            'tasks_pending' => TenantDB::table('repasse_case_tasks')->where('status', 'pending')->count(),
            'tasks_doing' => TenantDB::table('repasse_case_tasks')->where('status', 'doing')->count(),
            'tasks_done' => TenantDB::table('repasse_case_tasks')->where('status', 'done')->count(),
            'tasks_overdue' => TenantDB::table('repasse_case_tasks')->whereIn('status', ['pending', 'doing'])
                ->whereNotNull('due_on')->whereDate('due_on', '<', $today)->count(),
            'checklist_total' => TenantDB::table('repasse_case_checklist')->count(),
            'checklist_done' => TenantDB::table('repasse_case_checklist')->whereNotNull('checked_at')->count(),
            'evidence_total' => TenantDB::table('repasse_case_evidence')->whereNull('archived_at')->count(),
        ];
        $stats['tasks_open'] = TenantDB::table('repasse_case_tasks')
            ->whereIn('status', ['pending', 'doing'])
            ->when($period, fn ($query) => $query->whereBetween('due_on', [$from, $to]))
            ->count();
        $stats['tasks_overdue'] = TenantDB::table('repasse_case_tasks')
            ->whereIn('status', ['pending', 'doing'])
            ->whereNotNull('due_on')->whereDate('due_on', '<', $today)
            ->when($period, fn ($query) => $query->whereBetween('due_on', [$from, $to]))
            ->count();
        $stats['commitments_scheduled'] = TenantDB::table('repasse_case_tasks')
            ->whereIn('status', ['pending', 'doing'])->whereNotNull('due_on')
            ->when($period, fn ($query) => $query->whereBetween('due_on', [$from, $to]))
            ->count();
        $stats['incomplete_profiles'] = TenantDB::table('repasse_cases')->where('status', 'draft')
            ->where(function ($query): void {
                $query->whereNull('plate')->orWhere('plate', '')
                    ->orWhereNull('brand')->orWhere('brand', '')
                    ->orWhereNull('model')->orWhere('model', '');
            })->count();

        $finance = $this->financialDashboard($from, $to);
        $recent = TenantDB::table('repasse_cases')->select('id', 'title', 'status', 'updated_at')
            ->orderByDesc('updated_at')->limit(8)->get();
        $due = TenantDB::table('repasse_case_tasks as t')
            ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->whereIn('t.status', ['pending', 'doing'])->whereNotNull('t.due_on')
            ->when($period, fn ($query) => $query->whereBetween('t.due_on', [$from, $to]))
            ->select('t.id', 't.repasse_case_id', 'c.title as case_title', 't.title',
                't.details', 't.due_on', 't.priority', 't.status', 't.assigned_to',
                't.completed_at', 't.created_at', 't.updated_at')
            ->orderBy('t.due_on')->limit(10)->get();

        return response()->json(['stats' => $stats, 'recent_cases' => $recent,
            'upcoming_tasks' => $due, 'finance' => $finance,
            'period' => $period, 'as_of' => now()->toIso8601String()])
            ->header('Cache-Control', 'no-store');
    }

    public function task(Request $request, int $task): JsonResponse
    {
        $record = TenantDB::table('repasse_case_tasks as t')
            ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->where('t.id', $task)
            ->select('t.id', 't.repasse_case_id', 't.title', 't.details', 't.status',
                't.priority', 't.due_on', 't.assigned_to', 'u.name as assignee_name',
                't.completed_at', 't.created_at', 't.updated_at',
                'c.title as case_title', 'c.status as case_status')
            ->firstOrFail();

        return response()->json(['data' => $record])->header('Cache-Control', 'no-store');
    }

    public function assignees(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = User::query()->where('is_active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', 'cases.manage'))
            ->select(['id', 'name'])->orderBy('name')->orderBy('id');
        if (trim($data['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($data['search']));
            $query->where('name', 'like', '%'.$term.'%');
        }

        return response()->json($query->paginate(30))
            ->header('Cache-Control', 'no-store');
    }

    public function tasks(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['all', 'pending', 'doing', 'done', 'cancelled'])],
            'priority' => ['sometimes', Rule::in(['all', 'low', 'normal', 'high'])],
            'scope' => ['sometimes', Rule::in(['all', 'mine'])],
            'overdue' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (($filters['from'] ?? null) && ($filters['to'] ?? null) && $filters['from'] > $filters['to']) {
            abort(422, 'O início do período deve ser anterior ou igual ao fim.');
        }
        $query = TenantDB::table('repasse_case_tasks as t')
            ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->select('t.id', 't.title', 't.details', 't.status', 't.priority',
                't.due_on', 't.assigned_to', 'u.name as assignee_name',
                'c.id as repasse_case_id', 'c.title as case_title', 'c.status as case_status');
        if (($filters['scope'] ?? 'all') === 'mine') {
            $query->where('t.assigned_to', $request->user('api')->id);
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('t.status', $filters['status']);
        }
        if (($filters['priority'] ?? 'all') !== 'all') {
            $query->where('t.priority', $filters['priority']);
        }
        if ($filters['overdue'] ?? false) {
            $query->whereIn('t.status', ['pending', 'doing'])
                ->whereNotNull('t.due_on')->whereDate('t.due_on', '<', now()->toDateString());
        }
        if (($filters['from'] ?? null) && ($filters['to'] ?? null)) {
            $query->whereBetween('t.due_on', [$filters['from'], $filters['to']]);
        }
        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['search']));
            $query->where(function ($q) use ($term): void {
                $q->where('t.title', 'like', '%'.$term.'%')->orWhere('c.title', 'like', '%'.$term.'%');
            });
        }

        return response()->json($query->orderByRaw("CASE WHEN t.status IN ('done', 'cancelled') THEN 1 ELSE 0 END")
            ->orderBy('t.due_on')->orderByDesc('t.id')->paginate(30))
            ->header('Cache-Control', 'no-store');
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate(['entity' => ['required', Rule::in(['cases', 'tasks'])]]);
        $entity = $data['entity'];

        return response()->streamDownload(function () use ($entity): void {
            $stream = fopen('php://output', 'wb');
            if ($entity === 'cases') {
                fputcsv($stream, ['ID', 'Título', 'Status', 'Criado em', 'Atualizado em'], ';');
                TenantDB::table('repasse_cases')->select('id', 'title', 'status', 'created_at', 'updated_at')
                    ->orderBy('id')->chunkById(250, function ($rows) use ($stream): void {
                        foreach ($rows as $row) {
                            fputcsv($stream, [$row->id, $this->safe($row->title),
                                $row->status, $row->created_at, $row->updated_at], ';');
                        }
                    });
            } else {
                fputcsv($stream, ['ID', 'Dossiê', 'Tarefa', 'Status', 'Prioridade', 'Prazo', 'Responsável'], ';');
                TenantDB::table('repasse_case_tasks as t')
                    ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
                    ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
                    ->select('t.id', 'c.title as case_title', 't.title', 't.status',
                        't.priority', 't.due_on', 'u.name as assignee_name')
                    ->orderBy('t.id')->chunkById(250, function ($rows) use ($stream): void {
                        foreach ($rows as $row) {
                            fputcsv($stream, [$row->id, $this->safe($row->case_title),
                                $this->safe($row->title), $row->status, $row->priority,
                                $row->due_on, $this->safe($row->assignee_name)], ';');
                        }
                    }, 't.id', 'id');
            }
            fclose($stream);
        }, "repasse-{$entity}-".now()->format('Ymd').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safe(?string $value): string
    {
        $value = str_replace(["\r", "\n", "\t"], ' ', $value ?? '');
        $value = ltrim($value);

        return preg_match('/^[=+@\-]/u', $value) ? "'".$value : $value;
    }

    /**
     * The dashboard separates acquisition cost from operational expenses.
     * Sales plus realized income form revenue; realized expenses form the
     * comparison series. Planned expenses remain a projection-only KPI.
     */
    private function financialDashboard(?string $from, ?string $to): array
    {
        $range = $from && $to;
        $entryQuery = TenantDB::table('repasse_case_financial_entries')
            ->whereNull('voided_at')->whereNotNull('effective_on')
            ->when($range, fn ($query) => $query->whereBetween('effective_on', [$from, $to]));
        $entries = $entryQuery->select('kind', 'status', 'amount_cents', 'effective_on')->get();

        $sales = TenantDB::table('repasse_cases')
            ->whereNotNull('sold_on')->whereNotNull('sale_amount_cents')
            ->when($range, fn ($query) => $query->whereBetween('sold_on', [$from, $to]))
            ->select('sale_amount_cents', 'sold_on')->get();
        $purchases = TenantDB::table('repasse_cases')
            ->whereNotNull('purchased_on')->whereNotNull('purchase_amount_cents')
            ->when($range, fn ($query) => $query->whereBetween('purchased_on', [$from, $to]))
            ->sum('purchase_amount_cents');

        $realizedIncome = (int) $entries->where('kind', 'income')->where('status', 'realized')->sum('amount_cents');
        $realizedExpenses = (int) $entries->where('kind', 'expense')->where('status', 'realized')->sum('amount_cents');
        $plannedExpenses = (int) $entries->where('kind', 'expense')->where('status', 'planned')->sum('amount_cents');
        $saleRevenue = (int) $sales->sum('sale_amount_cents');
        $revenue = $saleRevenue + $realizedIncome;

        $start = $range ? Carbon::parse($from)->startOfDay() : now()->startOfMonth()->startOfDay();
        $end = $range ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        $days = $start->diffInDays($end) + 1;
        $granularity = $days <= 31 ? 'day' : ($days <= 180 ? 'week' : 'month');
        $points = [];
        if ($range) {
            foreach (CarbonPeriod::create($start, $end) as $date) {
                $key = $this->chartBucket($date, $granularity);
                $points[$key] ??= ['key' => $key, 'label' => $this->chartLabel($date, $granularity),
                    'revenue_cents' => 0, 'expense_cents' => 0];
            }
        }
        foreach ($entries as $entry) {
            $date = Carbon::parse($entry->effective_on);
            $key = $this->chartBucket($date, $granularity);
            $points[$key] ??= ['key' => $key, 'label' => $this->chartLabel($date, $granularity),
                'revenue_cents' => 0, 'expense_cents' => 0];
            if ($entry->status === 'realized' && $entry->kind === 'income') {
                $points[$key]['revenue_cents'] += (int) $entry->amount_cents;
            }
            if ($entry->status === 'realized' && $entry->kind === 'expense') {
                $points[$key]['expense_cents'] += (int) $entry->amount_cents;
            }
        }
        foreach ($sales as $sale) {
            $date = Carbon::parse($sale->sold_on);
            $key = $this->chartBucket($date, $granularity);
            $points[$key] ??= ['key' => $key, 'label' => $this->chartLabel($date, $granularity),
                'revenue_cents' => 0, 'expense_cents' => 0];
            $points[$key]['revenue_cents'] += (int) $sale->sale_amount_cents;
        }

        return [
            'sales_revenue_cents' => $saleRevenue,
            'income_realized_cents' => $realizedIncome,
            'revenue_realized_cents' => $revenue,
            'expense_realized_cents' => $realizedExpenses,
            'expense_planned_cents' => $plannedExpenses,
            'purchase_total_cents' => (int) $purchases,
            'net_realized_cents' => $revenue - $realizedExpenses,
            'chart_granularity' => $granularity,
            'chart' => array_values($points),
        ];
    }

    private function chartBucket(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'month' => $date->format('Y-m'),
            'week' => $date->copy()->startOfWeek()->format('Y-m-d'),
            default => $date->format('Y-m-d'),
        };
    }

    private function chartLabel(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'month' => $date->translatedFormat('M/ Y'),
            'week' => 'Sem. '.$date->format('d/m'),
            default => $date->format('d/m'),
        };
    }
}
