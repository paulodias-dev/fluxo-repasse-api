<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CaseOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CaseWorkController extends Controller
{
    public function __construct(private readonly CaseOperations $operations) {}

    public function tasks(Request $request, int $case): JsonResponse
    {
        $this->operations->ensureExists($case);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json(DB::table('repasse_case_tasks as t')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->where('t.repasse_case_id', $case)
            ->select('t.id', 't.repasse_case_id', 't.title', 't.details',
                't.status', 't.priority', 't.due_on', 't.assigned_to',
                'u.name as assignee_name', 't.completed_at', 't.created_at', 't.updated_at')
            ->orderByRaw("CASE WHEN t.status IN ('done', 'cancelled') THEN 1 ELSE 0 END")
            ->orderBy('t.due_on')->orderByDesc('t.id')->paginate(30))
            ->header('Cache-Control', 'no-store');
    }

    public function createTask(Request $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, ['title', 'details', 'priority', 'due_on', 'assigned_to']);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'details' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        ]);
        $this->validateAssignment($data['assigned_to'] ?? null);
        $title = trim($data['title']);
        if (mb_strlen($title) < 2) throw ValidationException::withMessages(['title' => 'Título inválido.']);
        $id = DB::transaction(function () use ($request, $case, $data, $title): int {
            $this->operations->editable($case);
            $id = DB::table('repasse_case_tasks')->insertGetId([
                'repasse_case_id' => $case, 'title' => $title,
                'details' => $data['details'] ?? null, 'status' => 'pending',
                'priority' => $data['priority'] ?? 'normal',
                'due_on' => $data['due_on'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                'created_by' => $request->user('api')->getAuthIdentifier(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->operations->event($case, $request, 'task_created');
            return $id;
        });

        return response()->json(['data' => $this->task($case, $id)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function updateTask(Request $request, int $case, int $task): JsonResponse
    {
        $this->operations->restrict($request, ['title', 'details', 'status', 'priority', 'due_on', 'assigned_to']);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'details' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'status' => ['sometimes', Rule::in(['pending', 'doing', 'done', 'cancelled'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        ]);
        if ($data === []) throw ValidationException::withMessages(['title' => 'Informe uma alteração.']);
        if (array_key_exists('assigned_to', $data)) $this->validateAssignment($data['assigned_to']);
        $changed = DB::transaction(function () use ($request, $case, $task, $data): bool {
            $this->operations->editable($case);
            $existing = DB::table('repasse_case_tasks')
                ->where('repasse_case_id', $case)->where('id', $task)->lockForUpdate()->firstOrFail();

            $updates = [];
            foreach (['title', 'details', 'status', 'priority', 'due_on', 'assigned_to'] as $field) {
                if (! array_key_exists($field, $data)) continue;
                $value = $field === 'title' ? trim($data[$field]) : $data[$field];
                if ($field === 'title' && mb_strlen($value) < 2) {
                    throw ValidationException::withMessages(['title' => 'Título inválido.']);
                }
                if ((string) ($existing->{$field} ?? '') !== (string) ($value ?? '')) $updates[$field] = $value;
            }
            if ($updates === []) return false;
            if (array_key_exists('status', $updates)) {
                $updates['completed_at'] = $updates['status'] === 'done' ? now() : null;
            }
            $updates['updated_at'] = now();
            DB::table('repasse_case_tasks')->where('id', $task)->update($updates);
            $this->operations->event($case, $request, 'task_updated');
            return true;
        });
        return response()->json(['data' => $this->task($case, $task), 'changed' => $changed])
            ->header('Cache-Control', 'no-store');
    }

    public function checklist(Request $request, int $case): JsonResponse
    {
        $this->operations->ensureExists($case);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return response()->json(DB::table('repasse_case_checklist')
            ->where('repasse_case_id', $case)
            ->select('id', 'label', 'checked_at', 'checked_by', 'created_at')
            ->orderBy('id')->paginate(50))
            ->header('Cache-Control', 'no-store');
    }

    public function addChecklist(Request $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, ['label']);
        $data = $request->validate(['label' => ['required', 'string', 'min:2', 'max:200']]);
        $label = trim($data['label']);
        if (mb_strlen($label) < 2) throw ValidationException::withMessages(['label' => 'Descrição inválida.']);
        $id = DB::transaction(function () use ($request, $case, $label): int {
            $this->operations->editable($case);
            $id = DB::table('repasse_case_checklist')->insertGetId([
                'repasse_case_id' => $case, 'label' => $label,
                'created_by' => $request->user('api')->getAuthIdentifier(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->operations->event($case, $request, 'checklist_added');
            return $id;
        });
        return response()->json(['data' => DB::table('repasse_case_checklist')->where('id', $id)->first()], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function updateChecklist(Request $request, int $case, int $item): JsonResponse
    {
        $this->operations->restrict($request, ['label', 'checked']);
        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'min:2', 'max:200'],
            'checked' => ['sometimes', 'required', 'boolean'],
        ]);
        if ($data === []) throw ValidationException::withMessages(['label' => 'Informe uma alteração.']);
        DB::transaction(function () use ($request, $case, $item, $data): void {
            $this->operations->editable($case);
            $existing = DB::table('repasse_case_checklist')->where('repasse_case_id', $case)
                ->where('id', $item)->lockForUpdate()->firstOrFail();
            $updates = [];
            if (array_key_exists('label', $data)) {
                $label = trim($data['label']);
                if (mb_strlen($label) < 2) throw ValidationException::withMessages(['label' => 'Descrição inválida.']);
                if ($label !== $existing->label) $updates['label'] = $label;
            }
            if (array_key_exists('checked', $data) && $data['checked'] !== ($existing->checked_at !== null)) {
                $updates['checked_at'] = $data['checked'] ? now() : null;
                $updates['checked_by'] = $data['checked'] ? $request->user('api')->getAuthIdentifier() : null;
            }
            if ($updates === []) return;
            $updates['updated_at'] = now();
            DB::table('repasse_case_checklist')->where('id', $item)->update($updates);
            $this->operations->event($case, $request, 'checklist_updated');
        });
        return response()->json(['data' => DB::table('repasse_case_checklist')
            ->where('repasse_case_id', $case)->where('id', $item)->firstOrFail()])
            ->header('Cache-Control', 'no-store');
    }

    public function notes(Request $request, int $case): JsonResponse
    {
        $this->operations->ensureExists($case);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return response()->json(DB::table('repasse_case_notes as notes')
            ->leftJoin('users as u', 'u.id', '=', 'notes.author_id')
            ->where('notes.repasse_case_id', $case)
            ->select('notes.id', 'notes.body', 'notes.author_id', 'u.name as author_name', 'notes.created_at')
            ->orderByDesc('notes.id')->paginate(30))
            ->header('Cache-Control', 'no-store');
    }

    public function addNote(Request $request, int $case): JsonResponse
    {
        $this->operations->restrict($request, ['body']);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000']]);
        $body = trim($data['body']);
        if (mb_strlen($body) < 2) throw ValidationException::withMessages(['body' => 'Nota inválida.']);
        $id = DB::transaction(function () use ($request, $case, $body): int {
            $this->operations->editable($case);
            $id = DB::table('repasse_case_notes')->insertGetId([
                'repasse_case_id' => $case, 'body' => $body,
                'author_id' => $request->user('api')->getAuthIdentifier(),
                'created_at' => now(),
            ]);
            $this->operations->event($case, $request, 'note_added');
            return $id;
        });
        return response()->json(['data' => DB::table('repasse_case_notes')
            ->where('id', $id)->first()], 201)->header('Cache-Control', 'no-store');
    }

    private function task(int $case, int $id): object
    {
        return DB::table('repasse_case_tasks as t')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->where('t.repasse_case_id', $case)->where('t.id', $id)
            ->select('t.id', 't.repasse_case_id', 't.title', 't.details', 't.status',
                't.priority', 't.due_on', 't.assigned_to', 'u.name as assignee_name',
                't.completed_at', 't.created_at', 't.updated_at')->firstOrFail();
    }

    private function validateAssignment(?int $id): void
    {
        if ($id === null) return;
        $user = User::query()->find($id);
        if (! $user || ! $user->is_active || ! $user->hasPermission('cases.manage')) {
            throw ValidationException::withMessages(['assigned_to' => 'Responsável sem acesso ao módulo.']);
        }
    }
}
