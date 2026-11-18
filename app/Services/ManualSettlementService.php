<?php

namespace App\Services;

use App\Support\TenantContext;
use App\Support\TenantDB;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ManualSettlementService
{
    public function __construct(
        private readonly CaseOperations $cases,
        private readonly DistributionCalculator $calculator,
    ) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function digest(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function vehicle(int $case, bool $lock = false): object
    {
        $query = TenantDB::table('repasse_cases')->where('id', $case);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function party(int $case, int $party): object
    {
        $row = TenantDB::table('repasse_case_parties as link')
            ->join('parties as p', 'p.id', '=', 'link.party_id')
            ->where('link.repasse_case_id', $case)
            ->where('p.tenant_id', TenantContext::id())
            ->where('p.is_active', true)->where('p.id', $party)
            ->first(['p.id', 'p.name']);
        if (! $row) {
            $this->fail('participants', 'Pagador e favorecidos devem pertencer a esta empresa e ao veículo.');
        }
        return $row;
    }

    public function options(int $case): array
    {
        $vehicle = $this->vehicle($case);
        $participants = TenantDB::table('repasse_case_parties as link')
            ->join('parties as p', 'p.id', '=', 'link.party_id')
            ->where('link.repasse_case_id', $case)
            ->where('p.tenant_id', TenantContext::id())
            ->where('p.is_active', true)->orderBy('p.name')
            ->get(['p.id', 'p.name'])
            ->map(fn (object $p): array => ['id' => (int) $p->id, 'name' => $p->name])
            ->all();
        return [
            'vehicle_id' => $case,
            'sold_on' => $vehicle->sold_on,
            'sale_registered' => $vehicle->sold_on !== null && $vehicle->sale_amount_cents !== null,
            'parties' => $participants,
            'bank_confirmed' => false,
            'payment_executed' => false,
        ];
    }

    private function snapshot(int $case, array $data): array
    {
        $payer = $this->party($case, (int) $data['payer_party_id']);
        $gross = (int) $data['gross_amount_cents'];
        $deductions = [];
        $deductionTotal = 0;
        foreach ($data['deductions'] ?? [] as $index => $row) {
            $cents = (int) $row['amount_cents'];
            $deductionTotal += $cents;
            $deductions[] = [
                'kind' => $row['kind'],
                'description' => trim($row['description']),
                'amount_cents' => $cents,
            ];
        }
        if ($deductionTotal >= $gross) {
            $this->fail('deductions', 'Deduções não podem consumir todo o valor informado.');
        }
        $net = $gross - $deductionTotal;
        $mode = $data['mode'];
        $parties = [];
        $lines = [];
        foreach ($data['lines'] as $index => $row) {
            $id = (int) $row['party_id'];
            if (isset($parties[$id])) {
                $this->fail("lines.{$index}.party_id", 'Favorecido repetido.');
            }
            $parties[$id] = $this->party($case, $id)->name;
            $line = [
                'party_id' => $id,
                'kind' => $row['kind'],
                'memo' => isset($row['memo']) ? trim($row['memo']) : null,
            ];
            if ($mode === 'percentage') {
                if (! isset($row['basis_points']) || isset($row['amount_cents'])) {
                    $this->fail("lines.{$index}", 'Neste plano informe somente percentuais.');
                }
                $line['basis_points'] = (int) $row['basis_points'];
            } else {
                if (! isset($row['amount_cents']) || isset($row['basis_points'])) {
                    $this->fail("lines.{$index}", 'Neste plano informe somente valores fixos.');
                }
                $line['amount_cents'] = (int) $row['amount_cents'];
            }
            $lines[] = $line;
        }
        if ($mode === 'percentage') {
            $calculated = $this->calculator->calculate($net, array_map(
                static fn (array $row): array => [
                    'party_id' => $row['party_id'],
                    'basis_points' => $row['basis_points'],
                    'memo' => $row['memo'],
                ], $lines
            ));
            $amounts = collect($calculated)->keyBy('party_id');
            foreach ($lines as &$line) {
                $line['amount_cents'] = (int) $amounts[$line['party_id']]['amount_cents'];
            }
            unset($line);
        } else {
            if (array_sum(array_column($lines, 'amount_cents')) !== $net) {
                $this->fail('lines', 'A soma dos valores fixos deve ser igual ao total líquido.');
            }
            foreach ($lines as &$line) $line['basis_points'] = null;
            unset($line);
        }
        foreach ($lines as &$line) {
            $line['party_name'] = $parties[$line['party_id']];
        }
        unset($line);
        usort($lines, static fn (array $a, array $b) => $a['party_id'] <=> $b['party_id']);

        return [
            'schema' => 'manual-settlement-v1',
            'case_id' => $case,
            'payer_party_id' => (int) $payer->id,
            'payer_party_name' => $payer->name,
            'gross_amount_cents' => $gross,
            'deductions' => $deductions,
            'deduction_total_cents' => $deductionTotal,
            'net_amount_cents' => $net,
            'mode' => $mode,
            'lines' => $lines,
            // Sem regra fiscal automática, retenção inferida ou execução bancária.
            'payment_executed' => false,
            'bank_confirmed' => false,
        ];
    }

    private function row(int $case, int $id, bool $lock = false): object
    {
        $query = TenantDB::table('repasse_payment_plans')
            ->where('repasse_case_id', $case)->where('id', $id);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function verified(object $row): array
    {
        $snapshot = json_decode((string) $row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($snapshot) || $this->digest($snapshot) !== $row->snapshot_sha256
            || (int) ($snapshot['case_id'] ?? 0) !== (int) $row->repasse_case_id) {
            abort(409, 'Plano financeiro com snapshot inconsistente.');
        }
        return $snapshot;
    }

    private function event(int $plan, ?int $payment, int $actor, string $type, array $details = []): void
    {
        TenantDB::table('repasse_plan_events')->insert([
            'tenant_id' => TenantContext::id(), 'plan_id' => $plan,
            'payment_id' => $payment, 'actor_user_id' => $actor,
            'event_type' => $type,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    public function create(int $case, array $data, Request $request): array
    {
        $actor = (int) $request->user('api')->id;
        $id = DB::transaction(function () use ($case, $data, $actor): int {
            $vehicle = $this->vehicle($case, true);
            if ($vehicle->sold_on === null || $vehicle->sale_amount_cents === null) {
                $this->fail('case', 'Registre a venda antes de preparar uma obrigação de repasse.');
            }
            $snapshot = $this->snapshot($case, $data);
            $sha = $this->digest($snapshot);
            $previous = DB::table('repasse_payment_plans')
                ->where('request_key', $data['request_key'])->first();
            if ($previous) {
                if ((int) $previous->tenant_id !== TenantContext::id()
                    || (int) $previous->repasse_case_id !== $case
                    || (int) $previous->created_by !== $actor
                    || ! hash_equals($previous->snapshot_sha256, $sha)) {
                    abort(409, 'Identificador já utilizado com outra operação.');
                }
                return (int) $previous->id;
            }
            if (TenantDB::table('repasse_payment_plans')->where('repasse_case_id', $case)
                ->whereIn('status', ['draft', 'approved'])->exists()) {
                $this->fail('case', 'Já existe um plano ativo. Conclua ou cancele antes de criar outro.');
            }
            $id = TenantDB::table('repasse_payment_plans')->insertGetId([
                'tenant_id' => TenantContext::id(),
                'repasse_case_id' => $case, 'request_key' => $data['request_key'],
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'snapshot_sha256' => $sha, 'status' => 'draft',
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($id, null, $actor, 'plan_prepared', ['sha256' => $sha]);
            return (int) $id;
        }, 3);
        return $this->show($case, $id);
    }

    public function approve(int $case, int $plan, string $expected, Request $request): array
    {
        DB::transaction(function () use ($case, $plan, $expected, $request): void {
            $this->vehicle($case, true);
            $row = $this->row($case, $plan, true);
            $this->verified($row);
            if ($row->status === 'approved' && hash_equals($row->snapshot_sha256, $expected)) {
                return; // Retry sem evento duplicado.
            }
            if ($row->status !== 'draft' || ! hash_equals($row->snapshot_sha256, $expected)) {
                abort(409, 'A versão do plano mudou. Atualize antes de aprovar.');
            }
            TenantDB::table('repasse_payment_plans')->where('id', $plan)
                ->update(['status' => 'approved', 'approved_by' => $request->user('api')->id,
                    'approved_at' => now(), 'updated_at' => now()]);
            $this->event($plan, null, (int) $request->user('api')->id, 'plan_approved', [
                'snapshot_sha256' => $expected,
            ]);
        }, 3);
        return $this->show($case, $plan);
    }

    public function cancel(int $case, int $plan, string $reason, Request $request): array
    {
        DB::transaction(function () use ($case, $plan, $reason, $request): void {
            $this->vehicle($case, true);
            $row = $this->row($case, $plan, true);
            $this->verified($row);
            if ($row->status === 'cancelled') {
                if ($row->cancel_reason === $reason) return;
                abort(409, 'Plano já cancelado com outra justificativa.');
            }
            if (TenantDB::table('repasse_plan_payments')->where('plan_id', $plan)
                ->whereNull('voided_at')->exists()) {
                $this->fail('reason', 'Estorne os pagamentos informados antes de cancelar este plano.');
            }
            TenantDB::table('repasse_payment_plans')->where('id', $plan)->update([
                'status' => 'cancelled', 'cancelled_by' => $request->user('api')->id,
                'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_at' => now(),
            ]);
            $this->event($plan, null, (int) $request->user('api')->id,
                'plan_cancelled', ['reason' => $reason]);
        }, 3);
        return $this->show($case, $plan);
    }

    private function verifiedPayment(object $p): void
    {
        $fingerprint = $this->digest([
            'plan_id' => (int) $p->plan_id,
            'beneficiary_party_id' => (int) $p->beneficiary_party_id,
            'amount_cents' => (int) $p->amount_cents,
            'paid_on' => $p->paid_on,
            'external_reference' => $p->external_reference,
        ]);
        if (! hash_equals($p->intent_sha256, $fingerprint)) {
            abort(409, 'Pagamento declarado com valores inconsistentes.');
        }
    }

    private function paymentRow(int $plan, int $id, bool $lock = false): object
    {
        $query = TenantDB::table('repasse_plan_payments')
            ->where('plan_id', $plan)->where('id', $id);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function fileMeta(UploadedFile $file, int $case): array
    {
        $mime = $file->getMimeType();
        $extension = match ($mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png',
            'image/jpeg' => 'jpg', 'image/webp' => 'webp',
            default => $this->fail('proof', 'Comprovante deve ser PDF ou imagem.'),
        };
        $path = 'repasse-settlement-proofs/'.TenantContext::id().'/'.$case.'/'.Str::uuid().'.'.$extension;
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $ok = Storage::disk('local')->put($path, $stream);
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
        if (! $ok) $this->fail('proof', 'Não foi possível salvar o comprovante.');
        return [
            'proof_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180),
            'proof_path' => $path,
            'proof_mime' => $mime,
            'proof_size_bytes' => $file->getSize(),
            'proof_sha256' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    public function report(int $case, int $plan, array $data, ?UploadedFile $proof, Request $request): array
    {
        $path = null;
        try {
            $id = DB::transaction(function () use ($case, $plan, $data, $proof, $request, &$path): int {
                $vehicle = $this->vehicle($case, true);
                $record = $this->row($case, $plan, true);
                $snapshot = $this->verified($record);
                $actor = (int) $request->user('api')->id;
                $intent = [
                    'plan_id' => $plan,
                    'beneficiary_party_id' => (int) $data['beneficiary_party_id'],
                    'amount_cents' => (int) $data['amount_cents'],
                    'paid_on' => $data['paid_on'],
                    'external_reference' => trim((string) ($data['external_reference'] ?? '')) ?: null,
                ];
                $sha = $this->digest($intent);
                $previous = DB::table('repasse_plan_payments')
                    ->where('request_key', $data['request_key'])->first();
                if ($previous) {
                    $this->verifiedPayment($previous);
                    if ((int) $previous->tenant_id !== TenantContext::id()
                        || (int) $previous->plan_id !== $plan
                        || (int) $previous->reported_by !== $actor
                        || ! hash_equals($previous->intent_sha256, $sha)) {
                        abort(409, 'Reenvio divergente; nenhum pagamento foi duplicado.');
                    }
                    return (int) $previous->id;
                }
                if ($record->status !== 'approved') {
                    $this->fail('plan', 'Aprove o plano antes de informar pagamentos externos.');
                }
                if ($vehicle->sold_on === null || $data['paid_on'] < $vehicle->sold_on) {
                    $this->fail('paid_on', 'A data de pagamento não pode anteceder a venda.');
                }
                $allocations = collect($snapshot['lines'])->keyBy('party_id');
                $line = $allocations[(int) $data['beneficiary_party_id']] ?? null;
                if (! $line) $this->fail('beneficiary_party_id', 'Favorecido ausente do plano aprovado.');
                $paid = (int) TenantDB::table('repasse_plan_payments')
                    ->where('plan_id', $plan)->where('beneficiary_party_id', $line['party_id'])
                    ->whereNull('voided_at')->sum('amount_cents');
                if ((int) $data['amount_cents'] > (int) $line['amount_cents'] - $paid) {
                    $this->fail('amount_cents', 'Valor excede o restante aprovado para este favorecido.');
                }
                $meta = $proof ? $this->fileMeta($proof, $case) : [];
                $path = $meta['proof_path'] ?? null;
                $id = TenantDB::table('repasse_plan_payments')->insertGetId([
                    'tenant_id' => TenantContext::id(), 'plan_id' => $plan,
                    'beneficiary_party_id' => (int) $line['party_id'],
                    'request_key' => $data['request_key'],
                    'intent_sha256' => $sha, 'amount_cents' => $data['amount_cents'],
                    'paid_on' => $data['paid_on'], 'external_reference' => $intent['external_reference'],
                    'reported_by' => $actor, 'reported_at' => now(),
                ] + $meta);
                $this->event($plan, $id, $actor, 'payment_reported', [
                    'amount_cents' => $data['amount_cents'],
                    'beneficiary_party_id' => (int) $line['party_id'],
                    'proof_attached' => $proof !== null,
                ]);
                return (int) $id;
            }); // Sem retry interno após escrever arquivo físico.
        } catch (Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
        return $this->show($case, $plan);
    }

    public function voidPayment(int $case, int $plan, int $id, string $reason, Request $request): array
    {
        DB::transaction(function () use ($case, $plan, $id, $reason, $request): void {
            $this->vehicle($case, true);
            $this->verified($this->row($case, $plan, true));
            $p = $this->paymentRow($plan, $id, true);
            $this->verifiedPayment($p);
            if ($p->voided_at !== null) {
                if ($p->void_reason === $reason) return;
                abort(409, 'Pagamento já estornado com justificativa diferente.');
            }
            TenantDB::table('repasse_plan_payments')->where('id', $id)->update([
                'voided_at' => now(), 'voided_by' => $request->user('api')->id,
                'void_reason' => $reason,
            ]);
            $this->event($plan, $id, (int) $request->user('api')->id,
                'payment_voided', ['reason' => $reason]);
        }, 3);
        return $this->show($case, $plan);
    }

    public function attachProof(int $case, int $plan, int $id, UploadedFile $proof, Request $request): array
    {
        $path = null;
        try {
            DB::transaction(function () use ($case, $plan, $id, $proof, $request, &$path): void {
                $this->vehicle($case, true);
                $this->verified($this->row($case, $plan, true));
                $row = $this->paymentRow($plan, $id, true);
                $this->verifiedPayment($row);
                if ($row->proof_sha256) {
                    if (hash_equals($row->proof_sha256, hash_file('sha256', $proof->getRealPath()))) return;
                    abort(409, 'Este pagamento já possui outro comprovante.');
                }
                if ($row->voided_at !== null) $this->fail('proof', 'Pagamento estornado não aceita anexos novos.');
                $meta = $this->fileMeta($proof, $case);
                $path = $meta['proof_path'];
                TenantDB::table('repasse_plan_payments')->where('id', $id)->update($meta);
                $this->event($plan, $id, (int) $request->user('api')->id, 'proof_attached', [
                    'sha256' => $meta['proof_sha256'],
                ]);
            });
        } catch (Throwable $e) {
            if ($path) Storage::disk('local')->delete($path);
            throw $e;
        }
        return $this->show($case, $plan);
    }

    public function proof(int $case, int $plan, int $id): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->vehicle($case);
        $this->verified($this->row($case, $plan));
        $row = $this->paymentRow($plan, $id);
        $this->verifiedPayment($row);
        if (! $row->proof_path || ! Storage::disk('local')->exists($row->proof_path)) {
            abort(404, 'Comprovante não localizado.');
        }
        $path = Storage::disk('local')->path($row->proof_path);
        if (! is_file($path) || filesize($path) !== (int) $row->proof_size_bytes
            || ! hash_equals($row->proof_sha256, hash_file('sha256', $path))) {
            abort(409, 'Comprovante com integridade divergente.');
        }
        $name = preg_replace('/[^a-zA-Z0-9._ -]/', '_', Str::ascii($row->proof_name));
        return response()->download($path, $name ?: 'comprovante', [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function index(int $case): array
    {
        $this->vehicle($case);
        return TenantDB::table('repasse_payment_plans')
            ->where('repasse_case_id', $case)->orderByDesc('id')
            ->get()->map(fn (object $p): array => $this->describe($p))->all();
    }

    public function show(int $case, int $plan): array
    {
        $this->vehicle($case);
        return $this->describe($this->row($case, $plan));
    }

    private function describe(object $row): array
    {
        $snapshot = $this->verified($row);
        $payments = TenantDB::table('repasse_plan_payments')
            ->where('plan_id', $row->id)->orderByDesc('id')->get();
        $reported = [];
        $entries = [];
        foreach ($payments as $p) {
            $this->verifiedPayment($p);
            if ($p->voided_at === null) {
                $reported[(int) $p->beneficiary_party_id] =
                    ($reported[(int) $p->beneficiary_party_id] ?? 0) + (int) $p->amount_cents;
            }
            $entries[] = [
                'id' => (int) $p->id, 'beneficiary_party_id' => (int) $p->beneficiary_party_id,
                'amount_cents' => (int) $p->amount_cents, 'paid_on' => $p->paid_on,
                'external_reference' => $p->external_reference,
                'proof_name' => $p->proof_name, 'proof_sha256' => $p->proof_sha256,
                'status' => $p->voided_at === null ? 'reported' : 'voided',
                'void_reason' => $p->void_reason, 'reported_at' => $p->reported_at,
                'bank_confirmed' => false, 'payment_executed' => false,
            ];
        }
        $totals = [];
        foreach ($snapshot['lines'] as $line) {
            $paid = $reported[$line['party_id']] ?? 0;
            $totals[] = [
                'party_id' => $line['party_id'], 'party_name' => $line['party_name'],
                'kind' => $line['kind'], 'approved_cents' => (int) $line['amount_cents'],
                'reported_cents' => $paid, 'remaining_cents' => $line['amount_cents'] - $paid,
            ];
        }
        $total = array_sum($reported);
        return [
            'id' => (int) $row->id, 'case_id' => (int) $row->repasse_case_id,
            'status' => $row->status,
            'snapshot' => $snapshot, 'snapshot_sha256' => $row->snapshot_sha256,
            'created_by' => $row->created_by, 'approved_by' => $row->approved_by,
            'approved_at' => $row->approved_at, 'cancelled_at' => $row->cancelled_at,
            'cancel_reason' => $row->cancel_reason,
            'reported_total_cents' => $total,
            'remaining_total_cents' => $snapshot['net_amount_cents'] - $total,
            'lines' => $totals, 'payments' => $entries,
            'obligation_created' => $row->status === 'approved',
            'bank_confirmed' => false, 'payment_executed' => false,
        ];
    }

    public function events(int $case, int $plan): array
    {
        $this->row($case, $plan);
        return TenantDB::table('repasse_plan_events')->where('plan_id', $plan)
            ->orderByDesc('id')->limit(100)->get()->all();
    }
}
