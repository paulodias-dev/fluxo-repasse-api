<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommercialTermsService
{
    /**
     * These are questions for the operator, NEVER a default commercial policy.
     * Filling every field is documentary completeness, NOT legal or banking approval.
     */
    public const FIELDS = [
        'event_trigger' => 'Evento gerador',
        'calculation_basis' => 'Base de cálculo',
        'fees_commissions' => 'Taxas e comissões',
        'taxes_withholdings' => 'Tributos e retenções',
        'eligibility' => 'Elegibilidade dos participantes',
        'authorization' => 'Alçadas e autorizações',
        'settlement' => 'Prazos e forma de liquidação',
        'cancellation' => 'Cancelamentos, estornos e disputas',
        'banking' => 'Integração bancária',
        'organization' => 'Titularidade e isolamento organizacional',
    ];

    public function __construct(
        private readonly DistributionTemplateService $templates,
        private readonly DistributionCalculator $calculator,
        private readonly CaseOperations $operations
    ) {}

    private function normalized(int $case, array $data): array
    {
        $title = trim($data['title']);
        if (mb_strlen($title) < 2) {
            throw ValidationException::withMessages(['title' => 'Informe um título válido.']);
        }

        $terms = [];
        foreach (self::FIELDS as $key => $label) {
            $value = trim((string) ($data['conditions'][$key] ?? ''));
            $terms[$key] = $value === '' ? null : $value;
        }

        $model = DB::table('repasse_distribution_templates')->where('id', $data['template_id'])
            ->lockForUpdate()->firstOrFail();
        if ((int) $model->repasse_case_id !== $case) {
            throw ValidationException::withMessages([
                'template_id' => 'Modelo não pertence a este veículo.',
            ]);
        }
        if ($model->archived_at !== null || (int) $model->revision !== (int) $data['template_revision']) {
            abort(409, 'Modelo arquivado ou versão alterada. Recarregue antes de criar a minuta.');
        }

        $version = $this->templates->version((int) $model->id, (int) $model->revision);
        $frozen = $version['snapshot'];
        if ((int) $frozen['case_id'] !== $case
            || (int) $frozen['revision'] !== (int) $model->revision) {
            abort(409, 'Versão do modelo incompatível com o veículo.');
        }
        // Do not invent or resurrect parties: validate the selected model's
        // source, recipients and 10000 basis points against CURRENT linkages.
        $this->templates->validateParties($case, [
            'name' => $frozen['name'],
            'source_party_id' => $frozen['source_party_id'],
            'lines' => $frozen['lines'],
        ]);

        $evidence = $this->evidence($case, $data['evidence_ids'] ?? []);
        $fields = [
            'title' => $title,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'reference_cents' => isset($data['reference_cents']) ? (int) $data['reference_cents'] : null,
            'template_id' => (int) $model->id,
            'template_revision' => (int) $model->revision,
            'template_sha256' => $version['sha256'],
            'template_snapshot' => $frozen,
            'conditions' => $terms,
            'evidence' => $evidence,
        ];
        if ($fields['reference_cents'] !== null) {
            $this->calculator->calculate($fields['reference_cents'], $frozen['lines']);
        }

        return $fields;
    }

    private function evidence(int $case, array $ids): array
    {
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['evidence_ids' => 'Anexos duplicados.']);
        }
        if ($ids === []) return [];

        $rows = DB::table('repasse_case_evidence')
            ->where('repasse_case_id', $case)->whereIn('id', $ids)
            ->whereNull('archived_at')
            ->select('id', 'original_name', 'sha256', 'mime', 'size_bytes')
            ->orderBy('id')->get()->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'original_name' => $r->original_name,
                'sha256' => $r->sha256,
                'mime' => $r->mime,
                'size_bytes' => (int) $r->size_bytes,
            ])->all();

        if (count($rows) !== count($ids)) {
            throw ValidationException::withMessages([
                'evidence_ids' => 'Há anexos ausentes, arquivados ou pertencentes a outro veículo.',
            ]);
        }

        return $rows;
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function sha(array $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    public function create(int $case, array $data, Request $request, ?array $fork = null): array
    {
        $id = DB::transaction(function () use ($case, $data, $request, $fork): int {
            $this->operations->editable($case);
            $fields = $this->normalized($case, $data);
            $now = now();
            $id = DB::table('repasse_commercial_terms')->insertGetId([
                'repasse_case_id' => $case,
                'template_id' => $fields['template_id'],
                'template_revision' => $fields['template_revision'],
                'template_sha256' => $fields['template_sha256'],
                'template_snapshot' => $this->encode($fields['template_snapshot']),
                'title' => $fields['title'],
                'description' => $fields['description'],
                'reference_cents' => $fields['reference_cents'],
                'conditions' => $this->encode($fields['conditions']),
                'revision' => 1, 'content_sha256' => $this->sha($fields),
                'forked_from_term_id' => $fork['term'] ?? null,
                'forked_from_revision' => $fork['revision'] ?? null,
                'created_by' => $request->user('api')->id,
                'updated_by' => $request->user('api')->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->syncEvidence($id, $fields['evidence']);
            $this->record($case, $id, $request, $fork ? 'commercial_term_forked' : 'commercial_term_created');
            return $id;
        }, 3);

        return $this->show($case, $id);
    }

    public function update(int $case, int $term, array $data, Request $request): array
    {
        $changed = DB::transaction(function () use ($case, $term, $data, $request): bool {
            $this->operations->editable($case);
            $old = $this->locked($case, $term, $data['revision']);
            if ($old->archived_at !== null) {
                throw ValidationException::withMessages(['term' => 'Reabra a minuta antes de editar.']);
            }
            $fields = $this->normalized($case, $data);
            if (hash_equals($old->content_sha256, $this->sha($fields))) return false;

            DB::table('repasse_commercial_terms')->where('id', $term)->update([
                'template_id' => $fields['template_id'],
                'template_revision' => $fields['template_revision'],
                'template_sha256' => $fields['template_sha256'],
                'template_snapshot' => $this->encode($fields['template_snapshot']),
                'title' => $fields['title'],
                'description' => $fields['description'],
                'reference_cents' => $fields['reference_cents'],
                'conditions' => $this->encode($fields['conditions']),
                'content_sha256' => $this->sha($fields),
                'revision' => (int) $old->revision + 1,
                'updated_by' => $request->user('api')->id, 'updated_at' => now(),
            ]);
            DB::table('repasse_commercial_term_evidence')->where('term_id', $term)->delete();
            $this->syncEvidence($term, $fields['evidence']);
            $this->record($case, $term, $request, 'commercial_term_revised');
            return true;
        }, 3);

        return ['data' => $this->show($case, $term), 'changed' => $changed];
    }

    public function state(int $case, int $term, int $revision, string $action, Request $request): array
    {
        $changed = DB::transaction(function () use ($case, $term, $revision, $action, $request): bool {
            $this->operations->editable($case);
            $old = $this->locked($case, $term, $revision);
            $archive = $action === 'archive';
            if (($old->archived_at !== null) === $archive) return false;
            DB::table('repasse_commercial_terms')->where('id', $term)->update([
                'archived_at' => $archive ? now() : null,
                'revision' => (int) $old->revision + 1,
                'updated_by' => $request->user('api')->id, 'updated_at' => now(),
            ]);
            $this->record($case, $term, $request, $archive
                ? 'commercial_term_archived' : 'commercial_term_reopened');
            return true;
        }, 3);

        return ['data' => $this->show($case, $term), 'changed' => $changed];
    }

    private function locked(int $case, int $term, int $revision): object
    {
        $row = DB::table('repasse_commercial_terms')
            ->where('repasse_case_id', $case)->where('id', $term)
            ->lockForUpdate()->firstOrFail();
        if ((int) $row->revision !== $revision) {
            abort(409, 'Minuta alterada por outro operador. Recarregue para continuar.');
        }
        return $row;
    }

    private function syncEvidence(int $term, array $evidence): void
    {
        foreach ($evidence as $item) {
            DB::table('repasse_commercial_term_evidence')->insert([
                'term_id' => $term, 'evidence_id' => $item['id'], 'created_at' => now(),
            ]);
        }
    }

    private function fields(object $row): array
    {
        $evidence = DB::table('repasse_commercial_term_evidence as l')
            ->join('repasse_case_evidence as d', 'd.id', '=', 'l.evidence_id')
            ->where('l.term_id', $row->id)
            ->select('d.id', 'd.original_name', 'd.sha256', 'd.mime', 'd.size_bytes')
            ->orderBy('d.id')->get()->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'original_name' => $r->original_name,
                'sha256' => $r->sha256,
                'mime' => $r->mime, 'size_bytes' => (int) $r->size_bytes,
            ])->all();

        return [
            'title' => $row->title, 'description' => $row->description,
            'reference_cents' => $row->reference_cents === null ? null : (int) $row->reference_cents,
            'template_id' => (int) $row->template_id,
            'template_revision' => (int) $row->template_revision,
            'template_sha256' => $row->template_sha256,
            'template_snapshot' => json_decode($row->template_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'conditions' => json_decode($row->conditions, true, 512, JSON_THROW_ON_ERROR),
            'evidence' => $evidence,
        ];
    }

    private function snapshot(object $row): array
    {
        return ['id' => (int) $row->id, 'case_id' => (int) $row->repasse_case_id,
            'revision' => (int) $row->revision, 'archived_at' => $row->archived_at,
            'forked_from_term_id' => $row->forked_from_term_id,
            'forked_from_revision' => $row->forked_from_revision,
            'content' => $this->fields($row)];
    }

    private function record(int $case, int $term, Request $request, string $type): void
    {
        $row = DB::table('repasse_commercial_terms')->where('id', $term)->firstOrFail();
        $snapshot = $this->snapshot($row);
        DB::table('repasse_commercial_term_versions')->insert([
            'term_id' => $term, 'revision' => $row->revision,
            'event_type' => $type, 'snapshot' => $this->encode($snapshot),
            'sha256' => $this->sha($snapshot),
            'actor_user_id' => $request->user('api')->id, 'created_at' => now(),
        ]);
        $this->operations->event($case, $request, $type);
    }

    public function list(int $case, array $filters): mixed
    {
        $this->operations->ensureExists($case);
        $query = DB::table('repasse_commercial_terms as t')
            ->leftJoin('repasse_distribution_templates as m', 'm.id', '=', 't.template_id')
            ->where('t.repasse_case_id', $case)
            ->select('t.id', 't.title', 't.revision', 't.description',
                't.template_id', 't.template_revision', 'm.name as template_name',
                't.reference_cents', 't.archived_at',
                't.forked_from_term_id', 't.forked_from_revision',
                't.created_at', 't.updated_at')
            ->orderByDesc('t.updated_at')->orderByDesc('t.id');

        if (($filters['status'] ?? 'all') === 'active') $query->whereNull('t.archived_at');
        if (($filters['status'] ?? 'all') === 'archived') $query->whereNotNull('t.archived_at');
        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($filters['search']));
            $query->where('t.title', 'like', '%'.$term.'%');
        }
        $page = $query->paginate(15);
        $page->through(static function (object $row): object {
            foreach (['id', 'revision', 'template_id', 'template_revision', 'reference_cents'] as $key) {
                if ($row->{$key} !== null) $row->{$key} = (int) $row->{$key};
            }
            return $row;
        });

        return $page;
    }

    public function show(int $case, int $term): array
    {
        $this->operations->ensureExists($case);
        $row = DB::table('repasse_commercial_terms')
            ->where('repasse_case_id', $case)->where('id', $term)->firstOrFail();
        $fields = $this->fields($row);
        if (! hash_equals($row->content_sha256, $this->sha($fields))) {
            abort(409, 'Minuta inconsistente. Integridade do conteúdo não confirmada.');
        }
        if (! hash_equals($fields['template_sha256'], $this->sha($fields['template_snapshot']))) {
            abort(409, 'Snapshot do modelo de distribuição inconsistente.');
        }
        $model = DB::table('repasse_distribution_templates')
            ->where('id', $row->template_id)->first();
        $currentParties = DB::table('repasse_case_parties as cp')
            ->join('parties as p', 'p.id', '=', 'cp.party_id')
            ->where('cp.repasse_case_id', $case)->where('p.is_active', true)
            ->pluck('cp.party_id')->map(static fn ($id): int => (int) $id)->all();
        $ids = [(int) $fields['template_snapshot']['source_party_id']];
        foreach ($fields['template_snapshot']['lines'] as $line) $ids[] = (int) $line['party_id'];
        $missingParties = array_values(array_diff(array_unique($ids), $currentParties));
        $evidenceIds = array_column($fields['evidence'], 'id');
        $activeEvidenceIds = $evidenceIds === [] ? [] : DB::table('repasse_case_evidence')
            ->where('repasse_case_id', $case)->whereNull('archived_at')
            ->whereIn('id', $evidenceIds)->pluck('id')->map(
                static fn ($id): int => (int) $id
            )->all();
        $missingEvidence = array_values(array_diff($evidenceIds, $activeEvidenceIds));
        $missingConditions = [];
        foreach (self::FIELDS as $key => $label) {
            if (! $fields['conditions'][$key]) $missingConditions[] = $key;
        }
        $modelChanged = ! $model || $model->archived_at !== null
            || (int) $model->revision !== (int) $row->template_revision;
        $caseArchived = DB::table('repasse_cases')->where('id', $case)->value('status') !== 'draft';

        return [
            'id' => (int) $row->id, 'case_id' => $case,
            'revision' => (int) $row->revision, 'archived_at' => $row->archived_at,
            'forked_from_term_id' => $row->forked_from_term_id,
            'forked_from_revision' => $row->forked_from_revision,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            'content_sha256' => $row->content_sha256,
            ...$fields,
            'checks' => [
                'filled' => count(self::FIELDS) - count($missingConditions),
                'total' => count(self::FIELDS),
                'missing_conditions' => $missingConditions,
                'model_changed_or_archived' => $modelChanged,
                'missing_active_parties' => $missingParties,
                'archived_evidence' => $missingEvidence,
                'case_archived' => $caseArchived,
                'documentary_complete' => $missingConditions === []
                    && ! $modelChanged && $missingParties === []
                    && $missingEvidence === [] && ! $caseArchived
                    && $row->archived_at === null,
            ],
            'financial_authorization' => false, 'payment_enabled' => false,
        ];
    }

    public function preview(int $case, int $term): array
    {
        $show = $this->show($case, $term);
        $amount = $show['reference_cents'];
        if ($amount === null) {
            return ['reference_cents' => null, 'allocations' => [],
                'difference_cents' => null, 'executable' => false, 'payment_enabled' => false];
        }
        $lines = $this->calculator->calculate($amount, $show['template_snapshot']['lines']);
        $names = collect($show['template_snapshot']['lines'])->keyBy('party_id');
        foreach ($lines as &$line) {
            $line['party_name'] = $names[$line['party_id']]['party_name'] ?? ('Participante #'.$line['party_id']);
        }
        unset($line);
        return [
            'reference_cents' => $amount, 'allocations' => $lines,
            'allocated_cents' => array_sum(array_column($lines, 'amount_cents')),
            'difference_cents' => $amount - array_sum(array_column($lines, 'amount_cents')),
            'source_party_id' => $show['template_snapshot']['source_party_id'],
            'template_revision' => $show['template_revision'],
            'executable' => false, 'financial_authorization' => false,
            'payment_enabled' => false,
        ];
    }

    public function versions(int $case, int $term): mixed
    {
        $this->show($case, $term);
        return DB::table('repasse_commercial_term_versions')
            ->where('term_id', $term)->select('revision', 'event_type', 'sha256',
                'actor_user_id', 'created_at')->orderByDesc('revision')->paginate(20);
    }

    public function version(int $case, int $term, int $revision): array
    {
        $this->show($case, $term);
        $row = DB::table('repasse_commercial_term_versions')
            ->where('term_id', $term)->where('revision', $revision)->firstOrFail();
        $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! hash_equals($row->sha256, $this->sha($snapshot))) {
            abort(409, 'Integridade da revisão histórica não confirmada.');
        }
        return ['revision' => (int) $row->revision,
            'sha256' => $row->sha256, 'event_type' => $row->event_type,
            'actor_user_id' => $row->actor_user_id,
            'created_at' => $row->created_at, 'snapshot' => $snapshot,
            'payment_enabled' => false];
    }

    public function fork(int $case, int $term, int $revision, string $title, Request $request): array
    {
        $old = $this->version($case, $term, $revision)['snapshot']['content'];
        return $this->create($case, [
            'title' => $title, 'description' => $old['description'],
            'reference_cents' => $old['reference_cents'],
            'template_id' => $old['template_id'],
            'template_revision' => $old['template_revision'],
            'conditions' => $old['conditions'],
            'evidence_ids' => array_column($old['evidence'], 'id'),
        ], $request, ['term' => $term, 'revision' => $revision]);
    }
}
