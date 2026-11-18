<?php

namespace App\Services;

use App\Support\TenantTable;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScenarioPlanner
{
    public function normalize(array $data): array
    {
        $allocations = array_map(static fn (array $line): array => [
            'party_id' => (int) $line['party_id'],
            'amount_cents' => (int) $line['amount_cents'],
            'memo' => trim($line['memo'] ?? '') ?: null,
        ], $data['allocations']);
        usort($allocations, static fn (array $a, array $b): int => $a['party_id'] <=> $b['party_id']);

        return [
            'title' => trim($data['title']),
            'description' => trim($data['description'] ?? '') ?: null,
            'gross_amount_cents' => (int) $data['gross_amount_cents'],
            'source_party_id' => isset($data['source_party_id']) ? (int) $data['source_party_id'] : null,
            'allocations' => $allocations,
        ];
    }

    private function digest(int $caseId, array $fields): string
    {
        return hash('sha256', json_encode(['case_id' => $caseId, ...$fields], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function requireDraftCase(int $caseId): void
    {
        $case = TenantTable::table('repasse_cases')->where('id', $caseId)->lockForUpdate()->firstOrFail();
        if ($case->status !== 'draft') {
            throw ValidationException::withMessages(['case_id' => 'O dossiê precisa estar aberto para editar simulações.']);
        }
    }

    private function validateParties(int $caseId, array $data): void
    {
        $authorized = TenantTable::table('repasse_case_parties as cp')
            ->join('parties as p', 'p.id', '=', 'cp.party_id')
            ->where('cp.repasse_case_id', $caseId)->where('p.is_active', true)
            ->pluck('cp.party_id')->map(static fn ($id) => (int) $id)->all();
        if ($data['source_party_id'] !== null && ! in_array($data['source_party_id'], $authorized, true)) {
            throw ValidationException::withMessages(['source_party_id' => 'Selecione uma pessoa ou empresa ativa vinculada ao dossiê.']);
        }
        foreach ($data['allocations'] as $index => $line) {
            if (! in_array($line['party_id'], $authorized, true)) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.party_id" => 'Destinatário precisa estar ativo e vinculado ao dossiê.',
                ]);
            }
        }
        if (mb_strlen($data['title']) < 2) {
            throw ValidationException::withMessages(['title' => 'Informe um título válido.']);
        }
    }

    public function create(array $input, Request $request, ?array $fork = null): array
    {
        $caseId = (int) $input['case_id'];
        $data = $this->normalize($input);
        $digest = $this->digest($caseId, $data);
        $reference = $input['client_reference'];

        $result = DB::transaction(function () use ($caseId, $data, $digest, $reference, $request, $fork): array {
            $existing = TenantTable::table('repasse_scenarios')->where('client_reference', $reference)->first();
            if ($existing) {
                if (! hash_equals($existing->initial_digest, $digest)) abort(409, 'Referência já utilizada com outros dados.');
                return ['id' => (int) $existing->id, 'created' => false];
            }
            $this->requireDraftCase($caseId);
            $this->validateParties($caseId, $data);
            $now = now();
            $inserted = TenantTable::table('repasse_scenarios')->insertOrIgnore([
                'repasse_case_id' => $caseId, 'client_reference' => $reference,
                'initial_digest' => $digest, 'current_digest' => $digest,
                'title' => $data['title'], 'description' => $data['description'],
                'currency' => 'BRL', 'gross_amount_cents' => $data['gross_amount_cents'],
                'source_party_id' => $data['source_party_id'], 'revision' => 1,
                'forked_from_scenario_id' => $fork['scenario_id'] ?? null,
                'forked_from_revision' => $fork['revision'] ?? null,
                'created_by' => $request->user('api')->id,
                'updated_by' => $request->user('api')->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $row = TenantTable::table('repasse_scenarios')->where('client_reference', $reference)->firstOrFail();
            if (! hash_equals($row->initial_digest, $digest)) abort(409, 'Referência já utilizada com outros dados.');
            if ($inserted === 0) return ['id' => (int) $row->id, 'created' => false];

            $this->saveLines((int) $row->id, $data['allocations']);
            $this->version((int) $row->id, $request, $fork ? 'scenario_forked' : 'scenario_created');
            return ['id' => (int) $row->id, 'created' => true];
        }, 3);

        return ['data' => $this->show($result['id']), 'created' => $result['created']];
    }

    public function replace(int $id, array $input, Request $request): array
    {
        $data = $this->normalize($input);
        $changed = DB::transaction(function () use ($id, $input, $data, $request): bool {
            $row = TenantTable::table('repasse_scenarios')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ((int) $row->revision !== (int) $input['revision']) abort(409, 'Revisão desatualizada. Recarregue antes de salvar.');
            $this->requireDraftCase((int) $row->repasse_case_id);
            if ($row->archived_at !== null) {
                throw ValidationException::withMessages(['scenario' => 'Reabra o cenário para editar.']);
            }
            $this->validateParties((int) $row->repasse_case_id, $data);
            $digest = $this->digest((int) $row->repasse_case_id, $data);
            if (hash_equals($row->current_digest, $digest)) return false;
            TenantTable::table('repasse_scenarios')->where('id', $id)->update([
                'title' => $data['title'], 'description' => $data['description'],
                'gross_amount_cents' => $data['gross_amount_cents'],
                'source_party_id' => $data['source_party_id'],
                'current_digest' => $digest, 'revision' => (int) $row->revision + 1,
                'updated_by' => $request->user('api')->id, 'updated_at' => now(),
            ]);
            TenantTable::table('repasse_scenario_allocations')->where('repasse_scenario_id', $id)->delete();
            $this->saveLines($id, $data['allocations']);
            $this->version($id, $request, 'scenario_revised');
            return true;
        }, 3);
        return ['data' => $this->show($id), 'changed' => $changed];
    }

    public function changeState(int $id, int $revision, string $action, Request $request): array
    {
        $changed = DB::transaction(function () use ($id, $revision, $action, $request): bool {
            $row = TenantTable::table('repasse_scenarios')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ((int) $row->revision !== $revision) abort(409, 'Revisão desatualizada.');
            $this->requireDraftCase((int) $row->repasse_case_id);
            $archiving = $action === 'archive';
            if (($row->archived_at !== null) === $archiving) return false;
            TenantTable::table('repasse_scenarios')->where('id', $id)->update([
                'archived_at' => $archiving ? now() : null,
                'revision' => (int) $row->revision + 1,
                'updated_by' => $request->user('api')->id, 'updated_at' => now(),
            ]);
            $this->version($id, $request, $archiving ? 'scenario_archived' : 'scenario_reopened');
            return true;
        }, 3);
        return ['data' => $this->show($id), 'changed' => $changed];
    }

    public function fork(int $id, int $revision, array $input, Request $request): array
    {
        $prior = $this->versionDetail($id, $revision);
        $snapshot = $prior['snapshot'];
        $payload = [
            'case_id' => $snapshot['case_id'],
            'client_reference' => $input['client_reference'],
            'title' => trim($input['title'] ?? $snapshot['title']),
            'description' => $snapshot['description'],
            'source_party_id' => $snapshot['source_party_id'],
            'gross_amount_cents' => $snapshot['gross_amount_cents'],
            'allocations' => array_map(static fn (array $a): array => [
                'party_id' => $a['party_id'], 'amount_cents' => $a['amount_cents'], 'memo' => $a['memo'],
            ], $snapshot['allocations']),
        ];
        return $this->create($payload, $request, ['scenario_id' => $id, 'revision' => $revision]);
    }

    private function saveLines(int $scenarioId, array $allocations): void
    {
        $now = now();
        foreach ($allocations as $line) {
            TenantTable::table('repasse_scenario_allocations')->insert([
                'repasse_scenario_id' => $scenarioId, 'party_id' => $line['party_id'],
                'amount_cents' => $line['amount_cents'], 'memo' => $line['memo'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function version(int $scenarioId, Request $request, string $event): void
    {
        $snapshot = $this->show($scenarioId);
        $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        TenantTable::table('repasse_scenario_versions')->insert([
            'repasse_scenario_id' => $scenarioId, 'revision' => $snapshot['revision'],
            'event_type' => $event, 'snapshot' => $serialized,
            'sha256' => hash('sha256', $serialized),
            'actor_user_id' => $request->user('api')->id, 'created_at' => now(),
        ]);
        TenantTable::table('repasse_case_events')->insert([
            'repasse_case_id' => $snapshot['case_id'],
            'actor_user_id' => $request->user('api')->id,
            'event_type' => $event, 'created_at' => now(),
        ]);
    }

    public function show(int $id): array
    {
        $row = TenantTable::table('repasse_scenarios as s')
            ->leftJoin('parties as source', 'source.id', '=', 's.source_party_id')
            ->join('repasse_cases as c', 'c.id', '=', 's.repasse_case_id')
            ->where('s.id', $id)->select('s.*', 'source.name as source_name',
                'c.title as case_title', 'c.status as case_status')->firstOrFail();
        $allocations = TenantTable::table('repasse_scenario_allocations as a')
            ->join('parties as p', 'p.id', '=', 'a.party_id')
            ->where('a.repasse_scenario_id', $id)
            ->select('a.party_id', 'p.name as party_name', 'a.amount_cents', 'a.memo')
            ->orderBy('a.party_id')->get()->map(static fn ($line): array => [
                'party_id' => (int) $line->party_id,
                'party_name' => $line->party_name,
                'amount_cents' => (int) $line->amount_cents, 'memo' => $line->memo,
            ])->all();
        $total = array_sum(array_column($allocations, 'amount_cents'));
        $gross = (int) $row->gross_amount_cents;
        $state = $row->source_party_id === null ? 'missing_source'
            : (count($allocations) === 0 ? 'missing_allocations'
                : ($total < $gross ? 'underallocated' : ($total > $gross ? 'overallocated' : 'balanced')));
        return [
            'id' => (int) $row->id, 'case_id' => (int) $row->repasse_case_id,
            'case_title' => $row->case_title, 'case_status' => $row->case_status,
            'client_reference' => $row->client_reference,
            'title' => $row->title, 'description' => $row->description,
            'currency' => 'BRL', 'gross_amount_cents' => $gross,
            'source_party_id' => $row->source_party_id === null ? null : (int) $row->source_party_id,
            'source_name' => $row->source_name,
            'allocations' => $allocations, 'allocated_cents' => $total,
            'difference_cents' => $gross - $total, 'balance_state' => $state,
            'revision' => (int) $row->revision,
            'archived_at' => $row->archived_at,
            'forked_from_scenario_id' => $row->forked_from_scenario_id,
            'forked_from_revision' => $row->forked_from_revision,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            'executable' => false, 'payment_enabled' => false,
        ];
    }

    public function list(array $filters): mixed
    {
        $query = TenantTable::table('repasse_scenarios')->select('id')->orderByDesc('id');
        if (! empty($filters['case_id'])) $query->where('repasse_case_id', $filters['case_id']);
        if (($filters['status'] ?? 'all') === 'active') $query->whereNull('archived_at');
        if (($filters['status'] ?? 'all') === 'archived') $query->whereNotNull('archived_at');
        if (trim($filters['search'] ?? '') !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['search']));
            $query->where('title', 'like', '%'.$escaped.'%');
        }
        $page = $query->paginate(15);
        $page->through(fn ($row): array => $this->show((int) $row->id));
        return $page;
    }

    public function summary(): array
    {
        $counts = TenantTable::table('repasse_scenarios')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN archived_at IS NULL THEN 1 ELSE 0 END) as active')
            ->selectRaw('SUM(CASE WHEN archived_at IS NOT NULL THEN 1 ELSE 0 END) as archived')
            ->first();
        // Agregação SQL em centavos: evita N+1 e nunca soma versões históricas.
        $allocations = TenantTable::table('repasse_scenario_allocations')
            ->select('repasse_scenario_id')
            ->selectRaw('SUM(amount_cents) as allocated')
            ->selectRaw('COUNT(*) as lines')
            ->groupBy('repasse_scenario_id');
        $balance = TenantTable::table('repasse_scenarios as s')
            ->leftJoinSub($allocations, 'a', 'a.repasse_scenario_id', '=', 's.id')
            ->whereNull('s.archived_at')
            ->selectRaw('SUM(CASE WHEN s.source_party_id IS NOT NULL AND COALESCE(a.lines,0) > 0 AND COALESCE(a.allocated,0) = s.gross_amount_cents THEN 1 ELSE 0 END) as balanced')
            ->first();
        $balanced = (int) ($balance->balanced ?? 0);
        $difference = (int) $counts->active - $balanced;
        return [
            'total' => (int) $counts->total, 'active' => (int) $counts->active,
            'archived' => (int) $counts->archived, 'balanced_drafts' => $balanced,
            'incomplete_drafts' => $difference, 'payment_enabled' => false,
        ];
    }

    public function versions(int $id): mixed
    {
        $this->show($id);
        return TenantTable::table('repasse_scenario_versions')->where('repasse_scenario_id', $id)
            ->select('revision', 'event_type', 'sha256', 'actor_user_id', 'created_at')
            ->orderByDesc('revision')->paginate(20);
    }

    public function versionDetail(int $id, int $revision): array
    {
        $this->show($id);
        $row = TenantTable::table('repasse_scenario_versions')
            ->where('repasse_scenario_id', $id)->where('revision', $revision)->firstOrFail();
        $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! HistoricalHashCompatibility::scenario($snapshot, $row->sha256)) {
            abort(500, 'Integridade histórica não confirmada.');
        }
        return [
            'revision' => (int) $row->revision, 'event_type' => $row->event_type,
            'sha256' => $row->sha256, 'actor_user_id' => $row->actor_user_id,
            'created_at' => $row->created_at, 'snapshot' => $snapshot,
        ];
    }
}
