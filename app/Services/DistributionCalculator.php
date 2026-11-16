<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class DistributionCalculator
{
    public const TOTAL_BASIS_POINTS = 10000;
    public const MAX_CENTS = 1000000000000;

    /**
     * The operator explicitly supplies every percentage in integer basis points.
     * Largest-remainder reconciliation is deterministic (ties by party ID).
     * No default commission, withholding or payment instruction is ever inferred.
     */
    public function calculate(int $grossCents, array $lines): array
    {
        if ($grossCents < 1 || $grossCents > self::MAX_CENTS) {
            throw ValidationException::withMessages(['gross_amount_cents' => 'Valor fora do limite da simulação.']);
        }
        if (count($lines) < 1 || count($lines) > 50) {
            throw ValidationException::withMessages(['lines' => 'Informe entre 1 e 50 destinatários.']);
        }

        $seen = [];
        $sum = 0;
        $result = [];
        foreach ($lines as $index => $line) {
            $partyId = (int) $line['party_id'];
            $bps = (int) $line['basis_points'];
            if ($partyId <= 0 || isset($seen[$partyId]) || $bps < 1 || $bps > self::TOTAL_BASIS_POINTS) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => 'Destinatário repetido ou percentual inválido.',
                ]);
            }
            $seen[$partyId] = true;
            $sum += $bps;
            // MAX_CENTS * TOTAL_BASIS_POINTS <= 10^16, safely below signed PHP 64-bit.
            $numerator = $grossCents * $bps;
            $result[] = [
                'party_id' => $partyId,
                'basis_points' => $bps,
                'amount_cents' => intdiv($numerator, self::TOTAL_BASIS_POINTS),
                'remainder' => $numerator % self::TOTAL_BASIS_POINTS,
                'memo' => $line['memo'] ?? null,
            ];
        }
        if ($sum !== self::TOTAL_BASIS_POINTS) {
            throw ValidationException::withMessages([
                'lines' => 'Os percentuais devem totalizar exatamente 100,00%.',
            ]);
        }

        $leftover = $grossCents - array_sum(array_column($result, 'amount_cents'));
        usort($result, static fn (array $a, array $b): int =>
            ($b['remainder'] <=> $a['remainder']) ?: ($a['party_id'] <=> $b['party_id']));
        for ($i = 0; $i < $leftover; $i++) {
            $result[$i]['amount_cents']++;
        }

        foreach ($result as $line) {
            if ($line['amount_cents'] < 1) {
                throw ValidationException::withMessages([
                    'gross_amount_cents' => 'Valor insuficiente para que todos os destinatários recebam ao menos um centavo na simulação.',
                ]);
            }
        }
        usort($result, static fn (array $a, array $b): int => $a['party_id'] <=> $b['party_id']);

        return array_map(static fn (array $line): array => [
            'party_id' => $line['party_id'],
            'basis_points' => $line['basis_points'],
            'amount_cents' => $line['amount_cents'],
            'memo' => $line['memo'],
        ], $result);
    }
}
