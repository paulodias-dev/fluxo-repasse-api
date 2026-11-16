<?php

namespace Tests\Unit;

use App\Services\HistoricalHashCompatibility;
use PHPUnit\Framework\TestCase;

final class HistoricalHashCompatibilityTest extends TestCase
{
    public function test_scenario_verified_after_json_key_normalization_without_accepting_changes(): void
    {
        $original = [
            'id' => 7, 'case_id' => 2, 'case_title' => 'Dossiê', 'case_status' => 'draft',
            'client_reference' => '0b1eb42f-03b2-4acf-a088-f8e0509c99c0',
            'title' => 'Cenário antigo', 'description' => null, 'currency' => 'BRL',
            'gross_amount_cents' => 102,
            'source_party_id' => null, 'source_name' => null,
            'allocations' => [[
                'party_id' => 8, 'party_name' => 'Destinatário',
                'amount_cents' => 102, 'memo' => null,
            ]],
            'allocated_cents' => 102, 'difference_cents' => 0, 'balance_state' => 'missing_source',
            'revision' => 1, 'archived_at' => null,
            'forked_from_scenario_id' => null, 'forked_from_revision' => null,
            'created_at' => '2026-11-16 11:10:00', 'updated_at' => '2026-11-16 11:10:00',
            'executable' => false, 'payment_enabled' => false,
        ];
        $digest = hash('sha256', json_encode($original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $normalized = $original;
        ksort($normalized);
        ksort($normalized['allocations'][0]);
        $this->assertTrue(HistoricalHashCompatibility::scenario($normalized, $digest));

        $normalized['gross_amount_cents']++;
        $this->assertFalse(HistoricalHashCompatibility::scenario($normalized, $digest));
    }

    public function test_review_event_verified_after_json_key_normalization_without_accepting_changes(): void
    {
        $original = [
            'review_id' => 12, 'sequence' => 1, 'event_type' => 'requested',
            'actor_user_id' => 3, 'body' => 'Conferir a proposta',
            'details' => [
                'scenario_revision' => 1, 'scenario_digest' => str_repeat('a', 64),
                'assigned_to' => null, 'due_on' => '2026-12-20',
            ],
            'previous_hash' => null,
        ];
        $digest = hash('sha256', json_encode($original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $normalized = $original;
        ksort($normalized);
        ksort($normalized['details']);
        $this->assertTrue(HistoricalHashCompatibility::reviewEvent($normalized, $digest));
        $normalized['details']['scenario_revision'] = 2;
        $this->assertFalse(HistoricalHashCompatibility::reviewEvent($normalized, $digest));
    }
}
