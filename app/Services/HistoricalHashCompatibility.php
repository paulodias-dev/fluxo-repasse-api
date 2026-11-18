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
    private const DISTRIBUTION_TEMPLATE_KEYS = [
        'id', 'case_id', 'case_title', 'case_status', 'name', 'description',
        'source_party_id', 'source_name', 'lines', 'percentage_total_bps',
        'revision', 'archived_at', 'forked_from_template_id',
        'forked_from_revision', 'created_at', 'updated_at', 'payment_enabled',
    ];

    private const DISTRIBUTION_LINE_KEYS = [
        'party_id', 'party_name', 'is_active', 'basis_points', 'memo',
    ];

    private const COMMERCIAL_TERM_KEYS = [
        'id', 'case_id', 'revision', 'archived_at', 'forked_from_term_id',
        'forked_from_revision', 'content',
    ];

    private const COMMERCIAL_CONTENT_KEYS = [
        'title', 'description', 'reference_cents', 'template_id',
        'template_revision', 'template_sha256', 'template_snapshot',
        'conditions', 'evidence',
    ];

    private const COMMERCIAL_CONDITION_KEYS = [
        'event_trigger', 'calculation_basis', 'fees_commissions',
        'taxes_withholdings', 'eligibility', 'authorization', 'settlement',
        'cancellation', 'banking', 'organization',
    ];

    private const COMMERCIAL_EVIDENCE_KEYS = [
        'id', 'original_name', 'sha256', 'mime', 'size_bytes',
    ];

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

    private static function canonical(array $input): array
    {
        if (! array_is_list($input)) {
            ksort($input, SORT_STRING);
        }
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = self::canonical($value);
            }
        }

        return $input;
    }

    private static function canonicalDigest(array $value): string
    {
        return self::digest(self::canonical($value));
    }

    public static function distributionTemplate(array $snapshot, string $expected): bool
    {
        if (hash_equals($expected, self::canonicalDigest($snapshot))) {
            return true;
        }

        $legacy = self::ordered($snapshot, self::DISTRIBUTION_TEMPLATE_KEYS);
        if (isset($legacy['lines']) && is_array($legacy['lines'])) {
            $legacy['lines'] = array_map(
                static fn (array $line): array => self::ordered($line, self::DISTRIBUTION_LINE_KEYS),
                $legacy['lines']
            );
        }

        return hash_equals($expected, self::digest($legacy));
    }

    public static function commercialFields(array $fields, string $expected): bool
    {
        if (hash_equals($expected, self::canonicalDigest($fields))) {
            return true;
        }

        $legacy = self::ordered($fields, self::COMMERCIAL_CONTENT_KEYS);
        if (isset($legacy['template_snapshot']) && is_array($legacy['template_snapshot'])) {
            $legacy['template_snapshot'] = self::distributionLegacy($legacy['template_snapshot']);
        }
        if (isset($legacy['conditions']) && is_array($legacy['conditions'])) {
            $legacy['conditions'] = self::ordered($legacy['conditions'], self::COMMERCIAL_CONDITION_KEYS);
        }
        if (isset($legacy['evidence']) && is_array($legacy['evidence'])) {
            $legacy['evidence'] = array_map(
                static fn (array $evidence): array => self::ordered($evidence, self::COMMERCIAL_EVIDENCE_KEYS),
                $legacy['evidence']
            );
        }

        return hash_equals($expected, self::digest($legacy));
    }

    public static function commercialTerm(array $snapshot, string $expected): bool
    {
        if (hash_equals($expected, self::canonicalDigest($snapshot))) {
            return true;
        }

        $legacy = self::ordered($snapshot, self::COMMERCIAL_TERM_KEYS);
        if (isset($legacy['content']) && is_array($legacy['content'])) {
            $legacy['content'] = self::ordered($legacy['content'], self::COMMERCIAL_CONTENT_KEYS);
            if (isset($legacy['content']['template_snapshot'])
                && is_array($legacy['content']['template_snapshot'])) {
                $legacy['content']['template_snapshot'] = self::distributionLegacy(
                    $legacy['content']['template_snapshot']
                );
            }
            if (isset($legacy['content']['conditions']) && is_array($legacy['content']['conditions'])) {
                $legacy['content']['conditions'] = self::ordered(
                    $legacy['content']['conditions'], self::COMMERCIAL_CONDITION_KEYS
                );
            }
            if (isset($legacy['content']['evidence']) && is_array($legacy['content']['evidence'])) {
                $legacy['content']['evidence'] = array_map(
                    static fn (array $evidence): array => self::ordered(
                        $evidence, self::COMMERCIAL_EVIDENCE_KEYS
                    ),
                    $legacy['content']['evidence']
                );
            }
        }

        return hash_equals($expected, self::digest($legacy));
    }

    private static function distributionLegacy(array $snapshot): array
    {
        $legacy = self::ordered($snapshot, self::DISTRIBUTION_TEMPLATE_KEYS);
        if (isset($legacy['lines']) && is_array($legacy['lines'])) {
            $legacy['lines'] = array_map(
                static fn (array $line): array => self::ordered($line, self::DISTRIBUTION_LINE_KEYS),
                $legacy['lines']
            );
        }

        return $legacy;
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
