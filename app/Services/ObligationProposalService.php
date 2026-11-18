<?php

namespace App\Services;

use App\Support\TenantDB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dossiês de propostas documentais, sem obrigação exigível.
 * Nunca autoriza dinheiro, movimento bancário ou saldo.
 */
final class ObligationProposalService
{
    public function __construct(
        private readonly PreparationRecordService $records,
        private readonly FinancialPreparationService $preparation,
        private readonly CommercialTermsService $terms,
    ) {}

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    private function sha(array $value): string
    {
        return hash('sha256', $this->encode($this->canonical($value)));
    }

    private function row(int $case, int $term, int $proposal): object
    {
        return TenantDB::table('repasse_obligation_proposals')
            ->where('id', $proposal)->where('repasse_case_id', $case)
            ->where('term_id', $term)->firstOrFail();
    }

    private function snapshot(object $row): array
    {
        try {
            $value = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(409, 'Proposta interna com fotografia ilegível.');
        }
        if (! is_array($value) || ! hash_equals($row->snapshot_sha256, $this->sha($value))) {
            abort(409, 'Proposta interna com integridade divergente.');
        }

        // O conteúdo e suas referências estruturais precisam coincidir.
        // Detecta edições isoladas em FKs/fonte e evita expor dados incoerentes.
        if ((int) ($value['case_id'] ?? 0) !== (int) $row->repasse_case_id
            || (int) ($value['term_id'] ?? 0) !== (int) $row->term_id
            || (int) ($value['preparation_record_id'] ?? 0) !== (int) $row->preparation_record_id
            || (int) ($value['payer_party_id'] ?? 0) !== (int) $row->payer_party_id
            || ($value['source_snapshot_sha256'] ?? null) !== $row->source_snapshot_sha256
            || ($value['financial_policy_approved'] ?? null) !== false
            || ($value['financial_authorization'] ?? null) !== false
            || ($value['obligation_created'] ?? null) !== false
            || ($value['payment_enabled'] ?? null) !== false
            || ($value['executable'] ?? null) !== false) {
            abort(409, 'Referências ou bloqueios financeiros da proposta inconsistentes.');
        }

        return $value;
    }

    public function options(int $case, int $term): array
    {
        $this->preparation->show($case, $term);

        return [
            'parties' => TenantDB::table('repasse_case_parties as cp')
                ->join('parties as p', 'p.id', '=', 'cp.party_id')
                ->where('cp.repasse_case_id', $case)->where('p.is_active', true)
                ->orderBy('p.name')->get(['p.id', 'p.name'])
                ->map(static fn (object $p): array => [
                    'id' => (int) $p->id, 'name' => $p->name,
                ])->all(),
            'payment_enabled' => false,
        ];
    }

    public function index(int $case, int $term): mixed
    {
        $current = $this->preparation->show($case, $term);

        return TenantDB::table('repasse_obligation_proposals')
            ->where('repasse_case_id', $case)->where('term_id', $term)
            ->select('id', 'preparation_record_id', 'payer_party_id', 'created_by',
                'created_at', 'snapshot_sha256', 'source_snapshot_sha256')
            ->orderByDesc('id')->paginate(15)->through(
                static fn (object $r): array => [
                    'id' => (int) $r->id,
                    'preparation_record_id' => (int) $r->preparation_record_id,
                    'payer_party_id' => (int) $r->payer_party_id,
                    'created_by' => $r->created_by === null ? null : (int) $r->created_by,
                    'created_at' => $r->created_at,
                    'snapshot_sha256' => $r->snapshot_sha256,
                    'stale' => ! hash_equals($r->source_snapshot_sha256, $current['snapshot_sha256']),
                    'status' => 'proposal_only',
                    'obligation_created' => false,
                    'payment_enabled' => false,
                ]
            );
    }

    public function show(int $case, int $term, int $proposal): array
    {
        $row = $this->row($case, $term, $proposal);
        $current = $this->preparation->show($case, $term);
        $record = $this->records->show($case, $term, (int) $row->preparation_record_id);

        return [
            'id' => (int) $row->id,
            'case_id' => $case,
            'term_id' => $term,
            'preparation_record_id' => (int) $row->preparation_record_id,
            'created_at' => $row->created_at,
            'created_by' => $row->created_by === null ? null : (int) $row->created_by,
            'snapshot_sha256' => $row->snapshot_sha256,
            'stale' => $record['stale']
                || ! hash_equals($row->source_snapshot_sha256, $current['snapshot_sha256']),
            'snapshot' => $this->snapshot($row),
            'status' => 'proposal_only',
            'financial_authorization' => false,
            'obligation_created' => false,
            'payment_enabled' => false,
            'executable' => false,
        ];
    }

