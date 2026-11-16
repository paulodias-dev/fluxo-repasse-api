<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VehicleFinanceWorkspace
{
    public function category(array $data): array
    {
        if (isset($data['category_id'])) {
            $category = DB::table('repasse_financial_categories')
                ->where('id', $data['category_id'])->lockForUpdate()->first();
            if (! $category || ! $category->is_active || $category->kind !== $data['kind']) {
                throw ValidationException::withMessages([
                    'category_id' => 'Categoria indisponível ou incompatível com a natureza do lançamento.',
                ]);
            }

            return ['category_id' => (int) $category->id, 'category' => $category->name];
        }
        $name = trim((string) ($data['category'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['category' => 'Selecione ou informe uma categoria válida.']);
        }

        // Free-text compatibility must not bypass an explicitly disabled
        // catalogue category. Active catalogue names resolve automatically.
        $slug = Str::slug($name);
        if ($slug !== '') {
            $registered = DB::table('repasse_financial_categories')
                ->where('kind', $data['kind'])->where('slug', mb_substr($slug, 0, 100))
                ->lockForUpdate()->first();
            if ($registered) {
                if (! $registered->is_active) {
                    throw ValidationException::withMessages([
                        'category' => 'Esta categoria foi desativada e não pode ser reutilizada.',
                    ]);
                }
                return ['category_id' => (int) $registered->id, 'category' => $registered->name];
            }
        }

        return ['category_id' => null, 'category' => $name];
    }

    public function categorySlug(string $name): string
    {
        $slug = Str::slug(trim($name));
        if ($slug === '') {
            throw ValidationException::withMessages(['name' => 'Categoria precisa conter letras ou números.']);
        }

        return mb_substr($slug, 0, 100);
    }

    public function event(int $case, int $entry, Request $request, string $type, array $details = []): void
    {
        DB::table('repasse_case_financial_events')->insert([
            'repasse_case_id' => $case,
            'entry_id' => $entry,
            'actor_user_id' => $request->user('api')->getAuthIdentifier(),
            'event_type' => $type,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    public function entry(int $case, int $entry): object
    {
        return DB::table('repasse_case_financial_entries')
            ->where('repasse_case_id', $case)->where('id', $entry)->firstOrFail();
    }

    public function withDocuments(int $case, int $entry): array
    {
        $record = $this->entry($case, $entry);
        $documents = DB::table('repasse_case_financial_documents as l')
            ->join('repasse_case_evidence as d', 'd.id', '=', 'l.evidence_id')
            ->where('l.repasse_case_id', $case)->where('l.entry_id', $entry)
            ->select('d.id', 'd.original_name', 'd.mime', 'd.size_bytes', 'd.sha256',
                'd.archived_at', 'd.created_at', 'l.linked_by', 'l.created_at as linked_at')
            ->orderBy('l.id')->get();

        $eventQuery = DB::table('repasse_case_financial_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.repasse_case_id', $case)->where('e.entry_id', $entry)
            ->select('e.id', 'e.event_type', 'e.details', 'e.actor_user_id',
                'u.name as actor_name', 'e.created_at');

        return [
            'entry' => $record,
            'documents' => $documents,
            'events' => (clone $eventQuery)->orderByDesc('e.id')->limit(30)->get(),
            'events_total' => (clone $eventQuery)->count(),
        ];
    }

    public function filtered(int $case, array $filters): Builder
    {
        $query = DB::table('repasse_case_financial_entries')
            ->where('repasse_case_id', $case);
        if (! ($filters['include_voided'] ?? false)) $query->whereNull('voided_at');
        foreach (['kind', 'status', 'category_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== 'all') {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['from'])) $query->whereDate('effective_on', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('effective_on', '<=', $filters['to']);
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'],
                trim((string) $filters['search']));
            $query->where(static function (Builder $q) use ($term): void {
                $q->where('description', 'like', '%'.$term.'%')
                    ->orWhere('category', 'like', '%'.$term.'%');
            });
        }

        return $query;
    }

    public function rejectInvalidDateRange(array $filters): void
    {
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['to' => 'A data final deve ser igual ou posterior à inicial.']);
        }
    }

    public function report(int $case, array $filters): array
    {
        $this->rejectInvalidDateRange($filters);
        $base = $this->filtered($case, $filters);
        $summary = (clone $base)->selectRaw("
            COUNT(*) AS entries_total,
            COALESCE(SUM(CASE WHEN kind = 'expense' AND status = 'realized'
                THEN amount_cents ELSE 0 END), 0) AS expense_realized_cents,
            COALESCE(SUM(CASE WHEN kind = 'expense' AND status = 'planned'
                THEN amount_cents ELSE 0 END), 0) AS expense_planned_cents,
            COALESCE(SUM(CASE WHEN kind = 'income' AND status = 'realized'
                THEN amount_cents ELSE 0 END), 0) AS income_realized_cents,
            COALESCE(SUM(CASE WHEN kind = 'income' AND status = 'planned'
                THEN amount_cents ELSE 0 END), 0) AS income_planned_cents
        ")->first();
        $totals = [];
        foreach ((array) $summary as $key => $value) $totals[$key] = (int) $value;

        $categories = (clone $base)
            ->select('kind', 'status', 'category')
            ->selectRaw('COUNT(*) AS entries_count, SUM(amount_cents) AS total_cents')
            ->groupBy('kind', 'status', 'category')
            ->orderBy('kind')->orderBy('status')->orderBy('category')
            ->paginate(30);

        $months = (clone $base)
            ->selectRaw("SUBSTR(effective_on, 1, 7) AS month, kind, status,
                COUNT(*) AS entries_count, SUM(amount_cents) AS total_cents")
            ->groupByRaw('SUBSTR(effective_on, 1, 7), kind, status')
            ->orderByRaw('SUBSTR(effective_on, 1, 7)')->orderBy('kind')->orderBy('status')
            ->get()->map(static fn ($r): array => [
                'month' => $r->month ?? 'sem-data',
                'kind' => $r->kind, 'status' => $r->status,
                'entries_count' => (int) $r->entries_count,
                'total_cents' => (int) $r->total_cents,
            ]);

        $categories->through(static fn ($r): array => [
            'kind' => $r->kind, 'status' => $r->status,
            'category' => $r->category,
            'entries_count' => (int) $r->entries_count,
            'total_cents' => (int) $r->total_cents,
        ]);

        return [
            'summary' => $totals,
            'by_category' => $categories,
            'by_month' => $months,
            'scope' => [
                'case_id' => $case,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'kind' => $filters['kind'] ?? 'all',
                'status' => $filters['status'] ?? 'all',
                'include_voided' => (bool) ($filters['include_voided'] ?? false),
                'excludes_purchase_sale_profile' => true,
            ],
        ];
    }
}
