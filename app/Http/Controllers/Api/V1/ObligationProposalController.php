<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreObligationProposalRequest;
use App\Services\CaseOperations;
use App\Services\ObligationProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ObligationProposalController extends Controller
{
    public function __construct(
        private readonly ObligationProposalService $proposals,
        private readonly CaseOperations $cases,
    ) {}

    public function options(int $case, int $term): JsonResponse
    {
        return response()->json(['data' => $this->proposals->options($case, $term)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, int $case, int $term): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->proposals->index($case, $term))
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreObligationProposalRequest $request, int $case, int $term): JsonResponse
    {
        $this->cases->restrict($request, [
            'request_key', 'preparation_record_id', 'expected_record_sha256',
            'payer_party_id', 'trigger_on', 'due_on', 'operator_note',
        ]);
        $result = $this->proposals->store($case, $term, $request->validated(), $request);

        return response()->json($result, $result['created'] ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(int $case, int $term, int $proposal): JsonResponse
    {
        return response()->json(['data' => $this->proposals->show($case, $term, $proposal)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function export(int $case, int $term, int $proposal): StreamedResponse
    {
        // Valida escopo e SHA ANTES de iniciar a resposta CSV.
        $view = $this->proposals->show($case, $term, $proposal);
        $snap = $view['snapshot'];

        return response()->streamDownload(static function () use ($view, $snap): void {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Grupo', 'Campo', 'Valor'], ';');
            $row = static function (string $group, string $field, mixed $value) use ($file): void {
                $v = trim(str_replace(["\r", "\n", "\t"], ' ', (string) ($value ?? '')));
                if (preg_match('/^[=+@\-]/u', $v)) {
                    $v = "'".$v;
                }
                fputcsv($file, [$group, $field, $v], ';');
            };
            $row('Proposta não exigível', 'Número', $view['id']);
            $row('Proposta não exigível', 'Responsável declarado', $snap['payer_party_name']);
            $row('Proposta não exigível', 'Data de evento declarada', $snap['trigger_on']);
            $row('Proposta não exigível', 'Data pretendida', $snap['due_on']);
            $row('Proposta não exigível', 'Observação', $snap['operator_note']);
            $row('Proposta não exigível', 'Apuração de origem', $snap['preparation_record_id']);
            $row('Proposta não exigível', 'Valor hipotético em centavos', $snap['net_cents']);
            foreach ($snap['declared_conditions'] as $key => $value) {
                $row('Condição contratual NÃO aprovada', (string) $key, $value);
            }
            foreach ($snap['lines'] as $line) {
                $row('Distribuição proposta', 'Participante #'.$line['party_id'],
                    $line['proposed_amount_cents']);
            }
            foreach ($snap['documentary_issues'] as $issue) {
                $row('Pendência documental', $issue['key'], $issue['message']);
            }
            $row('Integridade', 'SHA-256', $view['snapshot_sha256']);
            $row('Integridade', 'Fonte alterada', $view['stale'] ? 'SIM' : 'NÃO');
            $row('Governança', 'Obrigação constituída', 'NÃO');
            $row('Governança', 'Autorização ou pagamento', 'NÃO');
            fclose($file);
        }, "veiculo-{$case}-proposta-{$proposal}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