    public function store(int $case, int $term, array $input, Request $request): array
    {
        $actor = (int) $request->user('api')->id;
        $input = [
            'request_key' => $input['request_key'],
            'preparation_record_id' => (int) $input['preparation_record_id'],
            'expected_record_sha256' => $input['expected_record_sha256'],
            'payer_party_id' => (int) $input['payer_party_id'],
            'trigger_on' => $input['trigger_on'] ?? null,
            'due_on' => $input['due_on'] ?? null,
            'operator_note' => trim((string) ($input['operator_note'] ?? '')) ?: null,
        ];
        if ($input['trigger_on'] && $input['due_on'] && $input['due_on'] < $input['trigger_on']) {
            throw ValidationException::withMessages([
                'due_on' => 'A data pretendida não pode anteceder o evento informado.',
            ]);
        }
        $intent = $this->sha(['case_id' => $case, 'term_id' => $term, 'input' => $input]);

        return DB::transaction(function () use ($case, $term, $input, $actor, $intent): array {
            $vehicle = TenantDB::table('repasse_cases')->where('id', $case)
                ->lockForUpdate()->first(['id', 'status']);
            $termRow = TenantDB::table('repasse_commercial_terms')
                ->where('id', $term)->where('repasse_case_id', $case)
                ->lockForUpdate()->first(['id', 'archived_at']);
            if (! $vehicle || ! $termRow) {
                abort(404);
            }
            $previous = TenantDB::table('repasse_obligation_proposals')
                ->where('request_key', $input['request_key'])->first();
            if ($previous) {
                if ((int) $previous->repasse_case_id !== $case
                    || (int) $previous->term_id !== $term
                    || (int) $previous->created_by !== $actor
                    || ! hash_equals($previous->intent_sha256, $intent)) {
                    abort(409, 'Chave idempotente usada em outra proposta.');
                }

                return ['data' => $this->show($case, $term, (int) $previous->id), 'created' => false];
            }
            if ($vehicle->status !== 'draft' || $termRow->archived_at !== null) {
                throw ValidationException::withMessages([
                    'term' => 'Reabra o veículo e a minuta para propor obrigações.',
                ]);
            }
            $record = $this->records->show($case, $term, $input['preparation_record_id']);
            if (! hash_equals($record['snapshot_sha256'], $input['expected_record_sha256'])
                || $record['stale']) {
                abort(409, 'Apuração alterada ou desatualizada. Revise a fonte.');
            }
            $payer = TenantDB::table('repasse_case_parties as cp')
                ->join('parties as p', 'p.id', '=', 'cp.party_id')
                ->where('cp.repasse_case_id', $case)
                ->where('p.id', $input['payer_party_id'])->where('p.is_active', true)
                ->first(['p.id', 'p.name']);
            if (! $payer) {
                throw ValidationException::withMessages([
                    'payer_party_id' => 'Selecione um participante ativo deste veículo.',
                ]);
            }
            $commercial = $this->terms->show($case, $term);
            $current = $this->preparation->show($case, $term);
            $source = $record['snapshot'];
            $lines = array_map(static fn (array $allocation): array => [
                'party_id' => (int) $allocation['party_id'],
                'party_name' => $allocation['party_name'] ?? null,
                'basis_points' => (int) $allocation['basis_points'],
                'proposed_amount_cents' => (int) $allocation['amount_cents'],
                'state' => 'proposal_only',
                'payable' => false,
            ], $source['allocations']);
            if (array_sum(array_column($lines, 'proposed_amount_cents')) !== (int) $source['net_cents']) {
                abort(409, 'Rateio da fonte não fecha com o valor hipotético.');
            }
            // Todas as condições permanecem declarações do operador; nenhuma
            // delas se transforma automaticamente em política aprovada.
            $snapshot = [
                'case_id' => $case, 'term_id' => $term,
                'preparation_record_id' => $record['id'],
                'preparation_record_sha256' => $record['snapshot_sha256'],
                'source_snapshot_sha256' => $record['source_snapshot_sha256'],
                'payer_party_id' => (int) $payer->id,
                'payer_party_name' => $payer->name,
                'trigger_on' => $input['trigger_on'],
                'due_on' => $input['due_on'],
                'operator_note' => $input['operator_note'],
                'term_revision' => $current['term_revision'],
                'term_sha256' => $current['term_sha256'],
                'declared_conditions' => $commercial['conditions'],
                'review_state' => $current['review_state'],
                'documentary_issues' => $source['documentary_issues'],
                'reference_cents' => (int) $source['reference_cents'],
                'net_cents' => (int) $source['net_cents'],
                'lines' => $lines,
                'status' => 'proposal_only',
                'financial_policy_approved' => false,
                'financial_authorization' => false,
                'obligation_created' => false,
                'payment_enabled' => false,
                'executable' => false,
            ];
            $id = TenantDB::table('repasse_obligation_proposals')->insertGetId([
                'repasse_case_id' => $case,
                'term_id' => $term,
                'preparation_record_id' => $record['id'],
                'payer_party_id' => $payer->id,
                'request_key' => $input['request_key'],
                'intent_sha256' => $intent,
                'source_snapshot_sha256' => $current['snapshot_sha256'],
                'snapshot_sha256' => $this->sha($snapshot),
                'snapshot' => $this->encode($snapshot),
                'created_by' => $actor,
                'created_at' => now(),
            ]);

            return ['data' => $this->show($case, $term, $id), 'created' => true];
        }, 3);
    }
}
