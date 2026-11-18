<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class RepasseManualCalculator
{
    /**
     * Todas as porcentagens são explicitamente digitadas em basis points.
     * Percentuais incidem sobre a base MANUAL, sem inferir lucro/venda.
     * A sobra de arredondamento de até 1 centavo por linha percentual
     * é distribuída pelo maior resto e ordem estável.
     */
    public function calculate(int $base, array $lines): array
    {
        if ($base < 1 || $base > 999999999999 || count($lines) < 1 || count($lines) > 20) {
            throw ValidationException::withMessages(['lines' => 'Informe valor positivo e de 1 a 20 destinos.']);
        }

        $calculated = [];
        $remainders = [];
        $total = 0;
        foreach ($lines as $index => $line) {
            $mode = $line['mode'];
            $kind = $line['kind'];
            if (in_array($kind, ['tax', 'fee'], true) && $mode !== 'fixed') {
                throw ValidationException::withMessages(['lines' => 'Tributos e taxas devem ter valor manual fixo.']);
            }
            if ($mode === 'fixed') {
                if (! isset($line['amount_cents']) || isset($line['basis_points'])) {
                    throw ValidationException::withMessages(['lines' => 'Informe somente valor fixo nesta linha.']);
                }
                $amount = (int) $line['amount_cents'];
                if ($amount < 1) {
                    throw ValidationException::withMessages(['lines' => 'O valor fixo deve ser positivo.']);
                }
                $bps = null;
            } else {
                if (! isset($line['basis_points']) || isset($line['amount_cents'])) {
                    throw ValidationException::withMessages(['lines' => 'Informe somente percentual nesta linha.']);
                }
                $bps = (int) $line['basis_points'];
                if ($bps < 1 || $bps > 10000) {
                    throw ValidationException::withMessages(['lines' => 'Percentual fora de 0,01% a 100%.']);
                }
                $amount = intdiv($base * $bps, 10000);
                $remainders[$index] = ($base * $bps) % 10000;
            }
            $total += $amount;
            $calculated[] = [
                'beneficiary_party_id' => (int) $line['beneficiary_party_id'],
                'kind' => $kind, 'mode' => $mode,
                'basis_points' => $bps, 'amount_cents' => $amount,
            ];
        }

        $remaining = $base - $total;
        // A diferença aceitável não pode ser inventada para completar
        // uma base mal distribuída. Só distribuir centavos de arredondamento.
        $fractionTotal = array_sum($remainders);
        $maxRounding = intdiv($fractionTotal + 5000, 10000);
        if ($remaining < 0 || $remaining > $maxRounding || $remaining > count($remainders)) {
            throw ValidationException::withMessages([
                'lines' => 'A distribuição deve totalizar exatamente a base manual, sem excedente ou saldo implícito.',
            ]);
        }
        arsort($remainders, SORT_NUMERIC);
        foreach (array_keys($remainders) as $index) {
            if ($remaining === 0) break;
            $calculated[$index]['amount_cents']++;
            $remaining--;
        }
        // Uma linha de R$ 0,00 não deve virar obrigação financeira sem valor.
        foreach ($calculated as $line) {
            if ($line['amount_cents'] < 1) {
                throw ValidationException::withMessages([
                    'lines' => 'Cada destino precisa receber ao menos um centavo após arredondamento.',
                ]);
            }
        }
        if ($remaining !== 0 || array_sum(array_column($calculated, 'amount_cents')) !== $base) {
            throw ValidationException::withMessages(['lines' => 'Distribuição não fecha com a base informada.']);
        }

        return $calculated;
    }
}
