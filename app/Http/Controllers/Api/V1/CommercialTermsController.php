<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CaseOperations;
use App\Services\CommercialTermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommercialTermsController extends Controller
{
    public function __construct(
        private readonly CommercialTermsService $terms,
        private readonly CaseOperations $cases
    ) {}

    private function payload(Request $request, bool $editing = false): array
    {
        $allowed = [
            'title', 'description', 'template_id', 'template_revision',
            'reference_cents', 'conditions', 'evidence_ids',
        ];
        if ($editing) $allowed[] = 'revision';
        $this->cases->restrict($request, $allowed);

        $keys = implode(',', array_keys(CommercialTermsService::FIELDS));
        $rules = [
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'description' => ['present', 'nullable', 'string', 'max:4000'],
            'template_id' => ['required', 'integer', 'min:1'],
            'template_revision' => ['required', 'integer', 'min:1'],
            'reference_cents' => ['present', 'nullable', 'integer', 'min:1', 'max:1000000000000'],
            'conditions' => ['required', 'array:'.$keys],
            'evidence_ids' => ['present', 'array', 'max:25'],
            'evidence_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
        foreach (CommercialTermsService::FIELDS as $field => $label) {
            $rules['conditions.'.$field] = ['present', 'nullable', 'string', 'max:2000'];
        }
        if ($editing) $rules['revision'] = ['required', 'integer', 'min:1'];
        return $request->validate($rules);
    }

    public function index(Request $request, int $case): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['all', 'active', 'archived'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->json($this->terms->list($case, $filters));
    }

    public function store(Request $request, int $case): JsonResponse
    {
        $data = $this->payload($request);
        return $this->json(['data' => $this->terms->create($case, $data, $request)], 201);
    }

    public function show(int $case, int $term): JsonResponse
    {
        return $this->json(['data' => $this->terms->show($case, $term)]);
    }

    public function update(Request $request, int $case, int $term): JsonResponse
    {
        $data = $this->payload($request, true);
        return $this->json($this->terms->update($case, $term, $data, $request));
    }

    public function state(Request $request, int $case, int $term): JsonResponse
    {
        $this->cases->restrict($request, ['revision', 'action']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['archive', 'reopen'])],
        ]);
        return $this->json($this->terms->state(
            $case, $term, (int) $data['revision'], $data['action'], $request
        ));
    }

    public function versions(Request $request, int $case, int $term): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return $this->json($this->terms->versions($case, $term));
    }

    public function version(int $case, int $term, int $revision): JsonResponse
    {
        return $this->json(['data' => $this->terms->version($case, $term, $revision)]);
    }

    public function preview(int $case, int $term): JsonResponse
    {
        return $this->json(['data' => $this->terms->preview($case, $term)]);
    }

    public function fork(Request $request, int $case, int $term): JsonResponse
    {
        $this->cases->restrict($request, ['revision', 'title']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'min:2', 'max:180'],
        ]);
        return $this->json([
            'data' => $this->terms->fork($case, $term, (int) $data['revision'],
                trim($data['title']), $request),
        ], 201);
    }

    public function export(Request $request, int $case): StreamedResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['all', 'active', 'archived'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $this->cases->ensureExists($case);
        $query = DB::table('repasse_commercial_terms')->where('repasse_case_id', $case);
        if (($filters['status'] ?? 'all') === 'active') $query->whereNull('archived_at');
        if (($filters['status'] ?? 'all') === 'archived') $query->whereNotNull('archived_at');
        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($filters['search']));
            $query->where('title', 'like', '%'.$term.'%');
        }
        return response()->streamDownload(function () use ($query): void {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['ID', 'Minuta', 'Revisão', 'Status', 'Modelo ID',
                'Revisão do modelo', 'Referência em centavos', 'Observações',
                'SHA-256 da minuta', 'SHA-256 do modelo', 'Origem da cópia ID',
                'Origem da cópia revisão',
                ...array_values(CommercialTermsService::FIELDS),
                'Aprovação bancária', 'Criada em'], ';');
            $query->orderBy('id')->chunkById(100, function ($rows) use ($file): void {
                foreach ($rows as $row) {
                    $terms = json_decode($row->conditions, true, 512, JSON_THROW_ON_ERROR);
                    fputcsv($file, [
                        $row->id, $this->safe($row->title), $row->revision,
                        $row->archived_at ? 'Arquivada' : 'Em elaboração',
                        $row->template_id, $row->template_revision,
                        $row->reference_cents, $this->safe($row->description),
                        $row->content_sha256, $row->template_sha256,
                        $row->forked_from_term_id, $row->forked_from_revision,
                        ...array_map(fn (string $field): string =>
                            $this->safe($terms[$field] ?? null),
                            array_keys(CommercialTermsService::FIELDS)),
                        'NÃO AUTORIZA PAGAMENTO', $row->created_at,
                    ], ';');
                }
            });
            fclose($file);
        }, "veiculo-{$case}-condicoes-comerciais.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safe(?string $text): string
    {
        $value = trim(str_replace(["\r", "\n", "\t"], ' ', $text ?? ''));
        return preg_match('/^[=+@\-]/u', $value) ? "'".$value : $value;
    }

    private function json(mixed $content, int $status = 200): JsonResponse
    {
        return response()->json($content, $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
