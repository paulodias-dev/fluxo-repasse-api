<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DistributionBatchService
{
    public function __construct(
        private readonly DistributionCalculator $calculator,
        private readonly DistributionTemplateService $templates,
        private readonly ScenarioPlanner $scenarios
    ) {}

    private function normalizedRows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'client_reference' => strtolower($row['client_reference']),
            'title' => trim($row['title']),
            'gross_amount_cents' => (int) $row['gross_amount_cents'],
            'description' => trim($row['description'] ?? '') ?: null,
        ], $rows);
    }

    private function assertNoRepeats(array $rows): void
    {
        $seen = [];
        foreach ($rows as $i => $row) {
            if (mb_strlen($row['title']) < 2) {
                throw ValidationException::withMessages(["rows.{$i}.title" => 'Informe um título válido.']);
            }
            $ref = $row['client_reference'];
            if (isset($seen[$ref])) {
                throw ValidationException::withMessages(["rows.{$i}.client_reference" => 'Referência duplicada no lote.']);
            }
            $seen[$ref] = true;
        }
    }

    /**
     * Dry-run of the ENTIRE batch. All results are hypothetical; no database writes.
     */
    public function preview(int $templateId, int $revision, array $input): array
    {
        $rows = $this->normalizedRows($input);
        $this->assertNoRepeats($rows);
        $template = $this->templates->show($templateId);
        if ($template['revision'] !== $revision) abort(409, 'Modelo desatualizado.');
        $this->assertEditable($template);
        $this->templates->validateParties($template['case_id'], [
            'name' => $template['name'],
            'source_party_id' => $template['source_party_id'],
            'lines' => $template['lines'],
        ]);
        return $this->compute($template, $rows);
    }

    private function compute(array $template, array $rows): array
    {
        $computed = [];
        foreach ($rows as $i => $row) {
            try {
                $allocations = $this->calculator->calculate($row['gross_amount_cents'], $template['lines']);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    "rows.{$i}.gross_amount_cents" => 'Valor não pode ser dividido entre os destinatários deste modelo.',
                ]);
            }
            $names = collect($template['lines'])->keyBy('party_id');
            foreach ($allocations as &$allocation) {
                $allocation['party_name'] = $names[$allocation['party_id']]['party_name'];
            }
            unset($allocation);
            $computed[] = [
                'client_reference' => $row['client_reference'],
                'title' => $row['title'],
                'description' => $row['description'],
                'gross_amount_cents' => $row['gross_amount_cents'],
                'allocated_cents' => array_sum(array_column($allocations, 'amount_cents')),
                'difference_cents' => $row['gross_amount_cents'] - array_sum(array_column($allocations, 'amount_cents')),
                'allocations' => $allocations,
                'payment_enabled' => false,
                'executable' => false,
            ];
        }
        return [
            'template_id' => $template['id'],
            'template_revision' => $template['revision'],
            'case_id' => $template['case_id'],
            'source_party_id' => $template['source_party_id'],
            'rounding_method' => 'largest_remainder_party_id',
            'rows' => $computed,
            'count' => count($computed),
            'payment_enabled' => false,
        ];
    }

    private function assertEditable(array $template): void
    {
        if ($template['archived_at'] !== null || $template['case_status'] !== 'draft') {
            throw ValidationException::withMessages([
                'template' => 'Modelo deve estar ativo em dossiê aberto para gerar cenários.',
            ]);
        }
    }

    /**
     * Atomic batch creation. A replay with the same key and body returns the saved
     * result; a different body for the same key is always a conflict (HTTP 409).
     */
    public function apply(int $templateId, int $revision, array $input, Request $request): array
    {
        $rows = $this->normalizedRows($input['rows']);
        $this->assertNoRepeats($rows);
        $requestKey = strtolower($input['request_key']);
        $digest = hash('sha256', json_encode([
            'template_id' => $templateId, 'revision' => $revision, 'rows' => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($templateId, $revision, $rows, $requestKey, $digest, $request): array {
            $existing = DB::table('repasse_distribution_batches')
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($existing) return $this->replay($existing, $digest);

            $templateRow = DB::table('repasse_distribution_templates')
                ->where('id', $templateId)->lockForUpdate()->firstOrFail();
            if ((int) $templateRow->revision !== $revision) abort(409, 'Modelo alterado; confira a prévia novamente.');
            $template = $this->templates->show($templateId);
            $this->assertEditable($template);
            $this->templates->ensureCaseEditable($template['case_id']);
            $this->templates->validateParties($template['case_id'], [
                'name' => $template['name'],
                'source_party_id' => $template['source_party_id'], 'lines' => $template['lines'],
            ]);
            $preview = $this->compute($template, $rows);
            foreach ($rows as $i => $row) {
                if (DB::table('repasse_scenarios')->where('client_reference', $row['client_reference'])->exists()) {
                    throw ValidationException::withMessages([
                        "rows.{$i}.client_reference" => 'Esta referência já foi usada em outra operação.',
                    ]);
                }
            }

            // Insert the idempotency lock BEFORE all scenario writes.
            $inserted = DB::table('repasse_distribution_batches')->insertOrIgnore([
                'template_id' => $templateId,
                'template_revision' => $revision,
                'request_key' => $requestKey,
                'payload_digest' => $digest,
                'items_count' => count($rows),
                'template_snapshot' => json_encode($template, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'results' => json_encode([], JSON_THROW_ON_ERROR),
                'actor_user_id' => $request->user('api')->id,
                'created_at' => now(),
            ]);
            if ($inserted === 0) {
                $prior = DB::table('repasse_distribution_batches')
                    ->where('request_key', $requestKey)->lockForUpdate()->firstOrFail();
                return $this->replay($prior, $digest);
            }

            $saved = [];
            foreach ($preview['rows'] as $row) {
                $created = $this->scenarios->create([
                    'case_id' => $template['case_id'],
                    'client_reference' => $row['client_reference'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'source_party_id' => $template['source_party_id'],
                    'gross_amount_cents' => $row['gross_amount_cents'],
                    'allocations' => array_map(static fn (array $line): array => [
                        'party_id' => $line['party_id'],
                        'amount_cents' => $line['amount_cents'],
                        'memo' => $line['memo'],
                    ], $row['allocations']),
                ], $request);
                if (! $created['created']) abort(409, 'Referência já registrada; nenhum lote parcial foi salvo.');
                $saved[] = [
                    'scenario_id' => $created['data']['id'],
                    'client_reference' => $row['client_reference'],
                    'title' => $row['title'],
                    'gross_amount_cents' => $row['gross_amount_cents'],
                    'allocated_cents' => $row['allocated_cents'],
                    'difference_cents' => $row['difference_cents'],
                    'allocations' => $row['allocations'],
                ];
            }
            DB::table('repasse_distribution_batches')->where('request_key', $requestKey)
                ->update(['results' => json_encode($saved, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            DB::table('repasse_case_events')->insert([
                'repasse_case_id' => $template['case_id'],
                'actor_user_id' => $request->user('api')->id,
                'event_type' => 'distribution_batch_created',
                'created_at' => now(),
            ]);
            return [
                'created' => true,
                'batch' => $this->byKey($requestKey),
            ];
        }, 3);
    }

    private function replay(object $existing, string $digest): array
    {
        if (! hash_equals($existing->payload_digest, $digest)) {
            abort(409, 'Chave de lote reutilizada com conteúdo diferente.');
        }
        return ['created' => false, 'batch' => $this->toArray($existing)];
    }

    public function list(array $filters): mixed
    {
        $query = DB::table('repasse_distribution_batches as b')
            ->join('repasse_distribution_templates as t', 't.id', '=', 'b.template_id')
            ->select('b.id', 'b.template_id', 't.name as template_name',
                't.repasse_case_id as case_id', 'b.template_revision',
                'b.items_count', 'b.actor_user_id', 'b.created_at')
            ->orderByDesc('b.id');
        if (! empty($filters['template_id'])) $query->where('b.template_id', $filters['template_id']);
        if (! empty($filters['case_id'])) $query->where('t.repasse_case_id', $filters['case_id']);
        return $query->paginate(20);
    }

    public function show(int $id): array
    {
        return $this->toArray(DB::table('repasse_distribution_batches')->where('id', $id)->firstOrFail());
    }

    private function byKey(string $key): array
    {
        return $this->toArray(DB::table('repasse_distribution_batches')
            ->where('request_key', $key)->firstOrFail());
    }

    private function toArray(object $record): array
    {
        return [
            'id' => (int) $record->id,
            'template_id' => (int) $record->template_id,
            'template_revision' => (int) $record->template_revision,
            'request_key' => $record->request_key,
            'items_count' => (int) $record->items_count,
            'template_snapshot' => json_decode($record->template_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'results' => json_decode($record->results, true, 512, JSON_THROW_ON_ERROR),
            'actor_user_id' => $record->actor_user_id,
            'created_at' => $record->created_at,
            'payment_enabled' => false,
        ];
    }
}
