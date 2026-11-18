<?php

namespace App\Services;

use App\Support\TenantDB;

/**
 * Consolidação somente-leitura por veículo e tenant. Os registros declarativos
 * antigos nunca são abatidos das obrigações aprovadas: uma transferência pode
 * já estar declarada nos dois fluxos e não há identificador bancário verificável.
 */
final class RepasseFinancialOverview
{
    public function forVehicle(int $case): array
    {
        if (! TenantDB::table('repasse_cases')->where('id', $case)->exists()) {
            abort(404);
        }

        // Os totais são apurados sem joins 1:N entre linhas e parcelas.
        // Cada obrigação contribui uma vez só com sua base explicitamente informada.
        $settlements = TenantDB::table('repasse_settlements')
            ->where('repasse_case_id', $case)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'approved' THEN base_amount_cents ELSE 0 END), 0) AS approved_cents,
                COALESCE(SUM(CASE WHEN status = 'draft' THEN base_amount_cents ELSE 0 END), 0) AS draft_cents,
                COALESCE(SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END), 0) AS approved_count,
                COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END), 0) AS draft_count,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_count
            ")->first();

        $payments = TenantDB::table('repasse_settlement_payments as p')
            ->join('repasse_settlements as s', 's.id', '=', 'p.settlement_id')
            ->where('s.repasse_case_id', $case)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN s.status = 'approved' AND p.voided_at IS NULL
                    THEN p.amount_cents ELSE 0 END), 0) AS paid_cents,
                COALESCE(SUM(CASE WHEN s.status = 'approved' AND p.voided_at IS NULL
                    THEN 1 ELSE 0 END), 0) AS active_count,
                COALESCE(SUM(CASE WHEN p.voided_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS voided_count
            ")->first();

        $legacy = TenantDB::table('repasse_external_transfer_reports')
            ->where('repasse_case_id', $case)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN proposal_id IS NULL AND voided_at IS NULL
                    THEN amount_cents ELSE 0 END), 0) AS direct_cents,
                COALESCE(SUM(CASE WHEN proposal_id IS NOT NULL AND voided_at IS NULL
                    THEN amount_cents ELSE 0 END), 0) AS proposal_cents,
                COALESCE(SUM(CASE WHEN proposal_id IS NULL AND voided_at IS NULL
                    THEN 1 ELSE 0 END), 0) AS direct_count,
                COALESCE(SUM(CASE WHEN proposal_id IS NOT NULL AND voided_at IS NULL
                    THEN 1 ELSE 0 END), 0) AS proposal_count,
                COALESCE(SUM(CASE WHEN voided_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS voided_count
            ")->first();

        $approved = (int) $settlements->approved_cents;
        $paid = (int) $payments->paid_cents;
        $direct = (int) $legacy->direct_cents;
        $proposal = (int) $legacy->proposal_cents;
        $overlap = $approved > 0 && ($direct > 0 || $proposal > 0);

        return [
            'vehicle_id' => $case,
            'controlled' => [
                'approved_count' => (int) $settlements->approved_count,
                'approved_cents' => $approved,
                'draft_count' => (int) $settlements->draft_count,
                'draft_cents' => (int) $settlements->draft_cents,
                'cancelled_count' => (int) $settlements->cancelled_count,
                'payments_reported_count' => (int) $payments->active_count,
                'paid_cents' => $paid,
                'remaining_cents' => max(0, $approved - $paid),
                'voided_payments_count' => (int) $payments->voided_count,
            ],
            'external_reports' => [
                'direct_count' => (int) $legacy->direct_count,
                'direct_cents' => $direct,
                'proposal_count' => (int) $legacy->proposal_count,
                'proposal_cents' => $proposal,
                'voided_count' => (int) $legacy->voided_count,
            ],
            'flags' => [
                'possible_overlap' => $overlap,
                'overpaid_inconsistency' => $paid > $approved,
                'automatic_reconciliation' => false,
                'bank_confirmed' => false,
                'payment_executed_by_platform' => false,
                'sale_result_includes_repasses' => false,
            ],
        ];
    }
}
