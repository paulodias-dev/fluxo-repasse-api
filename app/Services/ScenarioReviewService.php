<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScenarioReviewService
{
    private const STATUSES = ['pending', 'reviewed', 'changes_requested', 'withdrawn'];

    public function reviewer(int $id): User
    {
        $user = User::query()->where('is_active', true)->findOrFail($id);
        if (! $user->hasPermission('scenario_reviews.manage')
            || ! $user->hasPermission('scenarios.manage')
            || ! $user->hasPermission('cases.manage')) {
            throw ValidationException::withMessages(['assigned_to' => 'A pessoa selecionada não possui as permissões de revisão.']);
        }
        return $user;
    }

    public function requireReviewer(Request $request): void
    {
        if (! $request->user('api')->hasPermission('scenario_reviews.manage')) {
            abort(403, 'É necessária a permissão de revisão documental.');
        }
    }

    private function requestDigest(int $scenarioId, array $data): string
    {
        return hash('sha256', json_encode([
            'scenario_id' => $scenarioId,
            'revision' => (int) $data['scenario_revision'],
            'note' => trim($data['request_note']),
            'due_on' => $data['due_on'] ?? null,
            'assigned_to' => isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function create(int $scenarioId, array $input, Request $request): array
    {
        $actor = (int) $request->user('api')->id;
        $digest = $this->requestDigest($scenarioId, $input);
        $requestKey = strtolower($input['request_key']);
        $result = DB::transaction(function () use ($scenarioId, $input, $request, $requestKey, $digest, $actor): array {
            $existing = DB::table('repasse_scenario_reviews')->where('request_key', $requestKey)->first();
            if ($existing) {
                if (! hash_equals($existing->payload_digest, $digest) || (int) $existing->requested_by !== $actor) {
                    abort(409, 'Chave de solicitação já utilizada em outra revisão.');
                }
                return ['id' => (int) $existing->id, 'created' => false];
            }
            $scenario = DB::table('repasse_scenarios')->where('id', $scenarioId)
                ->lockForUpdate()->firstOrFail();
            // A concurrent retry may have inserted the key while this request
            // waited for the scenario lock. Use a locking/current read here.
            $afterLock = DB::table('repasse_scenario_reviews')
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($afterLock) {
                if (! hash_equals($afterLock->payload_digest, $digest) || (int) $afterLock->requested_by !== $actor) {
                    abort(409, 'Chave de solicitação já utilizada em outra revisão.');
                }
                return ['id' => (int) $afterLock->id, 'created' => false];
            }
            $case = DB::table('repasse_cases')->where('id', $scenario->repasse_case_id)->firstOrFail();
            if ($scenario->archived_at !== null || $case->status !== 'draft') {
                throw ValidationException::withMessages(['scenario_id' => 'O cenário e o dossiê precisam estar abertos.']);
            }
            if ((int) $scenario->revision !== (int) $input['scenario_revision']) {
                abort(409, 'Revisão desatualizada. Recarregue o cenário.');
            }
            $previous = DB::table('repasse_scenario_reviews')
                ->where('scenario_id', $scenarioId)
                ->where('scenario_revision', $scenario->revision)
                // After changes_requested, the operator may submit the SAME scenario
                // revision again when fixing only dossier evidence/notes.
                ->whereIn('status', ['pending', 'reviewed'])->exists();
            if ($previous) abort(409, 'Esta revisão já foi enviada para análise. Altere a proposta antes de reenviar.');

            $assignee = isset($input['assigned_to']) ? (int) $input['assigned_to'] : null;
            if ($assignee !== null) {
                $this->reviewer($assignee);
                if (in_array($assignee, [$actor, (int) $scenario->created_by], true)) {
                    throw ValidationException::withMessages(['assigned_to' => 'Solicitante/criador não pode revisar a própria proposta.']);
                }
            }
            $id = DB::table('repasse_scenario_reviews')->insertGetId([
                'scenario_id' => $scenarioId,
                'request_key' => $requestKey, 'payload_digest' => $digest,
                'scenario_revision' => $scenario->revision,
                'scenario_digest' => $scenario->current_digest,
                'status' => 'pending', 'revision' => 1,
                'requested_by' => $actor, 'assigned_to' => $assignee,
                'request_note' => trim($input['request_note']),
                'due_on' => $input['due_on'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->appendEvent($id, 'requested', $actor, trim($input['request_note']), [
                'scenario_revision' => (int) $scenario->revision,
                'scenario_digest' => $scenario->current_digest,
                'assigned_to' => $assignee, 'due_on' => $input['due_on'] ?? null,
            ]);
            $this->caseEvent((int) $scenario->repasse_case_id, $actor, 'scenario_review_requested');
            return ['id' => $id, 'created' => true];
        }, 3);
        return ['data' => $this->show($result['id']), 'created' => $result['created']];
    }

    public function decide(int $reviewId, array $input, Request $request): array
    {
        $this->requireReviewer($request);
        $actor = (int) $request->user('api')->id;
        DB::transaction(function () use ($reviewId, $input, $actor): void {
            $review = $this->lock($reviewId, (int) $input['revision']);
            if ($review->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Esta solicitação já está encerrada.']);
            }
            $scenario = DB::table('repasse_scenarios')->where('id', $review->scenario_id)
                ->lockForUpdate()->firstOrFail();
            $case = DB::table('repasse_cases')->where('id', $scenario->repasse_case_id)->firstOrFail();
            if ($this->stale($review, $scenario, $case)) abort(409, 'O cenário foi alterado ou arquivado. Reenvie uma revisão atual.');
            if ($actor === (int) $review->requested_by || $actor === (int) $scenario->created_by) {
                abort(403, 'Segregação de funções: não é permitido revisar a própria proposta.');
            }
            if ($review->assigned_to !== null && $actor !== (int) $review->assigned_to) {
                abort(403, 'Esta revisão está atribuída a outra pessoa.');
            }
            DB::table('repasse_scenario_reviews')->where('id', $reviewId)->update([
                'status' => $input['decision'], 'revision' => (int) $review->revision + 1,
                'decision_note' => trim($input['note']),
                'decided_at' => now(), 'decided_by' => $actor, 'updated_at' => now(),
            ]);
            $this->appendEvent($reviewId, $input['decision'], $actor, trim($input['note']), [
                'scenario_revision' => (int) $review->scenario_revision,
                'scenario_digest' => $review->scenario_digest,
            ]);
            $this->caseEvent((int) $scenario->repasse_case_id, $actor, 'scenario_review_decided');
        }, 3);
        return ['data' => $this->show($reviewId)];
    }

    public function comment(int $reviewId, array $input, Request $request): array
    {
        $actor = (int) $request->user('api')->id;
        DB::transaction(function () use ($reviewId, $input, $actor): void {
            $review = DB::table('repasse_scenario_reviews')->where('id', $reviewId)
                ->lockForUpdate()->firstOrFail();
            $this->appendEvent($reviewId, 'comment', $actor, trim($input['body']), [
                'review_status' => $review->status,
            ]);
        }, 3);
        return ['data' => $this->show($reviewId)];
    }

    public function assign(int $reviewId, array $input, Request $request): array
    {
        $this->requireReviewer($request);
        $actor = (int) $request->user('api')->id;
        DB::transaction(function () use ($reviewId, $input, $actor): void {
            $review = $this->lock($reviewId, (int) $input['revision']);
            if ($review->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Somente uma revisão pendente pode ser atribuída.']);
            }
            $scenario = DB::table('repasse_scenarios')->where('id', $review->scenario_id)
                ->lockForUpdate()->firstOrFail();
            $case = DB::table('repasse_cases')->where('id', $scenario->repasse_case_id)->firstOrFail();
            if ($this->stale($review, $scenario, $case)) abort(409, 'Revisão desatualizada.');
            $assignee = isset($input['assigned_to']) ? (int) $input['assigned_to'] : null;
            if ($assignee !== null) {
                $this->reviewer($assignee);
                if (in_array($assignee, [(int) $review->requested_by, (int) $scenario->created_by], true)) {
                    throw ValidationException::withMessages(['assigned_to' => 'Solicitante/criador não pode revisar.']);
                }
            }
            if ($assignee === ($review->assigned_to === null ? null : (int) $review->assigned_to)) return;
            DB::table('repasse_scenario_reviews')->where('id', $reviewId)->update([
                'assigned_to' => $assignee, 'revision' => (int) $review->revision + 1,
                'updated_at' => now(),
            ]);
            $this->appendEvent($reviewId, 'assigned', $actor, null, [
                'from_user_id' => $review->assigned_to,
                'to_user_id' => $assignee,
            ]);
        }, 3);
        return ['data' => $this->show($reviewId)];
    }

    public function withdraw(int $reviewId, array $input, Request $request): array
    {
        $actor = (int) $request->user('api')->id;
        DB::transaction(function () use ($reviewId, $input, $actor): void {
            $review = $this->lock($reviewId, (int) $input['revision']);
            if ($actor !== (int) $review->requested_by) abort(403, 'Somente quem solicitou pode retirar a revisão.');
            if ($review->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Somente revisões pendentes podem ser retiradas.']);
            }
            DB::table('repasse_scenario_reviews')->where('id', $reviewId)->update([
                'status' => 'withdrawn', 'revision' => (int) $review->revision + 1,
                'updated_at' => now(),
            ]);
            $this->appendEvent($reviewId, 'withdrawn', $actor, trim($input['note']), []);
        }, 3);
        return ['data' => $this->show($reviewId)];
    }

    private function lock(int $id, int $revision): object
    {
        $review = DB::table('repasse_scenario_reviews')->where('id', $id)->lockForUpdate()->firstOrFail();
        if ((int) $review->revision !== $revision) abort(409, 'Revisão de análise desatualizada. Atualize a página.');
        return $review;
    }

    private function stale(object $review, object $scenario, object $case): bool
    {
        return (int) $scenario->revision !== (int) $review->scenario_revision
            || ! hash_equals($review->scenario_digest, $scenario->current_digest)
            || $scenario->archived_at !== null
            || $case->status !== 'draft';
    }

    private function appendEvent(int $reviewId, string $event, int $actor, ?string $body, array $details): void
    {
        // Call only while holding the parent review row lock; the next sequence is serialized.
        $previous = DB::table('repasse_scenario_review_events')
            ->where('review_id', $reviewId)->orderByDesc('sequence')->first();
        $sequence = $previous ? (int) $previous->sequence + 1 : 1;
        $previousHash = $previous?->event_hash;
        $payload = [
            'review_id' => $reviewId, 'sequence' => $sequence,
            'event_type' => $event, 'actor_user_id' => $actor,
            'body' => $body, 'details' => $details,
            'previous_hash' => $previousHash,
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        DB::table('repasse_scenario_review_events')->insert([
            'review_id' => $reviewId, 'sequence' => $sequence,
            'event_type' => $event, 'actor_user_id' => $actor,
            'body' => $body, 'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'previous_hash' => $previousHash, 'event_hash' => $hash, 'created_at' => now(),
        ]);
    }

    private function caseEvent(int $caseId, int $actor, string $type): void
    {
        DB::table('repasse_case_events')->insert([
            'repasse_case_id' => $caseId, 'actor_user_id' => $actor,
            'event_type' => $type, 'created_at' => now(),
        ]);
    }

    public function show(int $reviewId): array
    {
        $row = DB::table('repasse_scenario_reviews as r')
            ->join('repasse_scenarios as s', 's.id', '=', 'r.scenario_id')
            ->join('repasse_cases as c', 'c.id', '=', 's.repasse_case_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'r.requested_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'r.assigned_to')
            ->leftJoin('users as decider', 'decider.id', '=', 'r.decided_by')
            ->select('r.*', 's.title as scenario_title', 's.revision as current_scenario_revision',
                's.current_digest as current_scenario_digest', 's.created_by as scenario_creator_id',
                's.archived_at as scenario_archived_at', 'c.id as case_id', 'c.title as case_title',
                'c.status as case_status', 'requester.name as requester_name',
                'reviewer.name as assignee_name', 'decider.name as decider_name')
            ->where('r.id', $reviewId)->firstOrFail();
        return $this->represent($row);
    }

    public function represent(object $row): array
    {
        $stale = (int) $row->current_scenario_revision !== (int) $row->scenario_revision
            || ! hash_equals($row->scenario_digest, $row->current_scenario_digest)
            || $row->scenario_archived_at !== null || $row->case_status !== 'draft';
        return [
            'id' => (int) $row->id, 'scenario_id' => (int) $row->scenario_id,
            'scenario_title' => $row->scenario_title,
            'case_id' => (int) $row->case_id, 'case_title' => $row->case_title,
            'scenario_revision' => (int) $row->scenario_revision,
            'current_scenario_revision' => (int) $row->current_scenario_revision,
            'scenario_digest' => $row->scenario_digest, 'status' => $row->status,
            'revision' => (int) $row->revision, 'is_stale' => $stale,
            'requested_by' => $row->requested_by === null ? null : (int) $row->requested_by,
            'requester_name' => $row->requester_name,
            'assigned_to' => $row->assigned_to === null ? null : (int) $row->assigned_to,
            'assignee_name' => $row->assignee_name,
            'scenario_creator_id' => $row->scenario_creator_id === null ? null : (int) $row->scenario_creator_id,
            'decided_by' => $row->decided_by === null ? null : (int) $row->decided_by,
            'decider_name' => $row->decider_name,
            'due_on' => $row->due_on, 'request_note' => $row->request_note,
            'decision_note' => $row->decision_note,
            'decided_at' => $row->decided_at, 'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'decision_is_financial_authorization' => false,
            'payment_enabled' => false,
        ];
    }

    public function snapshot(int $reviewId, ScenarioPlanner $planner): array
    {
        $review = $this->show($reviewId);
        // Read the exact immutable scenario VERSION requested, never the current edited draft.
        // ScenarioPlanner::versionDetail independently verifies its SHA-256 before returning.
        $version = $planner->versionDetail($review['scenario_id'], $review['scenario_revision']);
        $snapshot = $version['snapshot'];
        return [
            'review_id' => $reviewId,
            'scenario_id' => $review['scenario_id'],
            'scenario_revision' => $review['scenario_revision'],
            'scenario_sha256' => $version['sha256'],
            'is_stale' => $review['is_stale'],
            'snapshot' => $snapshot,
            'source' => 'immutable_scenario_version',
            'financial_authorization' => false,
            'payment_enabled' => false,
        ];
    }

    public function verify(int $reviewId): array
    {
        $this->show($reviewId);
        $previousHash = null;
        $expectedSequence = 1;
        foreach (DB::table('repasse_scenario_review_events')
            ->where('review_id', $reviewId)->orderBy('sequence')->cursor() as $event) {
            $details = json_decode($event->details, true, 512, JSON_THROW_ON_ERROR);
            $payload = [
                'review_id' => (int) $event->review_id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type,
                'actor_user_id' => $event->actor_user_id === null ? null : (int) $event->actor_user_id,
                'body' => $event->body,
                'details' => $details,
                'previous_hash' => $event->previous_hash,
            ];
            $actualHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if ($event->sequence !== $expectedSequence
                || ! hash_equals($event->previous_hash ?? '', $previousHash ?? '')
                || ! hash_equals($event->event_hash, $actualHash)) {
                return ['valid' => false, 'failed_sequence' => $expectedSequence,
                    'checked_events' => $expectedSequence - 1];
            }
            $previousHash = $event->event_hash;
            $expectedSequence++;
        }
        return ['valid' => $expectedSequence > 1, 'checked_events' => $expectedSequence - 1,
            'last_hash' => $previousHash, 'verification_scope' => 'internal_hash_chain_only'];
    }
}
