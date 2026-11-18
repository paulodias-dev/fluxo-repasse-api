<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\TenantDB;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveScenarioRequest;
use App\Services\ScenarioPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RepasseScenarioController extends Controller
{
    public function __construct(private readonly ScenarioPlanner $planner) {}

    public function summary(): JsonResponse
    {
        return $this->json(['data' => $this->planner->summary()]);
    }

    public function cases(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = TenantDB::table('repasse_cases')->where('status', 'draft')
            ->select('id', 'title', 'status')->orderBy('title')->orderBy('id');
        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['search']));
            $query->where('title', 'like', '%'.$term.'%');
        }
        return $this->json($query->paginate(20));
    }

    public function parties(int $case): JsonResponse
    {
        TenantDB::table('repasse_cases')->where('id', $case)->firstOrFail();
        return $this->json([
            'data' => TenantDB::table('repasse_case_parties as cp')
                ->join('parties as p', 'p.id', '=', 'cp.party_id')
                ->where('cp.repasse_case_id', $case)
                ->select('p.id', 'p.name', 'p.type', 'p.is_active')
                ->orderBy('p.name')->get()->map(static fn ($p): array => [
                    'id' => (int) $p->id, 'name' => $p->name,
                    'type' => $p->type, 'is_active' => (bool) $p->is_active,
                ]),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'active', 'archived'])],
            'case_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->json($this->planner->list($filters));
    }

    public function show(int $scenario): JsonResponse
    {
        return $this->json(['data' => $this->planner->show($scenario)]);
    }

    public function store(SaveScenarioRequest $request): JsonResponse
    {
        $result = $this->planner->create($request->validated(), $request);
        return $this->json($result, $result['created'] ? 201 : 200);
    }

    public function replace(SaveScenarioRequest $request, int $scenario): JsonResponse
    {
        return $this->json($this->planner->replace($scenario, $request->validated(), $request));
    }

    public function changeState(Request $request, int $scenario): JsonResponse
    {
        $this->rejectUnknown($request, ['revision', 'action']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['archive', 'reopen'])],
        ]);
        return $this->json($this->planner->changeState($scenario, (int) $data['revision'], $data['action'], $request));
    }

    public function versions(Request $request, int $scenario): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return $this->json($this->planner->versions($scenario));
    }

    public function version(int $scenario, int $revision): JsonResponse
    {
        return $this->json(['data' => $this->planner->versionDetail($scenario, $revision)]);
    }

    public function compare(Request $request, int $scenario): JsonResponse
    {
        $data = $request->validate([
            'left' => ['required', 'integer', 'min:1'],
            'right' => ['required', 'integer', 'min:1'],
        ]);
        $left = $this->planner->versionDetail($scenario, (int) $data['left']);
        $right = $this->planner->versionDetail($scenario, (int) $data['right']);
        $start = $left['snapshot'];
        $end = $right['snapshot'];
        $amounts = static fn (array $snapshot): array => collect($snapshot['allocations'])
            ->mapWithKeys(static fn ($line) => [(int) $line['party_id'] => (int) $line['amount_cents']])
            ->all();
        $before = $amounts($start);
        $after = $amounts($end);
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $partyId) {
            $initial = $before[$partyId] ?? 0;
            $final = $after[$partyId] ?? 0;
            if ($initial !== $final) $changes[] = [
                'party_id' => (int) $partyId, 'before_cents' => $initial,
                'after_cents' => $final, 'delta_cents' => $final - $initial,
            ];
        }
        return $this->json(['data' => [
            'left' => $left, 'right' => $right,
            'gross_delta_cents' => $end['gross_amount_cents'] - $start['gross_amount_cents'],
            'source_changed' => $start['source_party_id'] !== $end['source_party_id'],
            'allocation_changes' => $changes,
        ]]);
    }

    public function fork(Request $request, int $scenario): JsonResponse
    {
        $this->rejectUnknown($request, ['revision', 'client_reference', 'title']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'client_reference' => ['required', 'uuid'],
            'title' => ['sometimes', 'string', 'min:2', 'max:180'],
        ]);
        $result = $this->planner->fork($scenario, (int) $data['revision'], $data, $request);
        return $this->json($result, $result['created'] ? 201 : 200);
    }

    public function export(int $scenario): StreamedResponse
    {
        $data = $this->planner->show($scenario);
        return response()->streamDownload(function () use ($data): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['Cenário', 'Revisão', 'Dossiê', 'Moeda', 'Origem prevista',
                'Valor previsto (centavos)', 'Destinatário previsto', 'Alocação (centavos)', 'Nota'], ';');
            foreach ($data['allocations'] as $line) {
                fputcsv($stream, [
                    $this->csvSafe($data['title']), $data['revision'],
                    $this->csvSafe($data['case_title']), 'BRL',
                    $this->csvSafe($data['source_name']),
                    $data['gross_amount_cents'], $this->csvSafe($line['party_name']),
                    $line['amount_cents'], $this->csvSafe($line['memo']),
                ], ';');
            }
            fputcsv($stream, ['DIFERENÇA NÃO LIQUIDADA', '', '', 'BRL', '',
                $data['difference_cents'], '', '', 'SIMULAÇÃO NÃO EXECUTÁVEL'], ';');
            fclose($stream);
        }, "cenario-repasse-{$scenario}-rev-{$data['revision']}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function csvSafe(?string $value): string
    {
        $clean = ltrim(str_replace(["\r", "\n", "\t"], ' ', $value ?? ''));
        return preg_match('/^[=+@\-\x{FF1D}\x{FF0B}]/u', $clean) ? "'".$clean : $clean;
    }

    private function rejectUnknown(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->all()), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Campo não permitido.'));
        }
    }

    private function json(mixed $value, int $status = 200): JsonResponse
    {
        return response()->json($value, $status)->header('Cache-Control', 'private, no-store');
    }
}
