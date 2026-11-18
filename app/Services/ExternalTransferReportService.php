<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Declarações de transferências EXTERNAS, informadas pelo operador.
 * Não movimenta dinheiro, não concede alçada e não constitui ledger, saldo,
 * obrigação exigível ou certificação bancária. Toda referência é documental.
 */
final class ExternalTransferReportService
{
    public function __construct(
        private readonly ObligationProposalService $proposals,
        private readonly CaseOperations $operations,
    ) {}

    private function row(int $case, int $term, int $proposal, int $report): object
    {
        return DB::table('repasse_external_transfer_reports')
            ->where('repasse_case_id', $case)
            ->where('term_id', $term)
            ->where('proposal_id', $proposal)
            ->where('id', $report)->firstOrFail();
    }

    private function proposal(int $case, int $term, int $proposal): array
    {
        // Verifica SHA e FKs da proposta congelada; nunca usa sua origem
        // hipotética como política financeira executável.
        return $this->proposals->show($case, $term, $proposal);
    }

    private function lines(array $proposal): array
    {
        return collect($proposal['snapshot']['lines'])->keyBy('party_id')->all();
    }

    private function item(object $row, array $proposal): array
    {
        $beneficiary = $this->lines($proposal)[(int) $row->beneficiary_party_id] ?? null;
        if (! $beneficiary
            || (int) $row->payer_party_id !== (int) $proposal['snapshot']['payer_party_id']
            || ! hash_equals($row->proposal_sha256, $proposal['snapshot_sha256'])) {
            abort(409, 'Registro externo com referência documental inconsistente.');
        }

        return [
            'id' => (int) $row->id,
            'case_id' => (int) $row->repasse_case_id,
            'term_id' => (int) $row->term_id,
            'proposal_id' => (int) $row->proposal_id,
            'payer_party_id' => (int) $row->payer_party_id,
            'payer_party_name' => $proposal['snapshot']['payer_party_name'],
            'beneficiary_party_id' => (int) $row->beneficiary_party_id,
            'beneficiary_party_name' => $beneficiary['party_name'] ?? ('Participante #'.$row->beneficiary_party_id),
            'proposed_reference_cents' => (int) $beneficiary['proposed_amount_cents'],
            'amount_cents' => (int) $row->amount_cents,
            'transferred_on' => (string) $row->transferred_on,
            'external_reference' => $row->external_reference,
            'operator_note' => $row->operator_note,
            'proof' => [
                'name' => $row->proof_name,
                'mime' => $row->proof_mime,
                'size_bytes' => (int) $row->proof_size_bytes,
                'sha256' => $row->proof_sha256,
            ],
            'reported_at' => $row->reported_at,
            'reported_by' => $row->reported_by === null ? null : (int) $row->reported_by,
            'voided_at' => $row->voided_at,
            'voided_by' => $row->voided_by === null ? null : (int) $row->voided_by,
            'void_reason' => $row->void_reason,
            'status' => $row->voided_at === null ? 'reported' : 'voided',
            'report_only' => true,
            'bank_confirmed' => false,
            'financial_authorization' => false,
            'payment_executed' => false,
            'obligation_created' => false,
        ];
    }

    private function filtered(int $case, int $term, int $proposal, string $status): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('repasse_external_transfer_reports')
            ->where('repasse_case_id', $case)
            ->where('term_id', $term)
            ->where('proposal_id', $proposal);
        if ($status === 'reported') {
            $query->whereNull('voided_at');
        } elseif ($status === 'voided') {
            $query->whereNotNull('voided_at');
        }

