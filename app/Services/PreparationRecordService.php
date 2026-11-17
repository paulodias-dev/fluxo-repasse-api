<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fotografias imutáveis de APURAÇÃO HIPOTÉTICA. Não existe saldo, obrigação,
 * ordem de pagamento, quitação ou instrução bancária neste recurso.
 */
final class PreparationRecordService
{
    public function __construct(
        private readonly FinancialPreparationService $preparation,
        private readonly DistributionCalculator $calculator,
    ) {}

    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function digest(array $data): string
    {
        return hash('sha256', $this->encode($data));
    }

    private function row(int $case, int $term, int $record): object
    {
        return DB::table('repasse_preparation_records')
            ->where('id', $record)->where('repasse_case_id', $case)
            ->where('term_id', $term)->firstOrFail();
    }

    private function snapshot(object $row): array
    {
        $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($snapshot) || ! hash_equals($row->snapshot_sha256, $this->digest($snapshot))) {
            abort(409, 'A fotografia da apuração não passou pela verificação de integridade.');
        }
        return $snapshot;
    }

    public function show(int $case, int $term, int $record): array
    {
        $current = $this->preparation->show($case, $term);
        $row = $this->row($case, $term, $record);
        return [
            'id' => (int) $row->id,
            'case_id' => $case,
            'term_id' => $term,
            'created_at' => $row->created_at,
            'created_by' => $row->created_by === null ? null : (int) $row->created_by,
            'replaces_record_id' => $row->replaces_record_id === null
                ? null : (int) $row->replaces_record_id,
            'source_snapshot_sha256' => $row->source_snapshot_sha256,
            'snapshot_sha256' => $row->snapshot_sha256,
            'stale' => ! hash_equals($row->source_snapshot_sha256, $current['snapshot_sha256']),
            'snapshot' => $this->snapshot($row),
            'financial_authorization' => false,
            'obligation_created' => false,
            'payment_enabled' => false,
            'executable' => false,
        ];
    }

    public function index(int $case, int $term): mixed
    {
        $current = $this->preparation->show($case, $term);
        return DB::table('repasse_preparation_records')
            ->where('repasse_case_id', $case)->where('term_id', $term)
            ->select('id', 'created_at', 'created_by', 'replaces_record_id',
                'source_snapshot_sha256', 'snapshot_sha256', 'reference_cents',
                'additions_cents', 'deductions_cents', 'net_cents')
            ->orderByDesc('id')->paginate(15)->through(
                static function (object $row) use ($current): array {
                    return [
                        'id' => (int) $row->id,
                        'created_at' => $row->created_at,
                        'created_by' => $row->created_by === null ? null : (int) $row->created_by,
                        'replaces_record_id' => $row->replaces_record_id === null
                            ? null : (int) $row->replaces_record_id,
                        'reference_cents' => (int) $row->reference_cents,
                        'additions_cents' => (int) $row->additions_cents,
                        'deductions_cents' => (int) $row->deductions_cents,
                        'net_cents' => (int) $row->net_cents,
                        'snapshot_sha256' => $row->snapshot_sha256,
                        'stale' => ! hash_equals(
                            $row->source_snapshot_sha256, $current['snapshot_sha256']
                        ),
                        'payment_enabled' => false,
                    ];
                }
            );
    }

    public function store(int $case, int $term, array $input, Request $request): array
    {
        $adjustments = array_map(static fn (array $line): array => [
            'kind' => $line['kind'],
            'label' => trim($line['label']),
            'amount_cents' => (int) $line['amount_cents'],
        ], $input['adjustments']);

        // O digest é da INTENÇÃO do usuário, não dos dados atuais, para que
        // retry após mudança de minuta retorne a MESMA fotografia já salva.
        $intent = [
            'case_id' => $case, 'term_id' => $term,
            'expected_snapshot_sha256' => $input['expected_snapshot_sha256'],
            'adjustments' => $adjustments,
            'replaces_record_id' => $input['replaces_record_id'] ?? null,
        ];
        $digest = $this->digest($intent);
        $actor = (int) $request->user('api')->id;

        return DB::transaction(function () use ($case, $term, $input, $adjustments,
            $digest, $actor): array {
            // Lock do próprio veículo serializa requests para a mesma ficha,
            // enquanto UNIQUE request_key protege também a concorrência global.
            $vehicle = DB::table('repasse_cases')->where('id', $case)
                ->lockForUpdate()->first(['id', 'status']);
            $termRow = DB::table('repasse_commercial_terms')
                ->where('id', $term)->where('repasse_case_id', $case)
                ->lockForUpdate()->first(['id', 'archived_at']);
            if (! $vehicle || ! $termRow) {
                abort(404);
            }
            $prior = DB::table('repasse_preparation_records')
                ->where('request_key', $input['request_key'])->first();
            if ($prior) {
                if ((int) $prior->repasse_case_id !== $case
                    || (int) $prior->term_id !== $term
                    || (int) $prior->created_by !== $actor
                    || ! hash_equals($prior->payload_digest, $digest)) {
                    abort(409, 'Chave idempotente já usada em outra operação.');
                }
                return ['data' => $this->show($case, $term, (int) $prior->id), 'created' => false];
            }

            if ($vehicle->status !== 'draft' || $termRow->archived_at !== null) {
                throw ValidationException::withMessages([
                    'term' => 'Reabra o veículo e a minuta para registrar uma nova apuração.',
                ]);
            }
            $current = $this->preparation->show($case, $term);
            if (! hash_equals($current['snapshot_sha256'], $input['expected_snapshot_sha256'])) {
                abort(409, 'A minuta, o parecer ou os dados financeiros mudaram. Atualize a conferência.');
            }
            $reference = $current['manual_reference_cents'];
            if ($reference === null) {
                throw ValidationException::withMessages([
                    'reference_cents' => 'Informe a referência manual na minuta antes de apurar.',
                ]);
            }
            $replace = $input['replaces_record_id'] ?? null;
            if ($replace !== null) {
                $this->row($case, $term, (int) $replace);
            }

            $adds = 0;
            $deducts = 0;
            foreach ($adjustments as $line) {
                if ($line['kind'] === 'addition') {
                    $adds += $line['amount_cents'];
                } else {
                    $deducts += $line['amount_cents'];
                }
            }
            $net = $reference + $adds - $deducts;
            if ($net < 1 || $net > DistributionCalculator::MAX_CENTS) {
                throw ValidationException::withMessages([
                    'adjustments' => 'A apuração deve produzir valor hipotético positivo, dentro do limite permitido.',
                ]);
            }
            // Reutiliza o rateador determinístico de 10000 bps da etapa 19.
            $commercial = $current; // Fonte já verificada, NÃO política contratual.
            $termDetails = DB::table('repasse_commercial_terms')
                ->where('id', $term)->where('repasse_case_id', $case)->firstOrFail();
            $template = json_decode($termDetails->template_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (! hash_equals($termDetails->template_sha256, $this->digest($template))) {
                abort(409, 'Modelo congelado da minuta com hash inválido.');
            }
            $allocations = $this->calculator->calculate($net, $template['lines']);

            $snapshot = [
                'case_id' => $case, 'term_id' => $term,
                'term_revision' => $current['term_revision'],
                'term_sha256' => $current['term_sha256'],
                'template_revision' => $current['template_revision'],
                'template_sha256' => $current['template_sha256'],
                'source_snapshot_sha256' => $current['snapshot_sha256'],
                'reference_cents' => $reference,
                'adjustments' => $adjustments,
                'additions_cents' => $adds,
                'deductions_cents' => $deducts,
                'net_cents' => $net,
                'allocations' => $allocations,
                'allocated_cents' => array_sum(array_column($allocations, 'amount_cents')),
                'difference_cents' => $net - array_sum(array_column($allocations, 'amount_cents')),
                'documentary_preparation_complete' => $current['documentary_preparation_complete'],
                'documentary_issues' => $current['issues'],
                'review_state' => $current['review_state'],
                'financial_policy_approved' => false,
                'financial_authorization' => false,
                'obligation_created' => false,
                'payment_enabled' => false,
                'executable' => false,
            ];
            $id = DB::table('repasse_preparation_records')->insertGetId([
                'repasse_case_id' => $case,
                'term_id' => $term,
                'request_key' => $input['request_key'],
                'payload_digest' => $digest,
                'source_snapshot_sha256' => $current['snapshot_sha256'],
                'snapshot_sha256' => $this->digest($snapshot),
                'snapshot' => $this->encode($snapshot),
                'reference_cents' => $reference,
                'additions_cents' => $adds,
                'deductions_cents' => $deducts,
                'net_cents' => $net,
                'replaces_record_id' => $replace,
                'created_by' => $actor,
                'created_at' => now(),
            ]);
            return ['data' => $this->show($case, $term, $id), 'created' => true];
        }, 3);
    }
}
