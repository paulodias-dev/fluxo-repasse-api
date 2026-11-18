<?php

namespace App\Services;

use Illuminate\Http\Request;
use App\Support\TenantDB;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CaseOperations
{
    public function ensureExists(int $case): object
    {
        return TenantDB::table('repasse_cases')->where('id', $case)->firstOrFail();
    }

    public function editable(int $case): object
    {
        $row = TenantDB::table('repasse_cases')->where('id', $case)->lockForUpdate()->firstOrFail();
        if ($row->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Reabra o dossiê para alterar seus registros.']);
        }

        return $row;
    }

    public function event(int $case, Request $request, string $type): void
    {
        TenantDB::table('repasse_case_events')->insert([
            'repasse_case_id' => $case,
            'actor_user_id' => $request->user('api')->getAuthIdentifier(),
            'event_type' => $type,
            'created_at' => now(),
        ]);
    }

    public function restrict(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->all()), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(
                array_fill_keys($unknown, 'Campo não permitido.')
            );
        }
    }
}
