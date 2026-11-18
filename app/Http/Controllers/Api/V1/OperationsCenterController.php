<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\TenantTable;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OperationsCenterController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $today = now()->toDateString();
        $stats = [
            'cases_total' => TenantTable::table('repasse_cases')->count(),
            'cases_draft' => TenantTable::table('repasse_cases')->where('status', 'draft')->count(),
            'cases_archived' => TenantTable::table('repasse_cases')->where('status', 'archived')->count(),
            'tasks_pending' => TenantTable::table('repasse_case_tasks')->where('status', 'pending')->count(),
            'tasks_doing' => TenantTable::table('repasse_case_tasks')->where('status', 'doing')->count(),
            'tasks_done' => TenantTable::table('repasse_case_tasks')->where('status', 'done')->count(),
            'tasks_overdue' => TenantTable::table('repasse_case_tasks')->whereIn('status', ['pending', 'doing'])
                ->whereNotNull('due_on')->whereDate('due_on', '<', $today)->count(),
            'checklist_total' => TenantTable::table('repasse_case_checklist')->count(),
            'checklist_done' => TenantTable::table('repasse_case_checklist')->whereNotNull('checked_at')->count(),
            'evidence_total' => TenantTable::table('repasse_case_evidence')->whereNull('archived_at')->count(),
        ];
        $recent = TenantTable::table('repasse_cases')->select('id', 'title', 'status', 'updated_at')
            ->orderByDesc('updated_at')->limit(8)->get();
        $due = TenantTable::table('repasse_case_tasks as t')
            ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->whereIn('t.status', ['pending', 'doing'])->whereNotNull('t.due_on')
            ->select('t.id', 't.repasse_case_id', 'c.title as case_title', 't.title',
                't.due_on', 't.priority', 't.status', 't.assigned_to')
            ->orderBy('t.due_on')->limit(10)->get();

        return response()->json(['stats' => $stats, 'recent_cases' => $recent,
            'upcoming_tasks' => $due, 'as_of' => now()->toIso8601String()])
            ->header('Cache-Control', 'no-store');
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
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = TenantTable::table('repasse_case_tasks as t')
            ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->select('t.id', 't.title', 't.details', 't.status', 't.priority',
                't.due_on', 't.assigned_to', 'u.name as assignee_name',
                'c.id as repasse_case_id', 'c.title as case_title', 'c.status as case_status');
        if (($filters['scope'] ?? 'all') === 'mine') $query->where('t.assigned_to', $request->user('api')->id);
        if (($filters['status'] ?? 'all') !== 'all') $query->where('t.status', $filters['status']);
        if (($filters['priority'] ?? 'all') !== 'all') $query->where('t.priority', $filters['priority']);
        if ($filters['overdue'] ?? false) {
            $query->whereIn('t.status', ['pending', 'doing'])
                ->whereNotNull('t.due_on')->whereDate('t.due_on', '<', now()->toDateString());
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
                TenantTable::table('repasse_cases')->select('id', 'title', 'status', 'created_at', 'updated_at')
                    ->orderBy('id')->chunkById(250, function ($rows) use ($stream): void {
                        foreach ($rows as $row) {
                            fputcsv($stream, [$row->id, $this->safe($row->title),
                                $row->status, $row->created_at, $row->updated_at], ';');
                        }
                    });
            } else {
                fputcsv($stream, ['ID', 'Dossiê', 'Tarefa', 'Status', 'Prioridade', 'Prazo', 'Responsável'], ';');
                TenantTable::table('repasse_case_tasks as t')
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
}
