<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RepasseCaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'archived'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = DB::table('repasse_cases')->select([
            'id', 'title', 'description', 'status', 'created_at', 'updated_at',
        ]);
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['search']));
            $query->where('title', 'like', '%'.$term.'%');
        }
        return response()->json($query->orderByDesc('id')->paginate(15))
            ->header('Cache-Control', 'no-store');
    }

    public function show(int $case): JsonResponse
    {
        return response()->json(['data' => $this->details($case)])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'description' => ['nullable', 'string', 'max:3000'],
        ]);
        $title = trim($data['title']);
        if (mb_strlen($title) < 2) {
            throw ValidationException::withMessages(['title' => 'Título inválido.']);
        }
        $id = DB::transaction(function () use ($request, $data, $title): int {
            $id = DB::table('repasse_cases')->insertGetId([
                'title' => $title, 'description' => $data['description'] ?? null,
                'status' => 'draft', 'created_by' => $request->user('api')->id,
                'updated_by' => $request->user('api')->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->record($id, $request, 'case_created');
            return $id;
        });
        return response()->json(['data' => $this->details($id)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $case): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'status' => ['sometimes', Rule::in(['draft', 'archived'])],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['title' => 'Informe uma alteração.']);
        }
        $changed = DB::transaction(function () use ($request, $case, $data): bool {
            $current = DB::table('repasse_cases')->where('id', $case)->lockForUpdate()->first();
            if (! $current) abort(404);
            $title = array_key_exists('title', $data) ? trim($data['title']) : $current->title;
            if (mb_strlen($title) < 2) {
                throw ValidationException::withMessages(['title' => 'Título inválido.']);
            }
            $description = array_key_exists('description', $data) ? $data['description'] : $current->description;
            $status = $data['status'] ?? $current->status;
            if ($current->status === 'archived' && $status === 'archived' &&
                ($title !== $current->title || $description !== $current->description)) {
                throw ValidationException::withMessages(['status' => 'Reabra o dossiê para editar.']);
            }
            if ($title === $current->title && $description === $current->description && $status === $current->status) return false;
            DB::table('repasse_cases')->where('id', $case)->update([
                'title' => $title, 'description' => $description, 'status' => $status,
                'updated_by' => $request->user('api')->id, 'updated_at' => now(),
            ]);
            $event = $status !== $current->status
                ? ($status === 'archived' ? 'case_archived' : 'case_reopened')
                : 'case_updated';
            $this->record($case, $request, $event);
            return true;
        });
        return response()->json(['data' => $this->details($case), 'changed' => $changed])
            ->header('Cache-Control', 'no-store');
    }

    public function attach(Request $request, int $case): JsonResponse
    {
        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')],
            'description' => ['nullable', 'string', 'max:180'],
        ]);
        DB::transaction(function () use ($request, $case, $data): void {
            $current = DB::table('repasse_cases')->where('id', $case)->lockForUpdate()->first();
            if (! $current) abort(404);
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Dossiê arquivado.']);
            }
            $party = DB::table('parties')->where('id', $data['party_id'])->first();
            if (! $party || ! $party->is_active) {
                throw ValidationException::withMessages(['party_id' => 'Cadastro deve estar ativo.']);
            }
            if (DB::table('repasse_case_parties')->where('repasse_case_id', $case)
                ->where('party_id', $data['party_id'])->exists()) {
                throw ValidationException::withMessages(['party_id' => 'Cadastro já associado.']);
            }
            DB::table('repasse_case_parties')->insert([
                'repasse_case_id' => $case, 'party_id' => $data['party_id'],
                'description' => $data['description'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->record($case, $request, 'party_attached');
        });
        return response()->json(['data' => $this->details($case)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function detach(Request $request, int $case, int $party): JsonResponse
    {
        DB::transaction(function () use ($request, $case, $party): void {
            $current = DB::table('repasse_cases')->where('id', $case)->lockForUpdate()->first();
            if (! $current) abort(404);
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Dossiê arquivado.']);
            }
            $deleted = DB::table('repasse_case_parties')->where('repasse_case_id', $case)
                ->where('party_id', $party)->delete();
            if (! $deleted) abort(404);
            $this->record($case, $request, 'party_detached');
        });
        return response()->json(['data' => $this->details($case)])
            ->header('Cache-Control', 'no-store');
    }

    public function events(Request $request, int $case): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        if (! DB::table('repasse_cases')->where('id', $case)->exists()) abort(404);
        return response()->json([
            'events' => DB::table('repasse_case_events')->where('repasse_case_id', $case)
                ->select('id', 'event_type', 'actor_user_id', 'created_at')
                ->orderByDesc('id')->paginate(15),
        ])->header('Cache-Control', 'no-store');
    }

    private function details(int $id): array
    {
        $case = DB::table('repasse_cases')->where('id', $id)->first();
        if (! $case) abort(404);
        $parties = DB::table('repasse_case_parties as cp')
            ->join('parties as p', 'p.id', '=', 'cp.party_id')
            ->where('cp.repasse_case_id', $id)
            ->select('cp.party_id', 'cp.description', 'p.name', 'p.type', 'p.is_active')
            ->orderBy('cp.id')->get()->map(fn ($p) => [
                'party_id' => $p->party_id, 'description' => $p->description,
                'name' => $p->name, 'type' => $p->type, 'is_active' => (bool) $p->is_active,
            ]);
        return [
            'id' => $case->id, 'title' => $case->title, 'description' => $case->description,
            'status' => $case->status, 'created_at' => $case->created_at,
            'updated_at' => $case->updated_at, 'parties' => $parties,
        ];
    }

    private function record(int $case, Request $request, string $event): void
    {
        DB::table('repasse_case_events')->insert([
            'repasse_case_id' => $case, 'actor_user_id' => $request->user('api')->id,
            'event_type' => $event, 'created_at' => now(),
        ]);
    }
}
