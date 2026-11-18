<?php

namespace Tests\Unit;

use App\Services\RepasseManualCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class RepasseManualCalculatorTest extends TestCase
{
    public function test_rateio_percentual_arredonda_centavos_sem_float_e_fecha_base(): void
    {
        $lines = (new RepasseManualCalculator())->calculate(101, [
            ['beneficiary_party_id' => 1, 'kind' => 'repasse', 'mode' => 'percentage', 'basis_points' => 5000],
            ['beneficiary_party_id' => 2, 'kind' => 'commission', 'mode' => 'percentage', 'basis_points' => 5000],
        ]);
        $this->assertSame([51, 50], array_column($lines, 'amount_cents'));
        $this->assertSame(101, array_sum(array_column($lines, 'amount_cents')));
    }

    public function test_fixo_e_percentual_sao_explicitos_e_mistura_pode_fechar_base(): void
    {
        $lines = (new RepasseManualCalculator())->calculate(101, [
            ['beneficiary_party_id' => 1, 'kind' => 'repasse', 'mode' => 'fixed', 'amount_cents' => 50],
            ['beneficiary_party_id' => 2, 'kind' => 'commission', 'mode' => 'percentage', 'basis_points' => 5000],
        ]);
        $this->assertSame([50, 51], array_column($lines, 'amount_cents'));
    }

    public function test_valor_irrisorio_nao_gera_linha_zero(): void
    {
        $this->expectException(ValidationException::class);
        (new RepasseManualCalculator())->calculate(1, [
            ['beneficiary_party_id' => 1, 'kind' => 'repasse', 'mode' => 'percentage', 'basis_points' => 5000],
            ['beneficiary_party_id' => 2, 'kind' => 'repasse', 'mode' => 'percentage', 'basis_points' => 5000],
        ]);
    }

    public function test_nao_cria_saldo_residual_ou_tributos_percentuais_automaticos(): void
    {
        foreach ([
            [['beneficiary_party_id' => 1, 'kind' => 'repasse', 'mode' => 'fixed', 'amount_cents' => 50]],
            [['beneficiary_party_id' => 1, 'kind' => 'tax', 'mode' => 'percentage', 'basis_points' => 10000]],
        ] as $lines) {
            try {
                (new RepasseManualCalculator())->calculate(100, $lines);
                $this->fail('Deveria rejeitar regra não autorizada');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_percentuais_superiores_a_cem_por_cento_sao_rejeitados_mesmo_com_arredondamento(): void
    {
        $this->expectException(ValidationException::class);

        (new RepasseManualCalculator())->calculate(101, [
            ['beneficiary_party_id' => 1, 'kind' => 'repasse', 'mode' => 'percentage', 'basis_points' => 5001],
            ['beneficiary_party_id' => 2, 'kind' => 'commission', 'mode' => 'percentage', 'basis_points' => 5001],
        ]);
    }

}
