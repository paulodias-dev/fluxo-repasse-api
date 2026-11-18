<?php

namespace App\Services;

use App\Support\TenantTable;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ScenarioReviewQueries
{
    public function __construct(private readonly ScenarioReviewService $reviews) {}

    private function base()
    {
        return TenantTable::table('repasse_scenario_reviews as r')
            ->join('repasse_scenarios as s', 's.id', '=', 'r.scenario_id')
            ->join('repasse_cases as c', 'c.id', '=', 's.repasse_case_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'r.requested_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'r.assigned_to')
            ->leftJoin('users as decider', 'decider.id', '=', 'r.decided_by')
            ->select('r.*', 's.title as scenario_title', 's.revision as current_scenario_revision',
                's.current_digest as current_scenario_digest', 's.created_by as scenario_creator_id',
                's.archived_at as scenario_archived_at', 'c.id as case_id', 'c.title as case_title',
                'c.status as case_status', 'requester.name as requester_name',
                'reviewer.name as assignee_name', 'decider.name as decider_name');
    }

    private function stale($query)
    {
        return $query->where(function ($q): void {
            $q->whereColumn('s.revision', '!=', 'r.scenario_revision')
                ->orWhereColumn('s.current_digest', '!=', 'r.scenario_digest')
                ->orWhereNotNull('s.archived_at')
                ->orWhere('c.status', '!=', 'draft');
        });
    }

    public function list(array $filters, int $actor): mixed
    {
        $query = $this->base();
        if (($filters['status'] ?? 'all') !== 'all') $query->where('r.status', $filters['status']);
        if (! empty($filters['scenario_id'])) $query->where('r.scenario_id', $filters['scenario_id']);
        if (! empty($filters['case_id'])) $query->where('c.id', $filters['case_id']);
        if (($filters['scope'] ?? 'all') === 'mine') $query->where('r.assigned_to', $actor);
        if (($filters['scope'] ?? 'all') === 'requested') $query->where('r.requested_by', $actor);
        if (($filters['scope'] ?? 'all') === 'unassigned') $query->whereNull('r.assigned_to');
        if ($filters['stale'] ?? false) $this->stale($query);
        if ($filters['overdue'] ?? false) {
            $query->where('r.status', 'pending')->whereNotNull('r.due_on')
                ->whereDate('r.due_on', '<', now()->toDateString());
        }
        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['search']));
            $query->where(function ($q) use ($term): void {
                $q->where('s.title', 'like', '%'.$term.'%')
                    ->orWhere('c.title', 'like', '%'.$term.'%')
                    ->orWhere('requester.name', 'like', '%'.$term.'%');
            });
        }
        $page = $query->orderByRaw("CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('r.due_on')->orderByDesc('r.id')->paginate(20);
        $page->through(fn ($row) => $this->reviews->represent($row));
        return $page;
    }

    public function summary(int $actor): array
    {
        $counts = TenantTable::table('repasse_scenario_reviews')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed")
            ->selectRaw("SUM(CASE WHEN status = 'changes_requested' THEN 1 ELSE 0 END) as changes_requested")
            ->selectRaw("SUM(CASE WHEN status = 'withdrawn' THEN 1 ELSE 0 END) as withdrawn")
            ->selectRaw("SUM(CASE WHEN status = 'pending' AND assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned")
            ->selectRaw("SUM(CASE WHEN status = 'pending' AND assigned_to = ? THEN 1 ELSE 0 END) as assigned_to_me", [$actor])
            ->selectRaw("SUM(CASE WHEN status = 'pending' AND due_on < ? THEN 1 ELSE 0 END) as overdue", [now()->toDateString()])
            ->first();

        $staleCount = $this->stale($this->base())->where('r.status', 'pending')->count();
        return [
            'total' => (int) $counts->total,
            'pending' => (int) $counts->pending,
            'reviewed' => (int) $counts->reviewed,
            'changes_requested' => (int) $counts->changes_requested,
            'withdrawn' => (int) $counts->withdrawn,
            'unassigned' => (int) $counts->unassigned,
            'assigned_to_me' => (int) $counts->assigned_to_me,
            'overdue' => (int) $counts->overdue,
            'stale_pending' => (int) $staleCount,
            'financially_approved' => 0,
            'payment_enabled' => false,
        ];
    }

    public function reviewers(array $filters): mixed
    {
        $query = User::query()->where('is_active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', 'scenario_reviews.manage'))
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', 'scenarios.manage'))
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', 'cases.manage'))
            ->select(['id', 'name'])->orderBy('name')->orderBy('id');
        if (trim($filters['search'] ?? '') !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['search']));
            $query->where('name', 'like', '%'.$term.'%');
        }
        return $query->paginate(30);
    }

    public function events(int $reviewId): mixed
    {
        $this->reviews->show($reviewId);
        return TenantTable::table('repasse_scenario_review_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.review_id', $reviewId)
            ->select('e.sequence', 'e.event_type', 'e.actor_user_id',
                'u.name as actor_name', 'e.body', 'e.details',
                'e.event_hash', 'e.previous_hash', 'e.created_at')
            ->orderBy('e.sequence')->paginate(40);
    }
}
