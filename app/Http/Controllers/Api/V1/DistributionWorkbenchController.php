<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DistributionBatchRequest;
use App\Http\Requests\Api\V1\SaveDistributionTemplateRequest;
use App\Services\DistributionBatchService;
use App\Services\DistributionTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DistributionWorkbenchController extends Controller
{
    public function __construct(
        private readonly DistributionTemplateService $templates,
        private readonly DistributionBatchService $batches
    ) {}

    public function templates(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'case_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['all', 'active', 'archived'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->json($this->templates->list($filters));
    }

    public function createTemplate(SaveDistributionTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();
        return $this->json(['data' => $this->templates->create(
            (int) $data['case_id'], $data, $request
        )], 201);
    }

    public function showTemplate(int $template): JsonResponse
    {
        return $this->json(['data' => $this->templates->show($template)]);
    }

    public function replaceTemplate(SaveDistributionTemplateRequest $request, int $template): JsonResponse
    {
        $data = $request->validated();
        return $this->json($this->templates->update(
            $template, (int) $data['revision'], $data, $request
        ));
    }

    public function changeTemplateState(Request $request, int $template): JsonResponse
    {
        $this->only($request, ['revision', 'action']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['archive', 'reopen'])],
        ]);
        return $this->json($this->templates->state(
            $template, (int) $data['revision'], $data['action'], $request
        ));
    }

    public function templateVersions(Request $request, int $template): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return $this->json($this->templates->versions($template));
    }

    public function templateVersion(int $template, int $revision): JsonResponse
    {
        return $this->json(['data' => $this->templates->version($template, $revision)]);
    }

    public function forkTemplate(Request $request, int $template): JsonResponse
    {
        $this->only($request, ['revision', 'name']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:2', 'max:180'],
        ]);
        return $this->json(['data' => $this->templates->fork(
            $template, (int) $data['revision'], trim($data['name']), $request
        )], 201);
    }

    public function previewTemplate(Request $request, int $template): JsonResponse
    {
        $this->only($request, ['template_revision', 'gross_amount_cents']);
        $data = $request->validate([
            'template_revision' => ['required', 'integer', 'min:1'],
            'gross_amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000000'],
        ]);
        return $this->json(['data' => $this->templates->preview(
            $template, (int) $data['template_revision'], (int) $data['gross_amount_cents']
        )]);
    }

    public function previewBatch(DistributionBatchRequest $request, int $template): JsonResponse
    {
        $data = $request->validated();
        return $this->json(['data' => $this->batches->preview(
            $template, (int) $data['template_revision'], $data['rows']
        )]);
    }

    public function applyBatch(DistributionBatchRequest $request, int $template): JsonResponse
    {
        $data = $request->validated();
        $result = $this->batches->apply($template, (int) $data['template_revision'], $data, $request);
        return $this->json($result, $result['created'] ? 201 : 200);
    }

    public function batches(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'template_id' => ['sometimes', 'integer', 'min:1'],
            'case_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->json($this->batches->list($filters));
    }

    public function showBatch(int $batch): JsonResponse
    {
        return $this->json(['data' => $this->batches->show($batch)]);
    }

    public function exportBatch(int $batch): StreamedResponse
    {
        $data = $this->batches->show($batch);
        return response()->streamDownload(function () use ($data): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, [
                'Lote', 'Modelo', 'Revisão', 'Cenário', 'Título', 'Moeda',
                'Valor previsto (centavos)', 'Origem prevista',
                'Destinatário previsto', 'Percentual informado (pontos-base)',
                'Valor simulado (centavos)', 'Diferença (centavos)', 'Estado',
            ], ';');
            $template = $data['template_snapshot'];
            $names = collect($template['lines'])->keyBy('party_id');
            foreach ($data['results'] as $result) {
                foreach ($result['allocations'] as $line) {
                    $target = $names[$line['party_id']]['party_name'] ?? ('Cadastro #'.$line['party_id']);
                    fputcsv($stream, [
                        $data['id'], $this->safe($template['name']),
                        $data['template_revision'], $result['scenario_id'],
                        $this->safe($result['title']), 'BRL',
                        $result['gross_amount_cents'], $this->safe($template['source_name']),
                        $this->safe($target), $line['basis_points'],
                        $line['amount_cents'], $result['difference_cents'],
                        'SIMULAÇÃO NÃO EXECUTÁVEL',
                    ], ';');
                }
            }
            fclose($stream);
        }, "simulacoes-lote-{$batch}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safe(?string $value): string
    {
        $clean = ltrim(str_replace(["\r", "\n", "\t"], ' ', $value ?? ''));
        return preg_match('/^[=+@\-\x{FF1D}\x{FF0B}]/u', $clean) ? "'".$clean : $clean;
    }

    private function only(Request $request, array $allowed): void
    {
        $extra = array_diff(array_keys($request->all()), $allowed);
        if ($extra !== []) {
            throw ValidationException::withMessages(array_fill_keys($extra, 'Campo não permitido.'));
        }
    }

    private function json(mixed $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }
}
