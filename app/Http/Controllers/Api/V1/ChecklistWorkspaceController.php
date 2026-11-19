<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CaseOperations;
use App\Support\TenantContext;
use App\Support\TenantDB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ChecklistWorkspaceController extends Controller
{
    private const GROUPS = ['Documentação', 'Preparação', 'Venda', 'Pós-venda'];

    public function __construct(private readonly CaseOperations $operations) {}

    public function index(Request $request, int $case): JsonResponse
    {
        $this->operations->ensureExists($case);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $sets = TenantDB::table('repasse_case_checklist_sets')
            ->where('repasse_case_id', $case)->orderByDesc('id')->paginate(30);
        $totals = TenantDB::table('repasse_case_checklist')
            ->where('repasse_case_id', $case)
            ->selectRaw('checklist_set_id, COUNT(*) as total, SUM(CASE WHEN checked_at IS NOT NULL THEN 1 ELSE 0 END) as completed')
            ->groupBy('checklist_set_id')->get()->keyBy(fn ($row) => $row->checklist_set_id ?? 'legacy');
        $sets->getCollection()->transform(function ($set) use ($totals) {
            $count = $totals->get($set->id);
            $set->items_count = (int) ($count->total ?? 0);
            $set->completed_count = (int) ($count->completed ?? 0);

            return $set;
        });
        $legacy = $totals->get('legacy');
        return response()->json(['sets' => $sets, 'legacy' => $legacy
            ? ['id' => 0, 'title' => 'Checklist de conferência', 'status' => 'active',
                'items_count' => (int) $legacy->total, 'completed_count' => (int) $legacy->completed]
            : null])->header('Cache-Control', 'no-store');
    }

    public function show(int $case, int $set): JsonResponse
    {
        $this->operations->ensureExists($case);
        $header = $set === 0
            ? ['id' => 0, 'title' => 'Checklist de conferência', 'status' => 'active']
            : (array) TenantDB::table('repasse_case_checklist_sets')
                ->where('repasse_case_id', $case)->where('id', $set)->firstOrFail();
        $items = TenantDB::table('repasse_case_checklist as i')
            ->leftJoin('users as u', 'u.id', '=', 'i.assigned_to')
            ->where('i.repasse_case_id', $case)
            ->where('i.checklist_set_id', $set === 0 ? null : $set)
            ->select('i.id', 'i.label', 'i.group_name', 'i.due_on', 'i.assigned_to',
                'u.name as assignee_name', 'i.checked_at', 'i.checked_by', 'i.created_at')
            ->orderBy('i.id')->get();
        return response()->json(['data' => [...$header, 'items' => $items]])
            ->header('Cache-Control', 'no-store');
    }

    private function payload(Request $request): array
    {
        $this->operations->restrict($request, ['title', 'status', 'items']);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'status' => ['sometimes', Rule::in(['draft', 'active'])],
            'items' => ['required', 'array', 'min:1', 'max:80'],
            'items.*.label' => ['required', 'string', 'min:2', 'max:200'],
            'items.*.group_name' => ['required', Rule::in(self::GROUPS)],
            'items.*.due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'items.*.assigned_to' => ['sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('tenant_id', TenantContext::requireId())],
        ]);
        $data['title'] = trim($data['title']);
        foreach ($data['items'] as &$item) {
            $item['label'] = trim($item['label']);
            if (mb_strlen($item['label']) < 2) {
                throw ValidationException::withMessages(['items' => 'Informe uma descrição válida para cada item.']);
            }
            if (isset($item['assigned_to'])) {
                $user = \App\Models\User::query()->where('tenant_id', TenantContext::requireId())
                    ->where('is_active', true)->find($item['assigned_to']);
                if (! $user || ! $user->hasPermission('cases.manage')) {
                    throw ValidationException::withMessages(['items' => 'Responsável sem acesso à operação.']);
                }
            }
        }
        unset($item);

        return $data;
    }

    private function insertItems(int $case, int $set, array $items, Request $request): void
    {
        foreach ($items as $item) {
            TenantDB::table('repasse_case_checklist')->insert([
                'repasse_case_id' => $case, 'checklist_set_id' => $set,
                'label' => $item['label'], 'group_name' => $item['group_name'],
                'due_on' => $item['due_on'] ?? null, 'assigned_to' => $item['assigned_to'] ?? null,
                'created_by' => $request->user('api')->getAuthIdentifier(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function store(Request $request, int $case): JsonResponse
    {
        $data = $this->payload($request);
        $set = DB::transaction(function () use ($case, $request, $data): int {
            $this->operations->editable($case);
            $set = TenantDB::table('repasse_case_checklist_sets')->insertGetId([
                'repasse_case_id' => $case, 'title' => $data['title'],
                'status' => $data['status'] ?? 'active',
                'created_by' => $request->user('api')->getAuthIdentifier(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->insertItems($case, $set, $data['items'], $request);
            $this->operations->event($case, $request, 'checklist_created');
            return $set;
        });
        return response()->json(['data' => $this->setHeader($case, $set)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $case, int $set): JsonResponse
    {
        if ($set <= 0) abort(404);
        $data = $this->payload($request);
        DB::transaction(function () use ($request, $case, $set, $data): void {
            $this->operations->editable($case);
            $existing = TenantDB::table('repasse_case_checklist_sets')
                ->where('repasse_case_id', $case)->where('id', $set)->lockForUpdate()->firstOrFail();
            if ($existing->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Somente rascunhos podem ser reestruturados.']);
            }
            TenantDB::table('repasse_case_checklist')->where('repasse_case_id', $case)
                ->where('checklist_set_id', $set)->delete();
            $this->insertItems($case, $set, $data['items'], $request);
            TenantDB::table('repasse_case_checklist_sets')->where('id', $set)->update([
                'title' => $data['title'], 'status' => $data['status'] ?? 'draft',
                'updated_at' => now(),
            ]);
            $this->operations->event($case, $request, 'checklist_updated');
        });
        return response()->json(['data' => $this->setHeader($case, $set)])
            ->header('Cache-Control', 'no-store');
    }

    public function updateItem(Request $request, int $case, int $set, int $item): JsonResponse
    {
        $this->operations->restrict($request, ['checked', 'label', 'due_on', 'assigned_to', 'group_name']);
        $data = $request->validate([
            'checked' => ['sometimes', 'boolean'],
            'label' => ['sometimes', 'string', 'min:2', 'max:200'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'group_name' => ['sometimes', Rule::in(self::GROUPS)],
            'assigned_to' => ['sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('tenant_id', TenantContext::requireId())],
        ]);
        if ($data === []) throw ValidationException::withMessages(['item' => 'Informe uma alteração.']);
        DB::transaction(function () use ($request, $case, $set, $item, $data): void {
            $this->operations->editable($case);
            if ($set !== 0) $this->setHeader($case, $set);
            $row = TenantDB::table('repasse_case_checklist')->where('repasse_case_id', $case)
                ->where('checklist_set_id', $set === 0 ? null : $set)
                ->where('id', $item)->lockForUpdate()->firstOrFail();
            if (isset($data['assigned_to'])) {
                $user = \App\Models\User::query()->where('tenant_id', TenantContext::requireId())
                    ->where('is_active', true)->find($data['assigned_to']);
                if (! $user || ! $user->hasPermission('cases.manage')) {
                    throw ValidationException::withMessages(['assigned_to' => 'Responsável sem acesso.']);
                }
            }
            $changes = [];
            foreach (['label', 'due_on', 'assigned_to', 'group_name'] as $field) {
                if (array_key_exists($field, $data)) $changes[$field] = $data[$field];
            }
            if (array_key_exists('checked', $data)) {
                $changes['checked_at'] = $data['checked'] ? now() : null;
                $changes['checked_by'] = $data['checked']
                    ? $request->user('api')->getAuthIdentifier() : null;
            }
            TenantDB::table('repasse_case_checklist')->where('id', $row->id)
                ->update([...$changes, 'updated_at' => now()]);
            $this->operations->event($case, $request, 'checklist_updated');
        });
        return $this->show($case, $set);
    }

    private function setHeader(int $case, int $set): array
    {
        return (array) TenantDB::table('repasse_case_checklist_sets')
            ->where('repasse_case_id', $case)->where('id', $set)->firstOrFail();
    }
}
