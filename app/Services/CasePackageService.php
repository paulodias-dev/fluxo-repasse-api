<?php

namespace App\Services;

use App\Support\TenantDB;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class CasePackageService
{
    public const MAX_FILES = 40;
    public const MAX_SCENARIOS = 30;
    public const MAX_REVIEWS = 30;
    public const MAX_TOTAL_BYTES = 104857600;

    public function __construct(
        private readonly ScenarioPlanner $scenarios,
        private readonly ScenarioReviewService $reviews
    ) {}

    public function normalize(int $caseId, array $data): array
    {
        $ids = static function (array $values): array {
            $result = array_map('intval', $values);
            sort($result, SORT_NUMERIC);
            return $result;
        };

        return [
            'case_id' => $caseId,
            'title' => trim($data['title']),
            'note' => trim($data['note'] ?? '') ?: null,
            'evidence_ids' => $ids($data['evidence_ids'] ?? []),
            'scenario_ids' => $ids($data['scenario_ids'] ?? []),
            'review_ids' => $ids($data['review_ids'] ?? []),
        ];
    }

    public function requestDigest(array $normalized, int $actor, string $expectedHash): string
    {
        return hash('sha256', json_encode(
            ['selection' => $normalized, 'actor' => $actor, 'expected_manifest_sha256' => $expectedHash],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * Assemble a deterministic, read-only record for the CURRENT state. The
     * caller must provide the preview digest again when finalizing the package.
     * No bank instruction, PII-heavy party contact data, or storage paths are
     * published in the manifest.
     */
    public function assemble(int $caseId, array $normalized): array
    {
        $case = TenantDB::table('repasse_cases')->where('id', $caseId)->firstOrFail();
        $parties = TenantDB::table('repasse_case_parties as cp')
            ->join('parties as p', 'p.id', '=', 'cp.party_id')
            ->where('cp.repasse_case_id', $caseId)
            ->select('p.id', 'p.type', 'p.name', 'p.is_active', 'cp.description')
            ->orderBy('p.id')->limit(401)->get();
        $tasks = TenantDB::table('repasse_case_tasks')
            ->where('repasse_case_id', $caseId)
            ->select('id', 'title', 'details', 'status', 'priority', 'due_on', 'assigned_to',
                'completed_at', 'created_at', 'updated_at')
            ->orderBy('id')->limit(401)->get();
        $checklist = TenantDB::table('repasse_case_checklist')
            ->where('repasse_case_id', $caseId)
            ->select('id', 'label', 'checked_by', 'checked_at', 'created_at', 'updated_at')
            ->orderBy('id')->limit(401)->get();
        $notes = TenantDB::table('repasse_case_notes')
            ->where('repasse_case_id', $caseId)
            ->select('id', 'body', 'author_id', 'created_at')->orderBy('id')->limit(401)->get();

        foreach (['parties' => $parties, 'tasks' => $tasks, 'checklist' => $checklist, 'notes' => $notes] as $key => $rows) {
            if ($rows->count() > 400) $this->fail('case_id', "O dossiê tem mais de 400 registros na seção {$key}; reduza o conjunto antes de criar o pacote.");
        }

        $files = [];
        $bytes = 0;
        $evidenceIds = $normalized['evidence_ids'];
        if (count($evidenceIds) > 0) {
            $records = TenantDB::table('repasse_case_evidence')
                ->where('repasse_case_id', $caseId)
                ->whereNull('archived_at')
                ->whereIn('id', $evidenceIds)
                ->orderBy('id')->get()->keyBy('id');
            if ($records->count() !== count($evidenceIds)) {
                $this->fail('evidence_ids', 'Uma ou mais evidências não estão disponíveis ou não pertencem ao dossiê.');
            }
            foreach ($evidenceIds as $id) {
                $evidence = $records[$id];
                $bytes += (int) $evidence->size_bytes;
                if ($bytes > self::MAX_TOTAL_BYTES) $this->fail('evidence_ids', 'O pacote ultrapassou 100 MB em anexos.');
                $this->verifyFile($evidence);
                $files[] = [
                    'id' => (int) $evidence->id,
                    'name' => $evidence->original_name,
                    'mime' => $evidence->mime,
                    'size_bytes' => (int) $evidence->size_bytes,
                    'sha256' => $evidence->sha256,
                    'uploaded_by' => $evidence->uploaded_by,
                    'created_at' => $evidence->created_at,
                ];
            }
        }

        $selectedScenarios = [];
        foreach ($normalized['scenario_ids'] as $id) {
            $scenario = $this->scenarios->show($id);
            if ($scenario['case_id'] !== $caseId) $this->fail('scenario_ids', 'O cenário não pertence ao dossiê.');
            $version = $this->scenarios->versionDetail($id, $scenario['revision']);
            $selectedScenarios[] = [
                'id' => $id,
                'revision' => $scenario['revision'],
                'snapshot_sha256' => $version['sha256'],
                'snapshot' => $version['snapshot'],
            ];
        }

        $selectedReviews = [];
        foreach ($normalized['review_ids'] as $id) {
            $review = $this->reviews->show($id);
            if ($review['case_id'] !== $caseId) $this->fail('review_ids', 'A revisão não pertence ao dossiê.');
            $integrity = $this->reviews->verify($id);
            if (! $integrity['valid']) $this->fail('review_ids', 'A trilha interna de uma revisão não passou na verificação de integridade.');
            $original = $this->reviews->snapshot($id, $this->scenarios);
            $selectedReviews[] = [
                'id' => $id,
                'scenario_id' => $review['scenario_id'],
                'status' => $review['status'],
                'scenario_revision' => $review['scenario_revision'],
                'request_note' => $review['request_note'],
                'decision_note' => $review['decision_note'],
                'requested_by' => $review['requested_by'],
                'decided_by' => $review['decided_by'],
                'decided_at' => $review['decided_at'],
                'created_at' => $review['created_at'],
                'is_stale_at_capture' => $review['is_stale'],
                'last_event_hash' => $integrity['last_hash'],
                'events_checked' => $integrity['checked_events'],
                'scenario_sha256' => $original['scenario_sha256'],
            ];
        }

        return [
            'format' => 'fluxo-repasse-dossier-package-v1',
            'purpose' => 'documentary_record_only_not_payment',
            'case' => [
                'id' => (int) $case->id, 'title' => $case->title,
                'description' => $case->description, 'status' => $case->status,
                'created_at' => $case->created_at, 'updated_at' => $case->updated_at,
            ],
            'title' => $normalized['title'], 'note' => $normalized['note'],
            'parties' => $parties->map(static fn ($p): array => [
                'id' => (int) $p->id, 'type' => $p->type, 'name' => $p->name,
                'is_active' => (bool) $p->is_active, 'description' => $p->description,
            ])->all(),
            'tasks' => $tasks->map(static fn ($t): array => (array) $t)->all(),
            'checklist' => $checklist->map(static fn ($item): array => (array) $item)->all(),
            'notes' => $notes->map(static fn ($note): array => (array) $note)->all(),
            'evidence' => $files, 'scenarios' => $selectedScenarios, 'reviews' => $selectedReviews,
            'summary' => [
                'parties' => $parties->count(), 'tasks' => $tasks->count(),
                'tasks_unfinished' => $tasks->whereNotIn('status', ['done', 'cancelled'])->count(),
                'checklist_items' => $checklist->count(),
                'checklist_pending' => $checklist->whereNull('checked_at')->count(),
                'notes' => $notes->count(), 'evidence' => count($files),
                'scenarios' => count($selectedScenarios), 'reviews' => count($selectedReviews),
                'total_evidence_bytes' => $bytes,
            ],
            'financial_authorization' => false,
            'payment_enabled' => false,
        ];
    }

    private function verifyFile(object $file): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($file->storage_path)) abort(409, 'Evidência indisponível no armazenamento privado.');
        $physical = $disk->path($file->storage_path);
        if (! is_file($physical) || filesize($physical) !== (int) $file->size_bytes
            || ! hash_equals($file->sha256, hash_file('sha256', $physical))) {
            abort(409, 'Evidência alterada desde o armazenamento original.');
        }
    }

    public function preview(int $caseId, array $input): array
    {
        $normalized = $this->normalize($caseId, $input);
        $manifest = $this->assemble($caseId, $normalized);
        $serialized = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return [
            'manifest' => $manifest,
            'manifest_sha256' => hash('sha256', $serialized),
            'can_pay' => false,
        ];
    }

    public function create(int $caseId, array $input, Request $request): array
    {
        $normalized = $this->normalize($caseId, $input);
        $actor = (int) $request->user('api')->id;
        $expected = strtolower($input['expected_manifest_sha256']);
        $requestKey = strtolower($input['request_key']);
        $digest = $this->requestDigest($normalized, $actor, $expected);

        $result = DB::transaction(function () use ($caseId, $normalized, $actor, $requestKey, $digest, $expected): array {
            $previous = TenantDB::table('repasse_case_packages')
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($previous) {
                if (! hash_equals($previous->request_digest, $digest)) abort(409, 'Chave já utilizada com dados diferentes.');
                return ['id' => (int) $previous->id, 'created' => false];
            }

            $case = TenantDB::table('repasse_cases')->where('id', $caseId)->lockForUpdate()->firstOrFail();
            if ($case->status !== 'draft') $this->fail('case_id', 'O dossiê precisa estar aberto para formalizar um pacote.');

            // Refresh ALL sources under the dossier lock, then force preview equality.
            $manifest = $this->assemble($caseId, $normalized);
            $serialized = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $hash = hash('sha256', $serialized);
            if (! hash_equals($expected, $hash)) abort(409, 'O conteúdo mudou desde a prévia. Recalcule antes de registrar.');

            $inserted = TenantDB::table('repasse_case_packages')->insertOrIgnore([
                'repasse_case_id' => $caseId, 'request_key' => $requestKey,
                'request_digest' => $digest, 'title' => $normalized['title'],
                'note' => $normalized['note'], 'manifest' => $serialized,
                'manifest_sha256' => $hash,
                'evidence_count' => count($manifest['evidence']),
                'scenario_count' => count($manifest['scenarios']),
                'review_count' => count($manifest['reviews']),
                'total_evidence_bytes' => $manifest['summary']['total_evidence_bytes'],
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = TenantDB::table('repasse_case_packages')->where('request_key', $requestKey)->firstOrFail();
            if (! hash_equals($row->request_digest, $digest)) abort(409, 'Chave já utilizada com dados diferentes.');
            if ($inserted) {
                TenantDB::table('repasse_case_events')->insert([
                    'repasse_case_id' => $caseId, 'actor_user_id' => $actor,
                    'event_type' => 'case_package_created', 'created_at' => now(),
                ]);
            }
            return ['id' => (int) $row->id, 'created' => (bool) $inserted];
        }, 3);

        return ['data' => $this->show($caseId, $result['id']), 'created' => $result['created']];
    }

    private function lookup(int $caseId, int $packageId): object
    {
        return TenantDB::table('repasse_case_packages')
            ->where('repasse_case_id', $caseId)->where('id', $packageId)->firstOrFail();
    }

    public function list(int $caseId): mixed
    {
        TenantDB::table('repasse_cases')->where('id', $caseId)->firstOrFail();
        return TenantDB::table('repasse_case_packages')
            ->where('repasse_case_id', $caseId)
            ->select('id', 'repasse_case_id as case_id', 'title', 'note', 'manifest_sha256',
                'evidence_count', 'scenario_count', 'review_count', 'total_evidence_bytes',
                'created_by', 'created_at')
            ->orderByDesc('id')->paginate(20);
    }

    public function show(int $caseId, int $packageId): array
    {
        $row = $this->lookup($caseId, $packageId);
        $manifest = json_decode($row->manifest, true, 512, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if (! hash_equals($row->manifest_sha256, $hash)) abort(409, 'Integridade do manifesto não confirmada.');
        return [
            'id' => (int) $row->id, 'case_id' => (int) $row->repasse_case_id,
            'title' => $row->title, 'note' => $row->note,
            'manifest_sha256' => $row->manifest_sha256,
            'created_by' => $row->created_by, 'created_at' => $row->created_at,
            'manifest' => $manifest, 'payment_enabled' => false,
        ];
    }

    public function verify(int $caseId, int $packageId): array
    {
        $package = $this->show($caseId, $packageId);
        $files = [];
        $intact = true;
        foreach ($package['manifest']['evidence'] as $file) {
            $row = TenantDB::table('repasse_case_evidence')
                ->where('repasse_case_id', $caseId)->where('id', $file['id'])->first();
            // Archived evidence is deliberately inaccessible for download in
            // CaseEvidenceController; packages must NOT bypass that restriction.
            $valid = $row !== null && $row->archived_at === null
                && hash_equals($file['sha256'], $row->sha256)
                && (int) $file['size_bytes'] === (int) $row->size_bytes;
            if ($valid) {
                try { $this->verifyFile($row); }
                catch (\Throwable $e) { $valid = false; }
            }
            $intact = $intact && $valid;
            $files[] = ['id' => $file['id'], 'name' => $file['name'],
                'sha256' => $file['sha256'], 'available_and_intact' => $valid];
        }
        return [
            'package_id' => $packageId, 'manifest_sha256' => $package['manifest_sha256'],
            'manifest_integrity' => true,
            'evidence_integrity' => $intact,
            'available_for_download' => $intact, 'files' => $files,
            'verification_scope' => 'local_database_and_private_storage',
        ];
    }

    public function compare(int $caseId, int $leftId, int $rightId): array
    {
        $left = $this->show($caseId, $leftId);
        $right = $this->show($caseId, $rightId);
        $a = $left['manifest'];
        $b = $right['manifest'];
        $changes = [];
        foreach (['parties', 'tasks', 'checklist', 'notes', 'evidence', 'scenarios', 'reviews'] as $type) {
            $leftMap = collect($a[$type])->keyBy('id');
            $rightMap = collect($b[$type])->keyBy('id');
            $added = [];
            $removed = [];
            $modified = [];
            foreach ($rightMap as $id => $entry) {
                if (! $leftMap->has($id)) $added[] = (int) $id;
                elseif ($leftMap[$id] !== $entry) $modified[] = (int) $id;
            }
            foreach ($leftMap as $id => $entry) {
                if (! $rightMap->has($id)) $removed[] = (int) $id;
            }
            $changes[$type] = ['added_ids' => $added, 'removed_ids' => $removed, 'modified_ids' => $modified];
        }
        return [
            'left_package_id' => $leftId, 'right_package_id' => $rightId,
            'same_manifest' => hash_equals($left['manifest_sha256'], $right['manifest_sha256']),
            'case_changed' => $a['case'] !== $b['case'],
            'changes' => $changes, 'financial_authorization' => false,
        ];
    }

    /**
     * Local temporary ZIP, no public signed URL or persistent archive copy.
     * All evidence is checked again immediately before and after writing.
     */
    public function archive(int $caseId, int $packageId): string
    {
        if (! class_exists(ZipArchive::class)) abort(503, 'A extensão PHP ZIP não está disponível.');
        $package = $this->show($caseId, $packageId);
        if (! $this->verify($caseId, $packageId)['available_for_download']) {
            abort(409, 'O pacote contém arquivo ausente ou divergente; consulte a verificação de integridade.');
        }
        $file = tempnam(sys_get_temp_dir(), 'repasse_pkg_');
        if ($file === false) abort(503, 'Não foi possível preparar o arquivo temporário.');
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($file);
            abort(503, 'Não foi possível abrir o ZIP.');
        }
        try {
            $json = json_encode([
                'package_id' => $packageId, 'case_id' => $caseId,
                'manifest_sha256' => $package['manifest_sha256'],
                'created_at' => $package['created_at'],
                'manifest' => $package['manifest'],
                'notice' => 'PACOTE DOCUMENTAL; NÃO É PAGAMENTO OU APROVAÇÃO FINANCEIRA',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if (! $zip->addFromString('manifesto.json', $json)) abort(503, 'Falha ao inserir o manifesto.');
            foreach ($package['manifest']['evidence'] as $entry) {
                $row = TenantDB::table('repasse_case_evidence')
                    ->where('repasse_case_id', $caseId)->where('id', $entry['id'])->firstOrFail();
                $this->verifyFile($row);
                $safeName = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $entry['name']);
                $safeName = trim($safeName, '. ');
                $safeName = $safeName !== '' ? $safeName : 'documento';
                $entryName = 'documentos/'.$entry['id'].'-'.substr($safeName, 0, 160);
                if (! $zip->addFile(Storage::disk('local')->path($row->storage_path), $entryName)) {
                    abort(503, 'Falha ao incorporar a evidência ao ZIP.');
                }
            }
            if (! $zip->close()) abort(503, 'Falha ao finalizar ZIP.');
            // ZipArchive may read source files lazily at close(); verify again afterwards.
            if (! $this->verify($caseId, $packageId)['available_for_download']) {
                abort(409, 'Um arquivo mudou durante a preparação do pacote.');
            }
            return $file;
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($file);
            throw $e;
        }
    }
}
