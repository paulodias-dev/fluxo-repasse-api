<?php

namespace App\Services;

use App\Support\TenantDB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class ControlledRepasseService
{
    public function __construct(private readonly RepasseManualCalculator $calculator) {}

    private function vehicle(int $case, bool $lock = false): object
    {
        $query = TenantDB::table('repasse_cases')->where('id', $case);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) abort(404);

        return $row;
    }

    private function participant(int $case, int $partyId): object
    {
        $row = TenantDB::table('repasse_case_parties as cp')
            ->join('parties as p', 'p.id', '=', 'cp.party_id')
            ->where('cp.repasse_case_id', $case)->where('p.id', $partyId)
            ->where('p.is_active', true)->first(['p.id', 'p.name']);
        if (! $row) {
            throw ValidationException::withMessages(['parties' => 'Use apenas pessoas ativas vinculadas a este veículo.']);
        }
        return $row;
    }

    private function settlement(int $case, int $id, bool $lock = false): object
    {
        $query = TenantDB::table('repasse_settlements')
            ->where('repasse_case_id', $case)->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) abort(404);
        return $row;
    }

    private function event(int $id, ?int $payment, int $actor, string $type, array $details = []): void
    {
        TenantDB::table('repasse_settlement_events')->insert([
            'settlement_id' => $id, 'payment_id' => $payment,
            'actor_user_id' => $actor, 'event_type' => $type,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    public function options(int $case): array
    {
        $vehicle = $this->vehicle($case);
        $parties = TenantDB::table('repasse_case_parties as cp')
            ->join('parties as p', 'p.id', '=', 'cp.party_id')
            ->where('cp.repasse_case_id', $case)->where('p.is_active', true)
            ->orderBy('p.name')->get(['p.id', 'p.name']);

        return [
            'sale_registered' => $vehicle->sold_on !== null && $vehicle->sale_amount_cents !== null,
            'sold_on' => $vehicle->sold_on,
            'parties' => $parties,
            'basis' => 'operator_entered_only',
            'bank_execution' => false,
        ];
    }

    public function index(int $case): array
    {
        $this->vehicle($case);
        $page = TenantDB::table('repasse_settlements')
            ->where('repasse_case_id', $case)->orderByDesc('id')->paginate(15);
        $page->getCollection()->transform(function (object $row) use ($case): array {
            return $this->snapshot($case, $row, false);
        });
        return $page->toArray();
    }

    private function snapshot(int $case, object $row, bool $includeHistory = true): array
    {
        $lines = TenantDB::table('repasse_settlement_lines')
            ->where('settlement_id', $row->id)->orderBy('id')->get();
        $payments = TenantDB::table('repasse_settlement_payments')
            ->where('settlement_id', $row->id)->orderByDesc('id')->get();
        $paid = [];
        foreach ($payments as $payment) {
            if ($payment->voided_at === null) {
                $paid[$payment->line_id] = ($paid[$payment->line_id] ?? 0) + (int) $payment->amount_cents;
            }
        }
        $destinations = $lines->map(static function (object $line) use ($paid, $row): array {
            $settled = $paid[$line->id] ?? 0;
            return [
                'id' => (int) $line->id, 'beneficiary_party_id' => (int) $line->beneficiary_party_id,
                'beneficiary_name' => $line->beneficiary_name,
                'kind' => $line->kind, 'mode' => $line->mode,
                'basis_points' => $line->basis_points === null ? null : (int) $line->basis_points,
                'amount_cents' => (int) $line->amount_cents,
                'paid_cents' => $settled,
                'remaining_cents' => $row->status === 'cancelled' ? 0 : (int) $line->amount_cents - $settled,
            ];
        })->all();
        $paidTotal = array_sum($paid);
        return [
            'id' => (int) $row->id, 'vehicle_id' => $case,
            'payer_party_id' => (int) $row->payer_party_id,
            'payer_name' => $row->payer_name, 'base_amount_cents' => (int) $row->base_amount_cents,
            'memo' => $row->memo, 'status' => $row->status,
            'paid_cents' => $paidTotal,
            'remaining_cents' => $row->status === 'cancelled' ? 0 : (int) $row->base_amount_cents - $paidTotal,
            'created_by' => (int) $row->created_by, 'created_at' => $row->created_at,
            'approved_by' => $row->approved_by, 'approved_at' => $row->approved_at,
            'cancel_reason' => $row->cancel_reason, 'cancelled_at' => $row->cancelled_at,
            'lines' => $destinations,
            'payments' => $includeHistory ? $payments->map(static fn (object $p): array => [
                'id' => (int) $p->id, 'line_id' => (int) $p->line_id,
                'amount_cents' => (int) $p->amount_cents, 'paid_on' => $p->paid_on,
                'external_reference' => $p->external_reference, 'proof_available' => $p->proof_path !== null,
                'reported_at' => $p->reported_at, 'reported_by' => $p->reported_by,
                'voided_at' => $p->voided_at, 'void_reason' => $p->void_reason,
                'bank_confirmed' => false,
            ])->all() : [],
            'events' => $includeHistory ? TenantDB::table('repasse_settlement_events')
                ->where('settlement_id', $row->id)->orderByDesc('id')->limit(100)->get()->all() : [],
            'events_total' => $includeHistory ? TenantDB::table('repasse_settlement_events')
                ->where('settlement_id', $row->id)->count() : 0,
            'payment_executed_by_platform' => false,
        ];
    }

    public function show(int $case, int $id): array
    {
        $this->vehicle($case);
        return $this->snapshot($case, $this->settlement($case, $id));
    }

    public function create(int $case, array $data, int $actor): array
    {
        $intent = [
            'vehicle_id' => $case, 'actor' => $actor,
            'payer_party_id' => (int) $data['payer_party_id'],
            'base_amount_cents' => (int) $data['base_amount_cents'],
            'memo' => trim((string) ($data['memo'] ?? '')) ?: null,
            'lines' => $data['lines'],
        ];
        $digest = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $result = DB::transaction(function () use ($case, $data, $actor, $intent, $digest): array {
            $vehicle = $this->vehicle($case, true);
            $previous = TenantDB::table('repasse_settlements')
                ->where('request_key', $data['request_key'])->first();
            if ($previous) {
                if ((int) $previous->repasse_case_id !== $case
                    || (int) $previous->created_by !== $actor
                    || ! hash_equals($previous->intent_sha256, $digest)) {
                    abort(409, 'Identificador já usado em outra operação.');
                }
                return ['id' => (int) $previous->id, 'created' => false];
            }
            if ($vehicle->sold_on === null || $vehicle->sale_amount_cents === null) {
                throw ValidationException::withMessages(['vehicle' => 'Registre a venda com data antes de preparar repasses.']);
            }
            $payer = $this->participant($case, $intent['payer_party_id']);
            $lines = $this->calculator->calculate($intent['base_amount_cents'], $data['lines']);
            $names = [];
            foreach ($lines as $line) {
                $names[$line['beneficiary_party_id']] =
                    $this->participant($case, $line['beneficiary_party_id'])->name;
            }
            $id = TenantDB::table('repasse_settlements')->insertGetId([
                'repasse_case_id' => $case,
                'payer_party_id' => $payer->id, 'payer_name' => $payer->name,
                'request_key' => $data['request_key'], 'intent_sha256' => $digest,
                'base_amount_cents' => $intent['base_amount_cents'], 'memo' => $intent['memo'],
                'status' => 'draft', 'created_by' => $actor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $line) {
                TenantDB::table('repasse_settlement_lines')->insert([
                    'settlement_id' => $id,
                    'beneficiary_party_id' => $line['beneficiary_party_id'],
                    'beneficiary_name' => $names[$line['beneficiary_party_id']],
                    'kind' => $line['kind'], 'mode' => $line['mode'],
                    'basis_points' => $line['basis_points'],
                    'amount_cents' => $line['amount_cents'],
                    'created_at' => now(),
                ]);
            }
            $this->event($id, null, $actor, 'created', ['base_amount_cents' => $intent['base_amount_cents']]);
            return ['id' => (int) $id, 'created' => true];
        });

        return ['data' => $this->show($case, $result['id']), 'created' => $result['created']];
    }

    public function approve(int $case, int $id, int $actor): array
    {
        DB::transaction(function () use ($case, $id, $actor): void {
            $vehicle = $this->vehicle($case, true);
            $row = $this->settlement($case, $id, true);
            if ($row->status !== 'draft') abort(409, 'Somente rascunhos podem ser aprovados.');
            if ($vehicle->sold_on === null || $vehicle->sale_amount_cents === null) {
                abort(409, 'Venda datada precisa permanecer registrada.');
            }
            $this->participant($case, (int) $row->payer_party_id);
            $lines = TenantDB::table('repasse_settlement_lines')->where('settlement_id', $id)->get();
            foreach ($lines as $line) {
                $this->participant($case, (int) $line->beneficiary_party_id);
            }
            TenantDB::table('repasse_settlements')->where('id', $id)->update([
                'status' => 'approved', 'approved_by' => $actor,
                'approved_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($id, null, $actor, 'approved');
        });
        return $this->show($case, $id);
    }

    public function pay(int $case, int $id, array $data, int $actor, ?UploadedFile $proof): array
    {
        $proofSha = $proof ? hash_file('sha256', $proof->getRealPath()) : null;
        $intent = [
            'vehicle_id' => $case, 'settlement_id' => $id, 'actor' => $actor,
            'line_id' => (int) $data['line_id'], 'amount_cents' => (int) $data['amount_cents'],
            'paid_on' => $data['paid_on'],
            'external_reference' => trim((string) ($data['external_reference'] ?? '')) ?: null,
            'proof_sha256' => $proofSha,
        ];
        $digest = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $path = null;
        try {
            $result = DB::transaction(function () use ($case, $id, $data, $actor, $proof, $proofSha, $intent, $digest, &$path): array {
                $vehicle = $this->vehicle($case, true);
                $settlement = $this->settlement($case, $id, true);
                $previous = TenantDB::table('repasse_settlement_payments')
                    ->where('request_key', $data['request_key'])->first();
                if ($previous) {
                    if ((int) $previous->settlement_id !== $id
                        || (int) $previous->reported_by !== $actor
                        || ! hash_equals($previous->intent_sha256, $digest)) {
                        abort(409, 'Identificador já utilizado em outro pagamento.');
                    }
                    return ['created' => false];
                }
                if ($settlement->status !== 'approved') {
                    abort(409, 'A distribuição precisa ser aprovada antes de registrar um pagamento.');
                }
                if ($vehicle->sold_on === null || $vehicle->sale_amount_cents === null
                    || $intent['paid_on'] < substr((string) $vehicle->sold_on, 0, 10)) {
                    throw ValidationException::withMessages([
                        'paid_on' => 'Informe um pagamento externo ocorrido na data da venda ou depois dela.',
                    ]);
                }
                $line = TenantDB::table('repasse_settlement_lines')
                    ->where('settlement_id', $id)->where('id', $intent['line_id'])->first();
                if (! $line) abort(404);
                $this->participant($case, (int) $settlement->payer_party_id);
                $this->participant($case, (int) $line->beneficiary_party_id);
                $already = (int) TenantDB::table('repasse_settlement_payments')
                    ->where('settlement_id', $id)->where('line_id', $line->id)
                    ->whereNull('voided_at')->sum('amount_cents');
                if ($intent['amount_cents'] > ((int) $line->amount_cents - $already)) {
                    throw ValidationException::withMessages(['amount_cents' => 'Valor acima do saldo restante deste beneficiário.']);
                }
                if ($proof) {
                    $mime = $proof->getMimeType();
                    $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg',
                        'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
                    if ($extension === null) throw ValidationException::withMessages(['proof' => 'Formato de arquivo não permitido.']);
                    $path = 'repasse-settlement-proofs/'.(string) $case.'/'.Str::uuid().'.'.$extension;
                    $handle = fopen($proof->getRealPath(), 'rb');
                    try {
                        if (! Storage::disk('local')->put($path, $handle)) {
                            throw ValidationException::withMessages(['proof' => 'Falha ao guardar comprovante privado.']);
                        }
                    } finally {
                        if (is_resource($handle)) fclose($handle);
                    }
                }
                $payment = TenantDB::table('repasse_settlement_payments')->insertGetId([
                    'settlement_id' => $id, 'line_id' => $line->id,
                    'request_key' => $data['request_key'], 'intent_sha256' => $digest,
                    'amount_cents' => $intent['amount_cents'],
                    'paid_on' => $intent['paid_on'], 'external_reference' => $intent['external_reference'],
                    'proof_name' => $proof ? mb_substr(
                        preg_replace('/[^\pL\pN._ -]/u', '_', basename($proof->getClientOriginalName())) ?: 'comprovante', 0, 180
                    ) : null,
                    'proof_path' => $path, 'proof_mime' => $proof?->getMimeType(),
                    'proof_size_bytes' => $proof?->getSize(), 'proof_sha256' => $proofSha,
                    'reported_by' => $actor, 'reported_at' => now(),
                ]);
                $this->event($id, $payment, $actor, 'payment_reported', [
                    'line_id' => (int) $line->id, 'amount_cents' => $intent['amount_cents'],
                ]);
                return ['created' => true];
            });
        } catch (Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
        return ['data' => $this->show($case, $id), 'created' => $result['created']];
    }

    public function voidPayment(int $case, int $id, int $payment, string $reason, int $actor): array
    {
        DB::transaction(function () use ($case, $id, $payment, $reason, $actor): void {
            $this->vehicle($case, true);
            $settlement = $this->settlement($case, $id, true);
            if ($settlement->status !== 'approved') {
                abort(409, 'Somente parcelas de distribuições aprovadas podem ser estornadas.');
            }
            $row = TenantDB::table('repasse_settlement_payments')
                ->where('settlement_id', $id)->where('id', $payment)->lockForUpdate()->first();
            if (! $row) abort(404);
            if ($row->voided_at !== null) abort(409, 'Pagamento já estornado.');
            TenantDB::table('repasse_settlement_payments')->where('id', $payment)->update([
                'voided_by' => $actor, 'voided_at' => now(), 'void_reason' => $reason,
            ]);
            $this->event($id, $payment, $actor, 'payment_voided', [
                'reason' => $reason, 'amount_cents' => (int) $row->amount_cents,
            ]);
        });
        return $this->show($case, $id);
    }

    public function cancel(
        int $case, int $id, string $reason, int $actor, bool $mayApprove
    ): array
    {
        DB::transaction(function () use ($case, $id, $reason, $actor, $mayApprove): void {
            $this->vehicle($case, true);
            $row = $this->settlement($case, $id, true);
            if ($row->status === 'cancelled') abort(409, 'Distribuição já cancelada.');
            // O autor pode retirar apenas o próprio rascunho. Uma obrigação já
            // aprovada somente pode ser cancelada por quem possui a alçada.
            if (! $mayApprove && ! ($row->status === 'draft'
                && (int) $row->created_by === $actor)) {
                abort(403, 'É necessária permissão de aprovação para cancelar esta distribuição.');
            }
            if (TenantDB::table('repasse_settlement_payments')
                ->where('settlement_id', $id)->whereNull('voided_at')->exists()) {
                abort(409, 'Estorne os pagamentos registrados antes de cancelar esta distribuição.');
            }
            TenantDB::table('repasse_settlements')->where('id', $id)->update([
                'status' => 'cancelled', 'cancelled_by' => $actor, 'cancelled_at' => now(),
                'cancel_reason' => $reason, 'updated_at' => now(),
            ]);
            $this->event($id, null, $actor, 'cancelled', ['reason' => $reason]);
        });
        return $this->show($case, $id);
    }

    public function proof(int $case, int $id, int $payment): BinaryFileResponse
    {
        $this->vehicle($case);
        $this->settlement($case, $id);
        $row = TenantDB::table('repasse_settlement_payments')
            ->where('settlement_id', $id)->where('id', $payment)->first();
        if (! $row || ! $row->proof_path) abort(404);
        $disk = Storage::disk('local');
        if (! $disk->exists($row->proof_path)) abort(409, 'Comprovante indisponível.');
        $physical = $disk->path($row->proof_path);
        if ((int) filesize($physical) !== (int) $row->proof_size_bytes ||
            ! hash_equals($row->proof_sha256, hash_file('sha256', $physical))) {
            abort(409, 'Falha de integridade do comprovante.');
        }
        return response()->download($physical, $row->proof_name ?: 'comprovante', [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
