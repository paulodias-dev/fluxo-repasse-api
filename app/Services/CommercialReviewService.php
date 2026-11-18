<?php

namespace App\Services;

use App\Support\TenantDB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal, single-organization DOCUMENTARY review of commercial terms.
 * This is neither contractual acceptance nor authorization to move money.
 */
final class CommercialReviewService
{
    public function __construct(
        private readonly CommercialTermsService $terms,
        private readonly ScenarioReviewService $scenarioReviews,
        private readonly CaseOperations $cases
    ) {}

    private function digest(array $data): string
    {
        return hash('sha256', json_encode([
            'case_id' => (int) $data['case_id'],
            'term_id' => (int) $data['term_id'],
            'term_revision' => (int) $data['term_revision'],
            'request_note' => trim($data['request_note']),
            'assigned_to' => isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            'due_on' => $data['due_on'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function incompatible(int $case, int $term): bool
    {
        $details = $this->terms->show($case, $term);
        return $details['archived_at'] !== null
            || $details['checks']['case_archived']
            || $details['checks']['model_changed_or_archived']
            || $details['checks']['missing_active_parties'] !== []
            || $details['checks']['archived_evidence'] !== [];
    }

    private function conflict(int $actor, object $review, object $term): bool
    {
        // Any requester, current creator/editor, or author of the frozen version
        // is barred from deciding or assigning the review to themself.
        $versionAuthor = TenantDB::table('repasse_commercial_term_versions')
            ->where('term_id', $term->id)->where('revision', $review->term_revision)
            ->value('actor_user_id');
        return in_array($actor, array_filter([
            $review->requested_by, $term->created_by, $term->updated_by, $versionAuthor,
        ], static fn ($id): bool => $id !== null), false);
    }

    private function row(int $id, ?int $revision = null): object
    {
        $review = TenantDB::table('repasse_commercial_reviews')
            ->where('id', $id)->lockForUpdate()->firstOrFail();
        if ($revision !== null && (int) $review->revision !== $revision) {
            abort(409, 'Versão da análise desatualizada. Recarregue antes de continuar.');
        }
        return $review;
    }

    private function current(object $review, bool $requireActive = true): array
    {
        $term = TenantDB::table('repasse_commercial_terms')
            ->where('id', $review->term_id)->lockForUpdate()->firstOrFail();
        if ((int) $term->revision !== (int) $review->term_revision
            || ! hash_equals($term->content_sha256, $review->term_sha256)
            || $this->incompatible((int) $term->repasse_case_id, (int) $term->id)) {
            abort(409, 'Minuta alterada, arquivada ou vínculo desatualizado. Solicite análise da versão atual.');
        }
        if ($requireActive && $review->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'A análise já foi encerrada.']);
        }
        return [$term, $this->terms->show((int) $term->repasse_case_id, (int) $term->id)];
    }

    private function event(int $review, string $type, int $actor, ?string $body, array $details): void
    {
        // Call only when holding the review lock or immediately after inserting it.
        $prior = TenantDB::table('repasse_commercial_review_events')
            ->where('review_id', $review)->orderByDesc('sequence')->first();
        $sequence = $prior ? (int) $prior->sequence + 1 : 1;
        $payload = [
            'review_id' => $review, 'sequence' => $sequence, 'event_type' => $type,
            'actor_user_id' => $actor, 'body' => $body, 'details' => $details,
            'previous_hash' => $prior?->event_hash,
        ];
        TenantDB::table('repasse_commercial_review_events')->insert([
            'review_id' => $review, 'sequence' => $sequence,
            'event_type' => $type, 'actor_user_id' => $actor,
            'body' => $body,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'previous_hash' => $prior?->event_hash,
            'event_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'created_at' => now(),
        ]);
    }

    public function create(array $data, Request $request): array
    {
        $actor = (int) $request->user('api')->id;
        $digest = $this->digest($data);
        $requestKey = strtolower($data['request_key']);
        $result = DB::transaction(function () use ($data, $request, $actor, $digest, $requestKey): array {
            $term = TenantDB::table('repasse_commercial_terms')->where('id', $data['term_id'])
                ->lockForUpdate()->firstOrFail();
            if ((int) $term->repasse_case_id !== (int) $data['case_id']) abort(404);
            $existing = TenantDB::table('repasse_commercial_reviews')->where('request_key', $requestKey)->first();
            if ($existing) {
                if (! hash_equals($existing->payload_digest, $digest)
                    || (int) $existing->requested_by !== $actor) {
                    abort(409, 'Chave idempotente já usada em outra solicitação.');
                }
                return ['id' => (int) $existing->id, 'created' => false];
            }
            if ((int) $term->revision !== (int) $data['term_revision']) {
                abort(409, 'Minuta alterada. Recarregue a revisão atual.');
            }
            if ($this->incompatible((int) $data['case_id'], (int) $data['term_id'])) {
                abort(409, 'Minuta e vínculos devem estar ativos e atuais antes de solicitar conferência.');
            }
            $open = TenantDB::table('repasse_commercial_reviews')
                ->where('term_id', $term->id)->where('term_revision', $term->revision)
                ->whereIn('status', ['pending', 'reviewed'])->exists();
            if ($open) abort(409, 'Esta versão já foi enviada ou conferida. Edite ou retire a análise existente.');

            $assigned = isset($data['assigned_to']) ? (int) $data['assigned_to'] : null;
            if ($assigned !== null) {
                $this->scenarioReviews->reviewer($assigned);
                $candidate = (object) [
                    'term_revision' => $term->revision, 'requested_by' => $actor,
                ];
                if ($this->conflict($assigned, $candidate, $term)) {
                    throw ValidationException::withMessages(['assigned_to' => 'Autor ou solicitante não pode revisar sua própria minuta.']);
                }
            }
            $version = $this->terms->version((int) $term->repasse_case_id, (int) $term->id, (int) $term->revision);
            $id = TenantDB::table('repasse_commercial_reviews')->insertGetId([
                'term_id' => $term->id, 'request_key' => $requestKey,
                'payload_digest' => $digest, 'term_revision' => $term->revision,
                'term_sha256' => $term->content_sha256,
                'term_version_sha256' => $version['sha256'],
                'status' => 'pending', 'revision' => 1,
                'requested_by' => $actor, 'assigned_to' => $assigned,
                'due_on' => $data['due_on'] ?? null, 'request_note' => trim($data['request_note']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($id, 'requested', $actor, trim($data['request_note']), [
                'term_revision' => (int) $term->revision,
                'term_version_sha256' => $version['sha256'],
                'assigned_to' => $assigned, 'due_on' => $data['due_on'] ?? null,
            ]);
            $this->cases->event((int) $term->repasse_case_id, $request, 'commercial_review_requested');
            return ['id' => $id, 'created' => true];
        }, 3);

        return ['data' => $this->show($result['id']), 'created' => $result['created']];
    }

    public function assign(int $id, array $input, Request $request): array
    {
        $this->scenarioReviews->requireReviewer($request);
        $actor = (int) $request->user('api')->id;
        DB::transaction(function () use ($id, $input, $actor, $request): void {
            $review = $this->row($id, (int) $input['revision']);
            [$term] = $this->current($review);
            $assigned = isset($input['assigned_to']) ? (int) $input['assigned_to'] : null;
            if ($assigned !== null) {
                $this->scenarioReviews->reviewer($assigned);
                if ($this->conflict($assigned, $review, $term)) {
                    throw ValidationException::withMessages(['assigned_to' => 'Autor ou solicitante não pode revisar.']);
                }
            }
            if ($assigned === ($review->assigned_to === null ? null : (int) $review->assigned_to)) return;
            TenantDB::table('repasse_commercial_reviews')->where('id', $id)->update([
                'assigned_to' => $assigned, 'revision' => $review->revision + 1,
                'updated_at' => now(),
            ]);
            $this->event($id, 'assigned', $actor, null, [
                'from_user_id' => $review->assigned_to, 'to_user_id' => $assigned,
            ]);
            $this->cases->event((int) $term->repasse_case_id, $request, 'commercial_review_assigned');
        }, 3);
        return ['data' => $this->show($id)];
    }

    public function comment(int $id, array $input, Request $request): array
    {
        $actor = (int) $request->user('api')->id;
        DB::transaction(function () use ($id, $actor, $input, $request): void {
            $review = $this->row($id);
            if ($review->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Somente análises pendentes podem receber comentários.']);
            }
            $term = TenantDB::table('repasse_commercial_terms')->where('id', $review->term_id)->firstOrFail();
            $this->event($id, 'comment', $actor, trim($input['body']), ['status' => $review->status]);
            $this->cases->event((int) $term->repasse_case_id, $request, 'commercial_review_commented');
        }, 3);
        return ['data' => $this->show($id)];
    }

    public function decide(int $id, array $input, Request $request): array
    {
        $this->scenarioReviews->requireReviewer($request);
        $actor = (int) $request->user('api')->id;
        DB::transaction(function () use ($id, $input, $request, $actor): void {
            $review = $this->row($id, (int) $input['revision']);
            [$term, $details] = $this->current($review);
            if ($this->conflict($actor, $review, $term)) {
                abort(403, 'Segregação de funções: é proibido conferir a própria minuta.');
            }
            if ($review->assigned_to !== null && $actor !== (int) $review->assigned_to) {
                abort(403, 'Análise atribuída a outro revisor.');
            }
            if ($input['decision'] === 'reviewed' && ! $details['checks']['documentary_complete']) {
                throw ValidationException::withMessages([
                    'decision' => 'Campos e vínculos precisam estar completos para registrar conferência documental.',
                ]);
            }
            TenantDB::table('repasse_commercial_reviews')->where('id', $id)->update([
                'status' => $input['decision'], 'decision_note' => trim($input['note']),
                'decided_by' => $actor, 'decided_at' => now(),
                'revision' => $review->revision + 1, 'updated_at' => now(),
            ]);
            $this->event($id, $input['decision'], $actor, trim($input['note']), [
                'term_revision' => (int) $review->term_revision,
                'term_sha256' => $review->term_sha256,
                'financial_authorization' => false,
            ]);
            $this->cases->event((int) $term->repasse_case_id, $request, 'commercial_review_decided');
        }, 3);
        return ['data' => $this->show($id)];
    }

    public function withdraw(int $id, array $input, Request $request): array
    {
        $actor = (int) $request->user('api')->id;
        DB::transaction(function () use ($id, $input, $actor, $request): void {
            $review = $this->row($id, (int) $input['revision']);
            if ($actor !== (int) $review->requested_by) abort(403, 'Somente o solicitante pode retirar a análise.');
            if ($review->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Somente análises pendentes podem ser retiradas.']);
            }
            TenantDB::table('repasse_commercial_reviews')->where('id', $id)->update([
                'status' => 'withdrawn', 'revision' => $review->revision + 1, 'updated_at' => now(),
            ]);
            $this->event($id, 'withdrawn', $actor, trim($input['note']), []);
            $term = TenantDB::table('repasse_commercial_terms')->where('id', $review->term_id)->firstOrFail();
            $this->cases->event((int) $term->repasse_case_id, $request, 'commercial_review_withdrawn');
        }, 3);
        return ['data' => $this->show($id)];
    }

    private function base(): \Illuminate\Database\Query\Builder
    {
        return TenantDB::table('repasse_commercial_reviews as r')
            ->join('repasse_commercial_terms as t', 't.id', '=', 'r.term_id')
            ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->leftJoin('repasse_distribution_templates as m', 'm.id', '=', 't.template_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'r.requested_by')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'r.assigned_to')
            ->leftJoin('users as decider', 'decider.id', '=', 'r.decided_by')
            ->select('r.*', 't.title as term_title', 't.revision as current_term_revision',
                't.content_sha256 as current_term_sha256', 't.archived_at as term_archived_at',
                't.repasse_case_id as case_id', 't.created_by as term_creator_id',
                't.updated_by as term_editor_id', 't.template_revision as current_model_revision',
                'm.revision as model_revision', 'm.archived_at as model_archived_at',
                'c.title as case_title', 'c.status as case_status',
                'requester.name as requester_name', 'assignee.name as assignee_name',
                'decider.name as decider_name');
    }

    private function serialize(object $r, bool $verified = false): array
    {
        $basicStale = (int) $r->term_revision !== (int) $r->current_term_revision
            || ! hash_equals($r->term_sha256, $r->current_term_sha256)
            || $r->term_archived_at !== null || $r->case_status !== 'draft'
            || $r->model_revision === null || $r->model_archived_at !== null
            || (int) $r->model_revision !== (int) $r->current_model_revision;
        return [
            'id' => (int) $r->id, 'term_id' => (int) $r->term_id,
            'term_title' => $r->term_title, 'case_id' => (int) $r->case_id,
            'case_title' => $r->case_title, 'term_revision' => (int) $r->term_revision,
            'current_term_revision' => (int) $r->current_term_revision,
            'term_sha256' => $r->term_sha256, 'term_version_sha256' => $r->term_version_sha256,
            'status' => $r->status, 'revision' => (int) $r->revision,
            'is_stale' => $basicStale, 'full_link_checks_pending' => ! $verified,
            'requested_by' => $r->requested_by === null ? null : (int) $r->requested_by,
            'requester_name' => $r->requester_name,
            'assigned_to' => $r->assigned_to === null ? null : (int) $r->assigned_to,
            'assignee_name' => $r->assignee_name,
            'decided_by' => $r->decided_by === null ? null : (int) $r->decided_by,
            'decider_name' => $r->decider_name, 'decision_note' => $r->decision_note,
            'request_note' => $r->request_note, 'due_on' => $r->due_on,
            'decided_at' => $r->decided_at, 'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
            'financial_authorization' => false, 'payment_enabled' => false,
        ];
    }

    public function index(array $filters, int $actor): array
    {
        $query = $this->base();
        if (! empty($filters['case_id'])) $query->where('t.repasse_case_id', $filters['case_id']);
        if (! empty($filters['term_id'])) $query->where('r.term_id', $filters['term_id']);
        if (($filters['status'] ?? 'all') !== 'all') $query->where('r.status', $filters['status']);
        if (($filters['scope'] ?? 'all') === 'requested') $query->where('r.requested_by', $actor);
        if (($filters['scope'] ?? 'all') === 'assigned') $query->where('r.assigned_to', $actor);
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
            $query->where(static function ($q) use ($like): void {
                $q->where('t.title', 'like', $like)->orWhere('c.title', 'like', $like);
            });
        }

        $summary = [
            'total' => (clone $query)->count('r.id'),
            'pending' => (clone $query)->where('r.status', 'pending')->count('r.id'),
            'reviewed' => (clone $query)->where('r.status', 'reviewed')->count('r.id'),
            'changes_requested' => (clone $query)->where('r.status', 'changes_requested')->count('r.id'),
            'withdrawn' => (clone $query)->where('r.status', 'withdrawn')->count('r.id'),
        ];
        $page = (clone $query)->orderByDesc('r.created_at')->orderByDesc('r.id')->paginate(20);
        $page->through(fn (object $row): array => $this->serialize($row));
        return ['summary' => $summary, 'reviews' => $page, 'payment_enabled' => false];
    }

    public function show(int $id): array
    {
        $row = $this->base()->where('r.id', $id)->firstOrFail();
        $review = $this->serialize($row);
        $detail = $this->terms->show((int) $row->case_id, (int) $row->term_id);
        $review['is_stale'] = $review['is_stale']
            || $detail['checks']['missing_active_parties'] !== []
            || $detail['checks']['archived_evidence'] !== [];
        $review['full_link_checks_pending'] = false;
        $review['documentary_complete'] = $detail['checks']['documentary_complete'];
        return $review;
    }

    public function snapshot(int $id): array
    {
        $review = $this->show($id);
        $version = $this->terms->version(
            $review['case_id'], $review['term_id'], $review['term_revision']
        );
        if (! hash_equals($review['term_version_sha256'], $version['sha256'])) {
            abort(409, 'Versão documental divergente da solicitação.');
        }
        return [
            'review_id' => $id, 'term_revision' => $review['term_revision'],
            'term_version_sha256' => $version['sha256'],
            'is_stale' => $review['is_stale'], 'snapshot' => $version['snapshot'],
            'source' => 'immutable_commercial_term_version',
            'financial_authorization' => false, 'payment_enabled' => false,
        ];
    }

    public function events(int $id): mixed
    {
        $this->show($id);
        return TenantDB::table('repasse_commercial_review_events')->where('review_id', $id)
            ->select('sequence', 'event_type', 'actor_user_id', 'body',
                'previous_hash', 'event_hash', 'created_at')
            ->orderBy('sequence')->paginate(25);
    }

    public function integrity(int $id): array
    {
        $snapshot = $this->snapshot($id);
        $expected = 1;
        $previous = null;
        foreach (TenantDB::table('repasse_commercial_review_events')
            ->where('review_id', $id)->orderBy('sequence')->cursor() as $event) {
            $details = json_decode($event->details, true, 512, JSON_THROW_ON_ERROR);
            $payload = [
                'review_id' => (int) $event->review_id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type,
                'actor_user_id' => $event->actor_user_id === null ? null : (int) $event->actor_user_id,
                'body' => $event->body, 'details' => $details,
                'previous_hash' => $event->previous_hash,
            ];
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if ((int) $event->sequence !== $expected
                || ! hash_equals($event->previous_hash ?? '', $previous ?? '')
                || ! hash_equals($event->event_hash, $hash)) {
                return ['valid' => false, 'failed_sequence' => $expected,
                    'checked_events' => $expected - 1, 'verification_scope' => 'internal_chain_only'];
            }
            $previous = $event->event_hash;
            $expected++;
        }
        return [
            'valid' => $expected > 1, 'checked_events' => $expected - 1,
            'last_hash' => $previous,
            'term_version_sha256' => $snapshot['term_version_sha256'],
            'verification_scope' => 'internal_chain_and_term_snapshot_only',
            'financial_authorization' => false,
        ];
    }
}
