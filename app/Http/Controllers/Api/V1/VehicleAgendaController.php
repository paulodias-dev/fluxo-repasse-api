<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CaseOperations;
use App\Support\TenantContext;
use App\Support\TenantDB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VehicleAgendaController extends Controller
{
    public function __construct(private readonly CaseOperations $operations) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'vehicle_id' => ['sometimes', 'integer', 'min:1'],
            'kind' => ['sometimes', Rule::in(['reminder', 'task', 'appointment'])],
        ]);
        $first = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $filters['from']);
        $last = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $filters['to']);
        if ($first->diffInDays($last) > 92) {
            throw ValidationException::withMessages(['to' => 'Escolha um intervalo de até 92 dias.']);
        }
        $query = TenantDB::table('repasse_case_tasks as t')
            ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->whereBetween('t.due_on', [$filters['from'], $filters['to']])
            ->select('t.id', 't.repasse_case_id', 'c.title as case_title',
                'c.status as case_status', 't.title', 't.details', 't.kind', 't.status',
                't.due_on', 't.start_time', 't.end_time', 't.remind_minutes',
                't.assigned_to', 'u.name as assignee_name', 't.priority');
        if (isset($filters['vehicle_id'])) $query->where('t.repasse_case_id', $filters['vehicle_id']);
        if (isset($filters['kind'])) $query->where('t.kind', $filters['kind']);
        return response()->json(['data' => $query->orderBy('t.due_on')
            ->orderBy('t.start_time')->orderBy('t.id')->limit(500)->get()])
            ->header('Cache-Control', 'no-store, private');
    }

    private function validateInput(Request $request, bool $partial = false): array
    {
        $allowed = ['repasse_case_id', 'title', 'details', 'kind', 'due_on',
            'start_time', 'end_time', 'assigned_to', 'priority', 'remind_minutes', 'status'];
        $this->operations->restrict($request, $allowed);
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'repasse_case_id' => [$required, 'integer', 'min:1'],
            'title' => [$required, 'string', 'min:2', 'max:180'],
            'details' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'kind' => [$required, Rule::in(['reminder', 'task', 'appointment'])],
            'due_on' => [$required, 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'assigned_to' => ['sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('tenant_id', TenantContext::requireId())],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'remind_minutes' => ['sometimes', 'nullable', Rule::in([0, 5, 15, 30, 60, 1440])],
            'status' => ['sometimes', Rule::in(['pending', 'doing', 'done', 'cancelled'])],
        ]);
        if (array_key_exists('title', $data)) {
            $data['title'] = trim($data['title']);
            if (mb_strlen($data['title']) < 2) {
                throw ValidationException::withMessages(['title' => 'Informe um título válido.']);
            }
        }
        if (isset($data['start_time'], $data['end_time']) &&
            $data['end_time'] <= $data['start_time']) {
            throw ValidationException::withMessages(['end_time' => 'O término deve ser posterior ao início.']);
        }
        if (! $partial && ($data['kind'] ?? '') === 'appointment' && empty($data['start_time'])) {
            throw ValidationException::withMessages(['start_time' => 'Informe o horário do agendamento.']);
        }
        if (isset($data['assigned_to'])) {
            $user = User::query()->where('tenant_id', TenantContext::requireId())
                ->where('is_active', true)->find($data['assigned_to']);
            if (! $user || ! $user->hasPermission('cases.manage')) {
                throw ValidationException::withMessages(['assigned_to' => 'Responsável sem acesso à operação.']);
            }
        }

        return $data;
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateInput($request);
        $case = (int) $data['repasse_case_id'];
        $id = DB::transaction(function () use ($case, $request, $data): int {
            $this->operations->editable($case);
            return tap(TenantDB::table('repasse_case_tasks')->insertGetId([
                'repasse_case_id' => $case, 'title' => $data['title'],
                'details' => $data['details'] ?? null,
                'kind' => $data['kind'], 'status' => $data['status'] ?? 'pending',
                'priority' => $data['priority'] ?? 'normal',
                'due_on' => $data['due_on'], 'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'remind_minutes' => $data['remind_minutes'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                'created_by' => $request->user('api')->getAuthIdentifier(),
                'created_at' => now(), 'updated_at' => now(),
            ]), fn () => $this->operations->event($case, $request, 'task_created'));
        });
        return response()->json(['data' => $this->one($id)], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(int $task): JsonResponse
    {
        return response()->json(['data' => $this->one($task)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, int $task): JsonResponse
    {
        $data = $this->validateInput($request, true);
        if ($data === []) {
            throw ValidationException::withMessages(['task' => 'Informe uma alteração.']);
        }
        $existing = $this->one($task);
        if (array_key_exists('repasse_case_id', $data) &&
            (int) $data['repasse_case_id'] !== (int) $existing->repasse_case_id) {
            throw ValidationException::withMessages(['repasse_case_id' => 'Não é permitido mover o compromisso.']);
        }
        DB::transaction(function () use ($request, $task, $data, $existing): void {
            $this->operations->editable((int) $existing->repasse_case_id);
            $current = TenantDB::table('repasse_case_tasks')->where('id', $task)
                ->where('repasse_case_id', $existing->repasse_case_id)->lockForUpdate()->firstOrFail();
            $start = $data['start_time'] ?? $current->start_time;
            $end = $data['end_time'] ?? $current->end_time;
            if ($start && $end && $end <= $start) {
                throw ValidationException::withMessages(['end_time' => 'O término deve ser posterior ao início.']);
            }
            if (($data['kind'] ?? $current->kind) === 'appointment' && ! $start) {
                throw ValidationException::withMessages(['start_time' => 'Agendamento precisa de horário.']);
            }
            unset($data['repasse_case_id']);
            if (array_key_exists('status', $data)) {
                $data['completed_at'] = $data['status'] === 'done' ? now() : null;
            }
            TenantDB::table('repasse_case_tasks')->where('id', $task)
                ->update([...$data, 'updated_at' => now()]);
            $this->operations->event((int) $existing->repasse_case_id, $request, 'task_updated');
        });
        return $this->show($task);
    }

    public function attachments(int $task): JsonResponse
    {
        $row = $this->one($task);
        $files = TenantDB::table('repasse_case_task_documents as x')
            ->join('repasse_case_evidence as e', 'e.id', '=', 'x.evidence_id')
            ->where('x.task_id', $task)->where('x.repasse_case_id', $row->repasse_case_id)
            ->whereNull('e.archived_at')
            ->select('e.id', 'e.original_name', 'e.mime', 'e.size_bytes', 'e.created_at')
            ->orderBy('x.id')->get();
        return response()->json(['data' => $files])
            ->header('Cache-Control', 'no-store, private');
    }

    public function attach(Request $request, int $task): JsonResponse
    {
        $this->operations->restrict($request, ['evidence_id']);
        $data = $request->validate(['evidence_id' => ['required', 'integer', 'min:1']]);
        $row = $this->one($task);
        DB::transaction(function () use ($request, $task, $row, $data): void {
            $this->operations->editable((int) $row->repasse_case_id);
            TenantDB::table('repasse_case_evidence')
                ->where('repasse_case_id', $row->repasse_case_id)->where('id', $data['evidence_id'])
                ->whereNull('archived_at')->firstOrFail();
            $exists = TenantDB::table('repasse_case_task_documents')
                ->where('task_id', $task)->where('evidence_id', $data['evidence_id'])->exists();
            if (! $exists) {
                TenantDB::table('repasse_case_task_documents')->insert([
                    'repasse_case_id' => $row->repasse_case_id, 'task_id' => $task,
                    'evidence_id' => $data['evidence_id'],
                    'created_by' => $request->user('api')->getAuthIdentifier(),
                    'created_at' => now(),
                ]);
                $this->operations->event((int) $row->repasse_case_id, $request, 'task_document_attached');
            }
        });
        return $this->attachments($task);
    }

    private function one(int $task): object
    {
        return TenantDB::table('repasse_case_tasks as t')
            ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->where('t.id', $task)
            ->select('t.id', 't.repasse_case_id', 'c.title as case_title',
                'c.status as case_status', 't.title', 't.details', 't.kind', 't.status',
                't.due_on', 't.start_time', 't.end_time', 't.remind_minutes',
                't.assigned_to', 'u.name as assignee_name', 't.priority',
                't.created_at', 't.updated_at')
            ->firstOrFail();
    }
}
