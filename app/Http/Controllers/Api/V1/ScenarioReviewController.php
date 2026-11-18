<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\TenantDB;

use App\Http\Controllers\Controller;
use App\Services\ScenarioReviewQueries;
use App\Services\ScenarioReviewService;
use App\Services\ScenarioPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ScenarioReviewController extends Controller
{
    public function __construct(
        private readonly ScenarioReviewService $reviews,
        private readonly ScenarioReviewQueries $queries,
        private readonly ScenarioPlanner $planner
    ) {}

    public function summary(Request $request): JsonResponse
    {
        return $this->json(['data' => $this->queries->summary((int) $request->user('api')->id)]);
    }

    public function reviewers(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        return $this->json($this->queries->reviewers($filters));
    }

    public function index(Request $request): JsonResponse
    {
        return $this->json($this->queries->list($this->filters($request), (int) $request->user('api')->id));
    }

    public function forScenario(Request $request, int $scenario): JsonResponse
    {
        TenantDB::table('repasse_scenarios')->where('id', $scenario)->firstOrFail();
        $filters = $this->filters($request);
        $filters['scenario_id'] = $scenario;
        return $this->json($this->queries->list($filters, (int) $request->user('api')->id));
    }

    public function show(int $review): JsonResponse
    {
        return $this->json(['data' => $this->reviews->show($review)]);
    }

    public function store(Request $request, int $scenario): JsonResponse
    {
        $this->only($request, ['request_key', 'scenario_revision', 'request_note', 'assigned_to', 'due_on']);
        $data = $request->validate([
            'request_key' => ['required', 'uuid'],
            'scenario_revision' => ['required', 'integer', 'min:1'],
            'request_note' => ['required', 'string', 'min:10', 'max:5000'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $result = $this->reviews->create($scenario, $data, $request);
        return $this->json($result, $result['created'] ? 201 : 200);
    }

    public function assign(Request $request, int $review): JsonResponse
    {
        $this->only($request, ['revision', 'assigned_to']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'assigned_to' => ['present', 'nullable', 'integer', 'min:1'],
        ]);
        return $this->json($this->reviews->assign($review, $data, $request));
    }

    public function decide(Request $request, int $review): JsonResponse
    {
        $this->only($request, ['revision', 'decision', 'note']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::in(['reviewed', 'changes_requested'])],
            'note' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        return $this->json($this->reviews->decide($review, $data, $request));
    }

    public function comment(Request $request, int $review): JsonResponse
    {
        $this->only($request, ['body']);
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
        ]);
        return $this->json($this->reviews->comment($review, $data, $request), 201);
    }

    public function withdraw(Request $request, int $review): JsonResponse
    {
        $this->only($request, ['revision', 'note']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        return $this->json($this->reviews->withdraw($review, $data, $request));
    }

    public function events(Request $request, int $review): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return $this->json($this->queries->events($review));
    }

    public function snapshot(int $review): JsonResponse
    {
        return $this->json(['data' => $this->reviews->snapshot($review, $this->planner)]);
    }

    public function integrity(int $review): JsonResponse
    {
        return $this->json(['data' => $this->reviews->verify($review)]);
    }

    public function export(int $review): StreamedResponse
    {
        $record = $this->reviews->show($review);
        $historical = $this->reviews->snapshot($review, $this->planner);
        return response()->streamDownload(function () use ($review, $record, $historical): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['Tipo', 'Solicitação', 'Cenário', 'Revisão do cenário', 'Status da análise',
                'Data', 'Usuário', 'Descrição', 'Hash', 'Observação'], ';');
            fputcsv($stream, ['REVISAO_DOCUMENTAL', $review, $record['scenario_id'],
                $record['scenario_revision'], $record['status'], $record['created_at'],
                $this->csv($record['requester_name']), $this->csv($record['request_note']),
                $record['scenario_digest'], 'NÃO AUTORIZA PAGAMENTOS'], ';');
            $snapshot = $historical['snapshot'];
            fputcsv($stream, ['PROPOSTA_ORIGINAL', $review, $record['scenario_id'],
                $record['scenario_revision'], $record['status'], $record['created_at'],
                $this->csv($snapshot['source_name'] ?? null),
                $this->csv($snapshot['title'] ?? null), $historical['scenario_sha256'],
                'VALOR PREVISTO EM CENTAVOS: '.$snapshot['gross_amount_cents']], ';');
            fputcsv($stream, ['DIFERENÇA_DE_PREVISAO', $review, $record['scenario_id'],
                $record['scenario_revision'], $record['status'], '', '', '',
                '', 'CENTAVOS: '.$snapshot['difference_cents']], ';');
            foreach ($snapshot['allocations'] as $allocation) {
                fputcsv($stream, ['DESTINATARIO_PREVISTO', $review, $record['scenario_id'],
                    $record['scenario_revision'], $record['status'], '', '',
                    $this->csv($allocation['party_name'] ?? null),
                    '', 'CENTAVOS: '.$allocation['amount_cents'].'; SEM AUTORIZAÇÃO FINANCEIRA'], ';');
            }
            $events = TenantDB::table('repasse_scenario_review_events as e')
                ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
                ->where('e.review_id', $review)->select('e.sequence', 'e.event_type',
                    'e.created_at', 'e.body', 'e.event_hash', 'u.name as actor_name')
                ->orderBy('e.sequence')->cursor();
            foreach ($events as $event) {
                fputcsv($stream, [$this->csv($event->event_type), $review,
                    $record['scenario_id'], $record['scenario_revision'],
                    $record['status'], $event->created_at,
                    $this->csv($event->actor_name), $this->csv($event->body),
                    $event->event_hash, 'TRILHA INTERNA DE REVISÃO'], ';');
            }
            fclose($stream);
        }, "revisao-documental-{$review}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['sometimes', Rule::in(['all', 'pending', 'reviewed', 'changes_requested', 'withdrawn'])],
            'scope' => ['sometimes', Rule::in(['all', 'mine', 'requested', 'unassigned'])],
            'stale' => ['sometimes', 'boolean'],
            'overdue' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'scenario_id' => ['sometimes', 'integer', 'min:1'],
            'case_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    private function only(Request $request, array $allow): void
    {
        $unknown = array_diff(array_keys($request->all()), $allow);
        if ($unknown !== []) throw ValidationException::withMessages(
            array_fill_keys($unknown, 'Campo não permitido na revisão documental.')
        );
    }

    private function csv(?string $value): string
    {
        $value = ltrim(str_replace(["\r", "\n", "\t"], ' ', $value ?? ''));
        return preg_match('/^[=+@\-\x{FF1D}\x{FF0B}]/u', $value) ? "'".$value : $value;
    }

    private function json(mixed $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }
}