        return $query;
    }

    public function index(int $case, int $term, int $proposal, string $status = 'all'): array
    {
        $source = $this->proposal($case, $term, $proposal);
        $query = $this->filtered($case, $term, $proposal, $status);
        // Contadores usam toda a proposta, nunca apenas a página de 15.
        $aggregates = DB::table('repasse_external_transfer_reports')
            ->where('proposal_id', $proposal)
            ->selectRaw('beneficiary_party_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN voided_at IS NULL THEN amount_cents ELSE 0 END), 0) AS reported_cents')
            ->selectRaw('SUM(CASE WHEN voided_at IS NULL THEN 1 ELSE 0 END) AS reported_count')
            ->selectRaw('SUM(CASE WHEN voided_at IS NOT NULL THEN 1 ELSE 0 END) AS voided_count')
            ->groupBy('beneficiary_party_id')->get()->keyBy('beneficiary_party_id');
        $lines = [];
        $total = 0;
        $reportedCount = 0;
        $voidedCount = 0;
        foreach ($source['snapshot']['lines'] as $line) {
            $party = (int) $line['party_id'];
            $row = $aggregates[$party] ?? null;
            $reported = (int) ($row->reported_cents ?? 0);
            $count = (int) ($row->reported_count ?? 0);
            $voids = (int) ($row->voided_count ?? 0);
            $total += $reported;
            $reportedCount += $count;
            $voidedCount += $voids;
            $lines[] = [
                'beneficiary_party_id' => $party,
                'beneficiary_party_name' => $line['party_name'] ?? null,
                'reference_cents' => (int) $line['proposed_amount_cents'],
                'reported_cents' => $reported,
                // Comparativo hipotético; nunca significa saldo a pagar.
                'difference_to_reference_cents' => (int) $line['proposed_amount_cents'] - $reported,
                'reported_count' => $count,
                'voided_count' => $voids,
            ];
        }

        $rows = (clone $query)->orderByDesc('id')->paginate(15);
        $rows->through(fn (object $row): array => $this->item($row, $source));

        return [
            'summary' => [
                'proposal_id' => $proposal,
                'proposal_sha256' => $source['snapshot_sha256'],
                'proposal_source_stale' => $source['stale'],
                'hypothetical_reference_cents' => (int) $source['snapshot']['net_cents'],
                'total_reported_cents' => $total,
                'reported_count' => $reportedCount,
                'voided_count' => $voidedCount,
                'lines' => $lines,
                'bank_confirmed' => false,
                'payment_executed' => false,
                'obligation_created' => false,
            ],
            'reports' => $rows,
        ];
    }

    public function show(int $case, int $term, int $proposal, int $report): array
    {
        $source = $this->proposal($case, $term, $proposal);

        return $this->item($this->row($case, $term, $proposal, $report), $source);
    }

    private function safeFileName(string $value): string
    {
        $path = str_replace('\\', '/', $value);
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($path)));

        return mb_substr($clean !== '' ? $clean : 'comprovante', 0, 180);
    }

    private function proofExtension(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages([
                'proof' => 'Somente PDF, JPG, PNG ou WebP são permitidos.',
            ]),
        };
    }

    public function store(int $case, int $term, int $proposal, array $input, Request $request): array
    {
        $proof = $request->file('proof');
        if (! $proof instanceof UploadedFile || ! $proof->isValid()) {
            throw ValidationException::withMessages(['proof' => 'Anexe um comprovante válido.']);
        }
        $sha = hash_file('sha256', $proof->getRealPath());
        $extension = $this->proofExtension($proof);
        $actor = (int) $request->user('api')->id;
        $normalized = [
            'case_id' => $case, 'term_id' => $term, 'proposal_id' => $proposal,
            'expected_proposal_sha256' => $input['expected_proposal_sha256'],
            'beneficiary_party_id' => (int) $input['beneficiary_party_id'],
            'amount_cents' => (int) $input['amount_cents'],
            'transferred_on' => $input['transferred_on'],
            'external_reference' => trim((string) ($input['external_reference'] ?? '')) ?: null,
            'operator_note' => trim((string) ($input['operator_note'] ?? '')) ?: null,
            'confirmed_external' => true,
            'proof_sha256' => $sha,
        ];
        $intent = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $storedPath = null;

        try {
            // Sem retry automático após escrita de arquivo: em falha o catch
            // elimina a prova, e o cliente reenvia a MESMA UUID.
            $outcome = DB::transaction(function () use (
                $case, $term, $proposal, $input, $request, $proof,
                $extension, $sha, $actor, $normalized, $intent, &$storedPath
            ): array {
                $vehicle = DB::table('repasse_cases')->where('id', $case)
                    ->lockForUpdate()->first(['id', 'sold_on', 'sale_amount_cents']);
                if (! $vehicle) {
                    abort(404);
                }
                $prior = DB::table('repasse_external_transfer_reports')
                    ->where('request_key', $input['request_key'])->first();
                if ($prior) {
                    if ((int) $prior->repasse_case_id !== $case
                        || (int) $prior->term_id !== $term
                        || (int) $prior->proposal_id !== $proposal
                        || (int) $prior->reported_by !== $actor
                        || ! hash_equals($prior->intent_sha256, $intent)) {
                        abort(409, 'Chave idempotente já utilizada com outros dados.');
                    }

                    return ['id' => (int) $prior->id, 'created' => false];
                }

                if ($vehicle->sold_on === null || $vehicle->sale_amount_cents === null) {
                    throw ValidationException::withMessages([
                        'case' => 'Registre uma venda real e datada antes de informar repasse externo.',
                    ]);
                }
                $source = $this->proposal($case, $term, $proposal);
                if (! hash_equals($source['snapshot_sha256'], $normalized['expected_proposal_sha256'])) {
                    abort(409, 'A proposta histórica não coincide com a conferência. Atualize a ficha.');
                }
                $lines = $this->lines($source);
                if (! isset($lines[$normalized['beneficiary_party_id']])) {
                    throw ValidationException::withMessages([
                        'beneficiary_party_id' => 'Escolha um destinatário da proposta vinculada a este veículo.',
                    ]);
                }

                // Proposta pode tornar-se 'stale' por causa da venda/arquivamento.
                // Operador declara o fato externo conscientemente; NUNCA o inferimos.
                $storedPath = 'repasse-external-proofs/'.$case.'/'.Str::uuid().'.'.$extension;
                $handle = fopen($proof->getRealPath(), 'rb');
                try {
                    $stored = Storage::disk('local')->put($storedPath, $handle);
                } finally {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
                if (! $stored) {
                    throw ValidationException::withMessages([
                        'proof' => 'Não foi possível armazenar o comprovante privado.',
                    ]);
                }
                $id = DB::table('repasse_external_transfer_reports')->insertGetId([
                    'repasse_case_id' => $case,
                    'term_id' => $term,
                    'proposal_id' => $proposal,
                    'payer_party_id' => (int) $source['snapshot']['payer_party_id'],
                    'beneficiary_party_id' => $normalized['beneficiary_party_id'],
                    'request_key' => $input['request_key'],
                    'intent_sha256' => $intent,
                    'proposal_sha256' => $source['snapshot_sha256'],
                    'amount_cents' => $normalized['amount_cents'],
                    'transferred_on' => $normalized['transferred_on'],
                    'external_reference' => $normalized['external_reference'],
                    'operator_note' => $normalized['operator_note'],
                    'proof_name' => $this->safeFileName($proof->getClientOriginalName()),
                    'proof_path' => $storedPath,
                    'proof_mime' => $proof->getMimeType(),
                    'proof_size_bytes' => $proof->getSize(),
                    'proof_sha256' => $sha,
                    'reported_by' => $actor,
                    'reported_at' => now(),
                ]);
                $this->operations->event($case, $request, 'external_transfer_reported');

                return ['id' => $id, 'created' => true];
            });
        } catch (Throwable $error) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $error;
        }

        return [
            'data' => $this->show($case, $term, $proposal, $outcome['id']),
            'created' => $outcome['created'],
        ];
    }

    public function void(int $case, int $term, int $proposal, int $report, string $reason, Request $request): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Informe pelo menos cinco caracteres para justificar o estorno.',
            ]);
        }
        DB::transaction(function () use ($case, $term, $proposal, $report, $reason, $request): void {
            $vehicle = DB::table('repasse_cases')->where('id', $case)
                ->lockForUpdate()->first(['id']);
            if (! $vehicle) {
                abort(404);
            }
            $row = DB::table('repasse_external_transfer_reports')
                ->where('id', $report)->where('repasse_case_id', $case)
                ->where('term_id', $term)->where('proposal_id', $proposal)
                ->lockForUpdate()->firstOrFail();
            $this->proposal($case, $term, $proposal);
            if ($row->voided_at !== null) {
                if ($row->void_reason === $reason) {
                    return; // Retry sem novo evento ou exclusão de histórico.
                }
                abort(409, 'Registro já estornado com outra justificativa.');
            }
            DB::table('repasse_external_transfer_reports')->where('id', $report)->update([
                'voided_at' => now(),
                'voided_by' => $request->user('api')->id,
                'void_reason' => $reason,
            ]);
            $this->operations->event($case, $request, 'external_transfer_voided');
        }, 3);

        return $this->show($case, $term, $proposal, $report);
    }

    public function proof(int $case, int $term, int $proposal, int $report): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->show($case, $term, $proposal, $report);
        $row = $this->row($case, $term, $proposal, $report);
        $disk = Storage::disk('local');
        if (! $disk->exists($row->proof_path)) {
            abort(404, 'Comprovante privado não localizado.');
        }
        $path = $disk->path($row->proof_path);
        if (! is_file($path)
            || (int) filesize($path) !== (int) $row->proof_size_bytes
            || ! hash_equals($row->proof_sha256, hash_file('sha256', $path))) {
            abort(409, 'Comprovante com integridade divergente.');
        }
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', Str::ascii($row->proof_name));

        return response()->download($path, $name ?: 'comprovante', [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function csvSafe(?string $value): string
    {
        $text = trim(str_replace(["\r", "\n", "\t"], ' ', $value ?? ''));

        return preg_match('/^[=+\-@]/u', $text) ? "'".$text : $text;
    }

    public function export(int $case, int $term, int $proposal, string $status = 'all'): StreamedResponse
    {
        $source = $this->proposal($case, $term, $proposal);
        $names = $this->lines($source);
        $query = $this->filtered($case, $term, $proposal, $status);
        return response()->streamDownload(function () use ($query, $source, $names): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Registro ID', 'Proposta ID', 'Responsável declarado', 'Destinatário',
                'Valor informado (centavos)', 'Data declarada', 'Referência externa',
                'Observação', 'Situação', 'Motivo estorno', 'Comprovante SHA-256',
                'Data registro', 'Conferência bancária',
            ], ';');
            $query->orderBy('id')->chunkById(100, function ($rows) use ($out, $source, $names): void {
                foreach ($rows as $row) {
                    $beneficiary = $names[(int) $row->beneficiary_party_id] ?? null;
                    if (! $beneficiary || ! hash_equals($row->proposal_sha256, $source['snapshot_sha256'])) {
                        abort(409, 'Referência de relatório externo inconsistente.');
                    }
                    fputcsv($out, [
                        $row->id, $row->proposal_id,
                        $this->csvSafe($source['snapshot']['payer_party_name']),
                        $this->csvSafe($beneficiary['party_name'] ?? null),
                        $row->amount_cents, $row->transferred_on,
                        $this->csvSafe($row->external_reference),
                        $this->csvSafe($row->operator_note),
                        $row->voided_at ? 'ESTORNADO' : 'INFORMADO PELO OPERADOR',
                        $this->csvSafe($row->void_reason),
                        $row->proof_sha256, $row->reported_at,
                        'NÃO VERIFICADO PELO BANCO',
                    ], ';');
                }
            }, 'id');
            fclose($out);
        }, 'repasse-externo-proposta-'.$proposal.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
