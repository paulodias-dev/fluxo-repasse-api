<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\TenantTable;

use App\Http\Controllers\Controller;
use App\Services\CasePackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class CasePackageController extends Controller
{
    public function __construct(private readonly CasePackageService $packages) {}

    public function options(Request $request, int $case): JsonResponse
    {
        TenantTable::table('repasse_cases')->where('id', $case)->firstOrFail();
        $filters = $request->validate([
            'kind' => ['required', Rule::in(['evidence', 'scenarios', 'reviews'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $search = trim($filters['search'] ?? '');
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

        $query = match ($filters['kind']) {
            'evidence' => TenantTable::table('repasse_case_evidence')->where('repasse_case_id', $case)
                ->whereNull('archived_at')
                ->select('id', 'original_name as title', 'size_bytes', 'sha256', 'created_at'),
            'scenarios' => TenantTable::table('repasse_scenarios')->where('repasse_case_id', $case)
                ->whereNull('archived_at')
                ->select('id', 'title', 'revision', 'gross_amount_cents', 'created_at'),
            'reviews' => TenantTable::table('repasse_scenario_reviews as r')
                ->join('repasse_scenarios as s', 's.id', '=', 'r.scenario_id')
                ->where('s.repasse_case_id', $case)
                ->select('r.id', 'r.status', 'r.scenario_id',
                    'r.scenario_revision', 's.title', 'r.created_at'),
        };
        if ($search !== '') {
            $field = match ($filters['kind']) {
                'evidence' => 'original_name',
                'scenarios' => 'title',
                'reviews' => 's.title',
            };
            $query->where($field, 'like', '%'.$term.'%');
        }
        return $this->json($query->orderByDesc('id')->paginate(25));
    }

    public function index(Request $request, int $case): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return $this->json($this->packages->list($case));
    }

    public function preview(Request $request, int $case): JsonResponse
    {
        $data = $this->validateBody($request, false);
        return $this->json(['data' => $this->packages->preview($case, $data)]);
    }

    public function store(Request $request, int $case): JsonResponse
    {
        $data = $this->validateBody($request, true);
        $result = $this->packages->create($case, $data, $request);
        return $this->json($result, $result['created'] ? 201 : 200);
    }

    public function show(int $case, int $package): JsonResponse
    {
        return $this->json(['data' => $this->packages->show($case, $package)]);
    }

    public function integrity(int $case, int $package): JsonResponse
    {
        return $this->json(['data' => $this->packages->verify($case, $package)]);
    }

    public function compare(Request $request, int $case, int $package): JsonResponse
    {
        $filters = $request->validate([
            'other_id' => ['required', 'integer', 'min:1'],
        ]);
        return $this->json(['data' => $this->packages->compare($case, $package, (int) $filters['other_id'])]);
    }

    public function download(int $case, int $package): BinaryFileResponse
    {
        $temporary = $this->packages->archive($case, $package);
        return response()->download($temporary, "dossie-{$case}-pacote-{$package}.zip", [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    private function validateBody(Request $request, bool $create): array
    {
        $allow = ['title', 'note', 'evidence_ids', 'scenario_ids', 'review_ids'];
        if ($create) array_push($allow, 'request_key', 'expected_manifest_sha256');
        $unknown = array_diff(array_keys($request->all()), $allow);
        if ($unknown !== []) throw ValidationException::withMessages(
            array_fill_keys($unknown, 'Campo não permitido no pacote.')
        );
        $rules = [
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'note' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'evidence_ids' => ['sometimes', 'array', 'max:40'],
            'evidence_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'scenario_ids' => ['sometimes', 'array', 'max:30'],
            'scenario_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'review_ids' => ['sometimes', 'array', 'max:30'],
            'review_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
        if ($create) {
            $rules['request_key'] = ['required', 'uuid'];
            $rules['expected_manifest_sha256'] = ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'];
        }
        $input = $request->validate($rules);
        if (mb_strlen(trim($input['title'])) < 2) {
            throw ValidationException::withMessages(['title' => 'Informe um nome válido.']);
        }
        return $input;
    }

    private function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
