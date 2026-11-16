<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DistributionTemplateService
{
    public function __construct(private readonly DistributionCalculator $calculator) {}

    public function normalize(array $input): array
    {
        $lines = array_map(static fn (array $line): array => [
            'party_id' => (int) $line['party_id'],
            'basis_points' => (int) $line['basis_points'],
            'memo' => trim($line['memo'] ?? '') ?: null,
        ], $input['lines']);
        usort($lines, static fn (array $a, array $b): int => $a['party_id'] <=> $b['party_id']);
        return [
            'name' => trim($input['name']),
            'description' => trim($input['description'] ?? '') ?: null,
            'source_party_id' => (int) $input['source_party_id'],
            'lines' => $lines,
        ];
    }

    public function digest(array $fields): string
    {
        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function ensureCaseEditable(int $caseId): void
    {
        $case = DB::table('repasse_cases')->where('id', $caseId)->lockForUpdate()->firstOrFail();
        if ($case->status !== 'draft') {
            throw ValidationException::withMessages(['case_id' => 'Reabra o dossiê para editar modelos.']);
        }
    }

    public function validateParties(int $caseId, array $fields): void
    {
        if (mb_strlen($fields['name']) < 2) {
            throw ValidationException::withMessages(['name' => 'Nome do modelo deve ter ao menos 2 caracteres.']);
        }

        $ids = DB::table('repasse_case_parties as cp')
            ->join('parties as p', 'p.id', '=', 'cp.party_id')
            ->where('cp.repasse_case_id', $caseId)->where('p.is_active', true)
            ->pluck('cp.party_id')->map(static fn ($id): int => (int) $id)->all();
        if (! in_array($fields['source_party_id'], $ids, true)) {
            throw ValidationException::withMessages(['source_party_id' => 'Origem precisa estar ativa e vinculada ao dossiê.']);
        }
        foreach ($fields['lines'] as $i => $line) {
            if (! in_array($line['party_id'], $ids, true)) {
                throw ValidationException::withMessages([
                    "lines.{$i}.party_id" => 'Destinatário deve estar ativo e vinculado ao dossiê.',
                ]);
            }
        }

        // Calculate a symbolic BRL 100.00 solely to check 10000 bps / uniqueness.
        // Every line must have a positive weight. No financial obligation is created.
        $this->calculator->calculate(10000, $fields['lines']);
    }

    public function create(int $caseId, array $input, Request $request, ?array $fork = null): array
    {
        $fields = $this->normalize($input);
        $id = DB::transaction(function () use ($caseId, $fields, $request, $fork): int {
            $this->ensureCaseEditable($caseId);
            $this->validateParties($caseId, $fields);
            $now = now();
            $id = DB::table('repasse_distribution_templates')->insertGetId([
                'repasse_case_id' => $caseId, 'name' => $fields['name'],
                'description' => $fields['description'],
                'source_party_id' => $fields['source_party_id'],
                'revision' => 1, 'content_digest' => $this->digest($fields),
                'forked_from_template_id' => $fork['template_id'] ?? null,
                'forked_from_revision' => $fork['revision'] ?? null,
                'created_by' => $request->user('api')->id,
                'updated_by' => $request->user('api')->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->saveLines($id, $fields['lines']);
            $this->record($id, $request, $fork ? 'template_forked' : 'template_created');
            return $id;
        }, 3);
        return $this->show($id);
    }

    public function update(int $id, int $revision, array $input, Request $request): array
    {
        $fields = $this->normalize($input);
        $changed = DB::transaction(function () use ($id, $revision, $fields, $request): bool {
            $row = $this->lock($id, $revision);
            $this->ensureCaseEditable((int) $row->repasse_case_id);
            if ($row->archived_at !== null) {
                throw ValidationException::withMessages(['template' => 'Reabra o modelo antes de editar.']);
            }
            $this->validateParties((int) $row->repasse_case_id, $fields);
            $hash = $this->digest($fields);
            if (hash_equals($row->content_digest, $hash)) return false;

            DB::table('repasse_distribution_templates')->where('id', $id)->update([
                'name' => $fields['name'], 'description' => $fields['description'],
                'source_party_id' => $fields['source_party_id'],
                'content_digest' => $hash, 'revision' => (int) $row->revision + 1,
                'updated_by' => $request->user('api')->id, 'updated_at' => now(),
            ]);
            DB::table('repasse_distribution_lines')->where('template_id', $id)->delete();
            $this->saveLines($id, $fields['lines']);
            $this->record($id, $request, 'template_revised');
            return true;
        }, 3);
        return ['data' => $this->show($id), 'changed' => $changed];
    }

    public function state(int $id, int $revision, string $action, Request $request): array
    {
        $changed = DB::transaction(function () use ($id, $revision, $action, $request): bool {
            $row = $this->lock($id, $revision);
            $this->ensureCaseEditable((int) $row->repasse_case_id);
            $archive = $action === 'archive';
            if (($row->archived_at !== null) === $archive) return false;
            DB::table('repasse_distribution_templates')->where('id', $id)->update([
                'archived_at' => $archive ? now() : null,
                'revision' => (int) $row->revision + 1,
                'updated_by' => $request->user('api')->id, 'updated_at' => now(),
            ]);
            $this->record($id, $request, $archive ? 'template_archived' : 'template_reopened');
            return true;
        }, 3);
        return ['data' => $this->show($id), 'changed' => $changed];
    }

    public function fork(int $id, int $revision, string $name, Request $request): array
    {
        $version = $this->version($id, $revision);
        $snapshot = $version['snapshot'];
        return $this->create((int) $snapshot['case_id'], [
            'name' => $name,
            'description' => $snapshot['description'],
            'source_party_id' => $snapshot['source_party_id'],
            'lines' => array_map(static fn (array $line): array => [
                'party_id' => $line['party_id'],
                'basis_points' => $line['basis_points'], 'memo' => $line['memo'],
            ], $snapshot['lines']),
        ], $request, ['template_id' => $id, 'revision' => $revision]);
    }

    public function list(array $filters): mixed
    {
        $query = DB::table('repasse_distribution_templates as t')
            ->leftJoin('parties as p', 'p.id', '=', 't.source_party_id')
            ->leftJoin('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->select('t.id', 't.repasse_case_id as case_id', 'c.title as case_title',
                't.name', 't.description', 't.revision', 't.archived_at',
                't.source_party_id', 'p.name as source_name', 't.created_at', 't.updated_at')
            ->orderByDesc('t.id');
        if (! empty($filters['case_id'])) $query->where('t.repasse_case_id', $filters['case_id']);
        if (($filters['status'] ?? 'all') === 'active') $query->whereNull('t.archived_at');
        if (($filters['status'] ?? 'all') === 'archived') $query->whereNotNull('t.archived_at');
        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['search']));
            $query->where('t.name', 'like', '%'.$term.'%');
        }
        return $query->paginate(15);
    }

    public function show(int $id): array
    {
        $row = DB::table('repasse_distribution_templates as t')
            ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->leftJoin('parties as p', 'p.id', '=', 't.source_party_id')
            ->where('t.id', $id)
            ->select('t.*', 'c.title as case_title', 'c.status as case_status',
                'p.name as source_name')->firstOrFail();
        $lines = DB::table('repasse_distribution_lines as line')
            ->join('parties as p', 'p.id', '=', 'line.party_id')
            ->where('line.template_id', $id)
            ->select('line.party_id', 'p.name as party_name', 'p.is_active',
                'line.basis_points', 'line.memo')->orderBy('line.party_id')->get()
            ->map(static fn ($line): array => [
                'party_id' => (int) $line->party_id,
                'party_name' => $line->party_name, 'is_active' => (bool) $line->is_active,
                'basis_points' => (int) $line->basis_points, 'memo' => $line->memo,
            ])->all();
        return [
            'id' => (int) $row->id, 'case_id' => (int) $row->repasse_case_id,
            'case_title' => $row->case_title, 'case_status' => $row->case_status,
            'name' => $row->name, 'description' => $row->description,
            'source_party_id' => (int) $row->source_party_id,
            'source_name' => $row->source_name, 'lines' => $lines,
            'percentage_total_bps' => array_sum(array_column($lines, 'basis_points')),
            'revision' => (int) $row->revision, 'archived_at' => $row->archived_at,
            'forked_from_template_id' => $row->forked_from_template_id,
            'forked_from_revision' => $row->forked_from_revision,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            'payment_enabled' => false,
        ];
    }

    public function preview(int $id, int $revision, int $grossCents): array
    {
        $record = $this->show($id);
        if ($record['revision'] !== $revision) abort(409, 'Modelo alterado. Atualize antes de calcular.');
        if ($record['archived_at'] !== null || $record['case_status'] !== 'draft') {
            throw ValidationException::withMessages(['template' => 'Somente modelo ativo em dossiê aberto pode ser aplicado.']);
        }
        $this->validateParties($record['case_id'], [
            'name' => $record['name'], 'source_party_id' => $record['source_party_id'],
            'lines' => $record['lines'],
        ]);
        $allocations = $this->calculator->calculate($grossCents, $record['lines']);
        $byParty = collect($record['lines'])->keyBy('party_id');
        foreach ($allocations as &$line) $line['party_name'] = $byParty[$line['party_id']]['party_name'];
        unset($line);

        return [
            'template_id' => $id, 'template_revision' => $revision,
            'case_id' => $record['case_id'], 'source_party_id' => $record['source_party_id'],
            'currency' => 'BRL', 'gross_amount_cents' => $grossCents,
            'allocated_cents' => array_sum(array_column($allocations, 'amount_cents')),
            'difference_cents' => $grossCents - array_sum(array_column($allocations, 'amount_cents')),
            'allocations' => $allocations, 'payment_enabled' => false,
            'executable' => false, 'rounding_method' => 'largest_remainder_party_id',
        ];
    }

    public function versions(int $id): mixed
    {
        $this->show($id);
        return DB::table('repasse_distribution_versions')->where('template_id', $id)
            ->select('revision', 'event_type', 'sha256', 'actor_user_id', 'created_at')
            ->orderByDesc('revision')->paginate(20);
    }

    public function version(int $id, int $revision): array
    {
        $this->show($id);
        $row = DB::table('repasse_distribution_versions')
            ->where('template_id', $id)->where('revision', $revision)->firstOrFail();
        $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (! hash_equals($row->sha256, hash('sha256', $serialized))) {
            abort(500, 'Integridade da revisão não confirmada.');
        }
        return [
            'revision' => (int) $row->revision, 'event_type' => $row->event_type,
            'sha256' => $row->sha256, 'actor_user_id' => $row->actor_user_id,
            'created_at' => $row->created_at, 'snapshot' => $snapshot,
        ];
    }

    private function lock(int $id, int $revision): object
    {
        $row = DB::table('repasse_distribution_templates')->where('id', $id)->lockForUpdate()->firstOrFail();
        if ((int) $row->revision !== $revision) abort(409, 'Versão desatualizada. Recarregue o modelo.');
        return $row;
    }

    private function saveLines(int $id, array $lines): void
    {
        foreach ($lines as $line) {
            DB::table('repasse_distribution_lines')->insert([
                'template_id' => $id, 'party_id' => $line['party_id'],
                'basis_points' => $line['basis_points'], 'memo' => $line['memo'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function record(int $id, Request $request, string $event): void
    {
        $data = $this->show($id);
        $serialized = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        DB::table('repasse_distribution_versions')->insert([
            'template_id' => $id, 'revision' => $data['revision'],
            'event_type' => $event, 'snapshot' => $serialized,
            'sha256' => hash('sha256', $serialized),
            'actor_user_id' => $request->user('api')->id, 'created_at' => now(),
        ]);
        DB::table('repasse_case_events')->insert([
            'repasse_case_id' => $data['case_id'],
            'actor_user_id' => $request->user('api')->id,
            'event_type' => $event, 'created_at' => now(),
        ]);
    }
}
