<?php

namespace App\Services;

/**
 * Preserve verification of legacy snapshots stored in MySQL JSON columns.
 *
 * MySQL may normalize the ordering of JSON object keys. Older releases hashed
 * the serialized PHP array BEFORE inserting it, which can differ from the
 * object reserialized after MySQL returns it. This verifier accepts only a
 * byte-equivalent legacy ordering with the exact recorded digest: it does NOT
 * disable hash validation or bless altered values.
 *
 * New package manifests use LONGTEXT to avoid this ambiguity altogether.
 */
final class HistoricalHashCompatibility
{
    private const SCENARIO_KEYS = [
        'id', 'case_id', 'case_title', 'case_status',
        'client_reference', 'title', 'description', 'currency',
        'gross_amount_cents', 'source_party_id', 'source_name',
        'allocations', 'allocated_cents', 'difference_cents', 'balance_state',
        'revision', 'archived_at', 'forked_from_scenario_id',
        'forked_from_revision', 'created_at', 'updated_at',
        'executable', 'payment_enabled',
    ];

    private const ALLOCATION_KEYS = ['party_id', 'party_name', 'amount_cents', 'memo'];

    private static function ordered(array $input, array $keys): array
    {
        $output = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) $output[$key] = $input[$key];
        }
        foreach ($input as $key => $value) {
            if (! array_key_exists($key, $output)) $output[$key] = $value;
        }

        return $output;
    }

    private static function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function scenario(array $snapshot, string $expected): bool
    {
        if (hash_equals($expected, self::digest($snapshot))) return true;
        $legacy = self::ordered($snapshot, self::SCENARIO_KEYS);
        if (isset($legacy['allocations']) && is_array($legacy['allocations'])) {
            $legacy['allocations'] = array_map(
                static fn ($line): array => self::ordered($line, self::ALLOCATION_KEYS),
                $legacy['allocations']
            );
        }

        return hash_equals($expected, self::digest($legacy));
    }

    public static function reviewEvent(array $payload, string $expected): bool
    {
        if (hash_equals($expected, self::digest($payload))) return true;
        $ordered = self::ordered($payload, [
            'review_id', 'sequence', 'event_type', 'actor_user_id',
            'body', 'details', 'previous_hash',
        ]);
        $detailsKeys = match ($payload['event_type'] ?? '') {
            'requested' => ['scenario_revision', 'scenario_digest', 'assigned_to', 'due_on'],
            'reviewed', 'changes_requested' => ['scenario_revision', 'scenario_digest'],
            'assigned' => ['from_user_id', 'to_user_id'],
            'comment' => ['review_status'],
            default => [],
        };
        if (isset($ordered['details']) && is_array($ordered['details'])) {
            $ordered['details'] = self::ordered($ordered['details'], $detailsKeys);
        }

        return hash_equals($expected, self::digest($ordered));
    }
}
