<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CommercialTermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A single-org commercial portfolio. Read-only documentary triage, not a ledger.
 * Current-party and attachment integrity remain authoritative in term detail.
 */
final class CommercialPortfolioController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['sometimes', Rule::in(['all', 'active', 'archived'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'case_id' => ['sometimes', 'integer', 'min:1'],
            'preparation' => ['sometimes', Rule::in([
                'all', 'reviewed', 'needs_review', 'reference_missing', 'model_outdated',
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    private function query(array $filters): \Illuminate\Database\Query\Builder
    {
        // Último parecer da MESMA revisão+SHA, no SQL antes da paginação.
        // Parecer anterior à alteração da minuta NÃO pode ser reaproveitado.
        $latest = DB::table('repasse_commercial_reviews')
            ->select('term_id', 'term_revision', 'term_sha256')
            ->selectRaw('MAX(id) AS review_id')
            ->groupBy('term_id', 'term_revision', 'term_sha256');
        $query = DB::table('repasse_commercial_terms as t')
            ->join('repasse_cases as c', 'c.id', '=', 't.repasse_case_id')
            ->leftJoin('repasse_distribution_templates as m', 'm.id', '=', 't.template_id')
            ->leftJoinSub($latest, 'lr', static function ($join): void {
                $join->on('lr.term_id', '=', 't.id')
                    ->on('lr.term_revision', '=', 't.revision')
                    ->on('lr.term_sha256', '=', 't.content_sha256');
            })
            ->leftJoin('repasse_commercial_reviews as cr', 'cr.id', '=', 'lr.review_id');

        if (($filters['status'] ?? 'all') === 'active') {
            $query->whereNull('t.archived_at');
        }
        if (($filters['status'] ?? 'all') === 'archived') {
            $query->whereNotNull('t.archived_at');
        }
        if (isset($filters['case_id'])) {
            $query->where('t.repasse_case_id', (int) $filters['case_id']);
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
            $query->where(static function ($q) use ($like): void {
                $q->where('t.title', 'like', $like)
                    ->orWhere('c.title', 'like', $like)
                    ->orWhere('c.plate', 'like', $like);
            });
        }

        switch ($filters['preparation'] ?? 'all') {
            case 'reviewed':
                $query->where('cr.status', 'reviewed');
                break;
            case 'needs_review':
                $this->needsReview($query);
                break;
            case 'reference_missing':
                $query->whereNull('t.reference_cents');
                break;
            case 'model_outdated':
                $this->outdated($query);
                break;
        }

        return $query;
    }

    private function needsReview(\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder
    {
        return $query->where(static function ($q): void {
            $q->whereNull('cr.id')->orWhere('cr.status', '!=', 'reviewed');
        });
    }

    private function outdated(\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder
    {
        return $query->where(static function ($q): void {
            $q->whereNull('m.id')->orWhereNotNull('m.archived_at')
                ->orWhereColumn('m.revision', '!=', 't.template_revision');
        });
    }

    private function columns(): array
    {
        return [
            't.id as id', 't.repasse_case_id as case_id',
            'c.title as case_title', 'c.plate as plate', 'c.status as case_status',
            't.title', 't.description', 't.conditions', 't.revision',
            't.template_id', 't.template_revision', 'm.revision as current_template_revision',
            'm.name as template_name', 'm.archived_at as model_archived_at',
            't.reference_cents', 't.archived_at', 't.created_at', 't.updated_at',
            'cr.id as current_review_id', 'cr.status as current_review_status',
        ];
    }

    private function item(object $row): array
    {
        $conditions = json_decode($row->conditions, true, 512, JSON_THROW_ON_ERROR);
        $missing = [];
        foreach (CommercialTermsService::FIELDS as $field => $label) {
            if (trim((string) ($conditions[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }
        $modelOutdated = $row->current_template_revision === null
            || $row->model_archived_at !== null
            || (int) $row->current_template_revision !== (int) $row->template_revision;

        return [
            'id' => (int) $row->id,
            'case_id' => (int) $row->case_id,
            'case_title' => $row->case_title,
            'plate' => $row->plate,
            'case_status' => $row->case_status,
            'title' => $row->title,
            'revision' => (int) $row->revision,
            'template_id' => (int) $row->template_id,
            'template_name' => $row->template_name,
            'template_revision' => (int) $row->template_revision,
            'reference_cents' => $row->reference_cents === null ? null : (int) $row->reference_cents,
            'archived_at' => $row->archived_at,
            'updated_at' => $row->updated_at,
            'conditions_filled' => count(CommercialTermsService::FIELDS) - count($missing),
            'conditions_total' => count(CommercialTermsService::FIELDS),
            'missing_conditions' => $missing,
            'model_outdated' => $modelOutdated,
            'current_review_id' => $row->current_review_id === null ? null : (int) $row->current_review_id,
            'current_review_status' => $row->current_review_status ?? 'not_requested',
            'reference_missing' => $row->reference_cents === null,
            'vehicle_archived' => $row->case_status !== 'draft',
            'full_documentary_checks_pending' => true,
            'financial_authorization' => false,
            'payment_enabled' => false,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->query($this->filters($request));
        $summary = [
            'total' => (clone $query)->count('t.id'),
            'active' => (clone $query)->whereNull('t.archived_at')->count('t.id'),
            'archived' => (clone $query)->whereNotNull('t.archived_at')->count('t.id'),
            'model_outdated' => $this->outdated(clone $query)->count('t.id'),
            'vehicle_archived' => (clone $query)->where('c.status', '!=', 'draft')->count('t.id'),
            'reviewed_current_revision' => (clone $query)->where('cr.status', 'reviewed')->count('t.id'),
            'needs_documentary_review' => $this->needsReview(clone $query)->count('t.id'),
            'reference_missing' => (clone $query)->whereNull('t.reference_cents')->count('t.id'),
        ];
        $terms = (clone $query)->select($this->columns())
            ->orderByDesc('t.updated_at')->orderByDesc('t.id')->paginate(20);
        $terms->through(fn (object $row): array => $this->item($row));

        return response()->json([
            'data' => [
                'summary' => $summary, 'terms' => $terms, 'financial_authorization' => false,
                'payment_enabled' => false,
                'note' => 'Indicadores de triagem. Verificação integral de partes, documentos e SHA ocorre na ficha da minuta.',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function safe(?string $value): string
    {
        $text = trim(str_replace(["\r", "\n", "\t"], ' ', $value ?? ''));
        return preg_match('/^[=+\-@]/u', $text) ? "'".$text : $text;
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($this->filters($request))->select($this->columns());
        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Minuta ID', 'Veículo ID', 'Veículo', 'Placa', 'Minuta', 'Revisão',
                'Status', 'Modelo', 'Revisão do modelo', 'Referência (centavos)',
                'Campos preenchidos', 'Campos pendentes', 'Modelo desatualizado',
                'Veículo arquivado', 'Parecer documental da versão atual',
                'Referência manual ausente', 'Atualizada em', 'Autorização financeira',
            ], ';');

            $query->orderBy('t.id')->chunkById(100, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    $item = $this->item($row);
                    fputcsv($out, [
                        $item['id'], $item['case_id'], $this->safe($item['case_title']),
                        $this->safe($item['plate']), $this->safe($item['title']),
                        $item['revision'], $item['archived_at'] ? 'Arquivada' : 'Ativa',
                        $this->safe($item['template_name']), $item['template_revision'],
                        $item['reference_cents'], $item['conditions_filled'],
                        implode(', ', $item['missing_conditions']),
                        $item['model_outdated'] ? 'Sim' : 'Não',
                        $item['vehicle_archived'] ? 'Sim' : 'Não',
                        $this->safe($item['current_review_status']),
                        $item['reference_missing'] ? 'Sim' : 'Não',
                        $item['updated_at'], 'NÃO AUTORIZA PAGAMENTO',
                    ], ';');
                }
            }, 't.id', 'id');
            fclose($out);
        }, 'central-comercial-repasse.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
