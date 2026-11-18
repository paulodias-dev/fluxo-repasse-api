<?php

namespace App\Services;

use App\Support\TenantDB;

use Illuminate\Support\Facades\DB;

/**
 * Pré-repasse documental, SOMENTE LEITURA. Não cria obrigação, ledger,
 * autorização monetária, instrução bancária ou repasse.
 */
final class FinancialPreparationService
{
    public function __construct(private readonly CommercialTermsService $terms) {}

    public function show(int $case, int $term): array
    {
        // show() já verifica SHA do conteúdo, modelo, evidências e vínculos;
        // qualquer inconsistência no snapshot é um 409, nunca dado presumido.
        $commercial = $this->terms->show($case, $term);
        $preview = $this->terms->preview($case, $term);
        $vehicle = TenantDB::table('repasse_cases')->where('id', $case)->first([
            'id', 'title', 'status', 'sold_on', 'purchase_amount_cents', 'sale_amount_cents',
        ]);
        if (! $vehicle) {
            abort(404);
        }

        $finance = TenantDB::table('repasse_case_financial_entries')
            ->where('repasse_case_id', $case)->whereNull('voided_at')
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'expense' AND status = 'realized'
                THEN amount_cents ELSE 0 END), 0) AS realized_expense")
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'income' AND status = 'realized'
                THEN amount_cents ELSE 0 END), 0) AS realized_income")
            ->first();
        $purchase = $vehicle->purchase_amount_cents === null
            ? null : (int) $vehicle->purchase_amount_cents;
        $sale = $vehicle->sale_amount_cents === null
            ? null : (int) $vehicle->sale_amount_cents;
        $actualSaleResult = $vehicle->sold_on !== null && $purchase !== null && $sale !== null
            ? $sale + (int) $finance->realized_income - $purchase - (int) $finance->realized_expense
            : null;

        // Parecer da MESMA revisão e mesmo SHA. Um parecer antigo não serve.
        // Status reviewed confere documentos, NÃO autoriza movimentar dinheiro.
        $review = TenantDB::table('repasse_commercial_reviews')
            ->where('term_id', $term)
            ->where('term_revision', $commercial['revision'])
            ->where('term_sha256', $commercial['content_sha256'])
            ->orderByDesc('id')
            ->first(['id', 'revision', 'status', 'term_revision', 'term_sha256',
                'decided_at', 'updated_at']);
        $reviewState = $review?->status ?? 'not_requested';

        $issues = [];
        foreach ($commercial['checks']['missing_conditions'] as $key) {
            $issues[] = ['key' => 'condition:'.$key, 'scope' => 'commercial',
                'message' => 'Condição comercial ainda não declarada: '.(CommercialTermsService::FIELDS[$key] ?? $key)];
        }
        if ($commercial['archived_at'] !== null) {
            $issues[] = ['key' => 'archived_term', 'scope' => 'commercial',
                'message' => 'A minuta está arquivada'];
        }
        if ($commercial['checks']['model_changed_or_archived']) {
            $issues[] = ['key' => 'stale_model', 'scope' => 'distribution',
                'message' => 'Modelo de distribuição alterado ou arquivado'];
        }
        if ($commercial['checks']['missing_active_parties'] !== []) {
            $issues[] = ['key' => 'inactive_parties', 'scope' => 'distribution',
                'message' => 'Existem participantes não vinculados ou inativos'];
        }
        if ($commercial['checks']['archived_evidence'] !== []) {
            $issues[] = ['key' => 'archived_evidence', 'scope' => 'documentary',
                'message' => 'Uma ou mais evidências da minuta foram arquivadas'];
        }
        if ($commercial['checks']['case_archived']) {
            $issues[] = ['key' => 'archived_vehicle', 'scope' => 'vehicle',
                'message' => 'Veículo arquivado; revisar antes de continuar'];
        }
        if ($commercial['reference_cents'] === null) {
            $issues[] = ['key' => 'missing_reference', 'scope' => 'distribution',
                'message' => 'Valor de referência manual não informado'];
        }
        if ($reviewState !== 'reviewed') {
            $issues[] = ['key' => 'missing_current_review', 'scope' => 'review',
                'message' => 'A revisão documental atual não tem parecer reviewed'];
        }

        $documentaryReady = $issues === [];
        // Não presumir que "completo" implica permissão financeira.
        $payload = [
            'case_id' => $case,
            'case_title' => $vehicle->title,
            'case_status' => $vehicle->status,
            'term_id' => $term,
            'term_title' => $commercial['title'],
            'term_revision' => $commercial['revision'],
            'term_sha256' => $commercial['content_sha256'],
            'template_id' => $commercial['template_id'],
            'template_revision' => $commercial['template_revision'],
            'template_sha256' => $commercial['template_sha256'],
            'source_party_id' => $preview['source_party_id']
                ?? $commercial['template_snapshot']['source_party_id'],
            'manual_reference_cents' => $commercial['reference_cents'],
            'sale_result_known_cents' => $actualSaleResult,
            'sold_on' => $vehicle->sold_on,
            'allocations' => $preview['allocations'],
            'allocated_cents' => $preview['allocated_cents'] ?? null,
            'difference_cents' => $preview['difference_cents'],
            'review' => $review === null ? null : [
                'id' => (int) $review->id,
                'revision' => (int) $review->revision,
                'status' => $review->status,
                'decided_at' => $review->decided_at,
            ],
            'review_state' => $reviewState,
            'documentary_preparation_complete' => $documentaryReady,
            'issues' => $issues,
            'financial_policy_approved' => false,
            'financial_authorization' => false,
            'obligation_created' => false,
            'payment_enabled' => false,
            'executable' => false,
            'missing_execution_decisions' => [
                'Política contratual aprovada para fato gerador, base, taxas e tributos',
                'Titularidade, alçadas financeiras e regras de liquidação aprovadas',
                'Integração bancária/PSP, conciliação, estornos e isolamento organizacional aprovados',
            ],
        ];
        // SHA da fotografia de leitura, não assinatura digital ou trilha WORM.
        // O snapshot contém dados já conhecidos, nenhuma informação bancária.
        $payload['snapshot_sha256'] = hash(
            'sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return $payload;
    }
}
