<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePreparationRecordRequest;
use App\Services\CaseOperations;
use App\Services\PreparationRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PreparationRecordController extends Controller
{
    public function __construct(
        private readonly PreparationRecordService $records,
        private readonly CaseOperations $cases
    ) {}

    public function index(Request $request, int $case, int $term): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return response()->json($this->records->index($case, $term))
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(StorePreparationRecordRequest $request, int $case, int $term): JsonResponse
    {
        $this->cases->restrict($request, [
            'request_key', 'expected_snapshot_sha256', 'adjustments', 'replaces_record_id',
        ]);
        $result = $this->records->store($case, $term, $request->validated(), $request);
        return response()->json($result, $result['created'] ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(int $case, int $term, int $record): JsonResponse
    {
        return response()->json(['data' => $this->records->show($case, $term, $record)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function export(int $case, int $term, int $record): StreamedResponse
    {
        // Integridade do snapshot e escopo validado ANTES de iniciar stream.
        $view = $this->records->show($case, $term, $record);
        $snap = $view['snapshot'];
        return response()->streamDownload(function () use ($view, $snap): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Grupo', 'Campo', 'Valor'], ';');
            $put = static function (string $group, string $field, mixed $value) use ($out): void {
                $content = trim(str_replace(["\r", "\n", "\t"], ' ', (string) ($value ?? '')));
                if (preg_match('/^[=+@\-]/u', $content)) {
                    $content = "'".$content;
                }
                fputcsv($out, [$group, $field, $content], ';');
            };
            foreach ([
                'case_id', 'term_id', 'term_revision', 'term_sha256',
                'template_revision', 'template_sha256',
                'source_snapshot_sha256', 'reference_cents',
                'additions_cents', 'deductions_cents', 'net_cents',
                'allocated_cents', 'difference_cents', 'review_state',
            ] as $key) {
                $put('Apuração não executável', $key, $snap[$key]);
            }
            foreach ($snap['adjustments'] as $line) {
                $put('Ajuste manual', $line['kind'].' · '.$line['label'], $line['amount_cents']);
            }
            foreach ($snap['allocations'] as $line) {
                $put('Rateio hipotético', 'Participante #'.$line['party_id'].' · '
                    .$line['basis_points'].' bps', $line['amount_cents']);
            }
            foreach ($snap['documentary_issues'] as $issue) {
                $put('Pendência documental', $issue['key'], $issue['message']);
            }
            $put('Auditoria interna', 'SHA-256', $view['snapshot_sha256']);
            $put('Auditoria interna', 'Snapshot desatualizado', $view['stale'] ? 'SIM' : 'NÃO');
            $put('Não autorizado', 'Gera obrigação', 'NÃO');
            $put('Não autorizado', 'Autoriza pagamento', 'NÃO');
            fclose($out);
        }, "veiculo-{$case}-minuta-{$term}-apuracao-{$record}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
