<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FinancialPreparationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Preparação para discussão/comprovação documental. Nenhuma função escreve
 * dinheiro ou indica direito de recebimento.
 */
final class FinancialPreparationController extends Controller
{
    public function __construct(private readonly FinancialPreparationService $preparation) {}

    public function show(int $case, int $term): JsonResponse
    {
        return response()->json(['data' => $this->preparation->show($case, $term)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function export(int $case, int $term): StreamedResponse
    {
        // Calcula previamente e valida SHA/permissões/escopo antes de iniciar
        // streaming, para não devolver CSV parcial em caso de 404/409.
        $data = $this->preparation->show($case, $term);
        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Tipo', 'Campo', 'Valor'], ';');
            $row = function (string $type, string $label, mixed $value) use ($out): void {
                $text = trim(str_replace(["\r", "\n", "\t"], ' ', (string) ($value ?? '')));
                // CSV anti-formula para strings arbitrárias inseridas pelo operador.
                if (preg_match('/^[=+@\-]/u', $text)) {
                    $text = "'".$text;
                }
                fputcsv($out, [$type, $label, $text], ';');
            };
            foreach ([
                'case_id', 'case_title', 'term_id', 'term_title',
                'term_revision', 'term_sha256', 'template_id',
                'template_revision', 'template_sha256', 'manual_reference_cents',
                'sale_result_known_cents', 'review_state',
                'documentary_preparation_complete', 'snapshot_sha256',
            ] as $key) {
                $value = $data[$key];
                $row('Resumo', $key, is_bool($value) ? ($value ? 'SIM' : 'NÃO') : $value);
            }
            foreach ($data['allocations'] as $line) {
                $row('Simulação', 'Participante #'.$line['party_id'], implode(' | ', [
                    $line['party_name'] ?? '', (string) $line['basis_points'],
                    (string) $line['amount_cents'],
                ]));
            }
            foreach ($data['issues'] as $issue) {
                $row('Pendência documental', $issue['key'], $issue['message']);
            }
            foreach ($data['missing_execution_decisions'] as $key => $value) {
                $row('Execução não habilitada', (string) ($key + 1), $value);
            }
            $row('Aviso', 'Autoriza pagamento', 'NÃO');
            $row('Aviso', 'Gera obrigação ou saldo', 'NÃO');
            fclose($out);
        }, "veiculo-{$case}-minuta-{$term}-pre-repasse.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
