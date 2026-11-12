<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AdminUserAuditController extends Controller
{
    private const EVENT_TYPES = [
        'login_succeeded', 'token_refreshed', 'logout', 'password_changed',
        'account_created', 'account_updated', 'account_email_changed',
        'account_activated', 'account_deactivated',
        'platform_admin_granted', 'platform_admin_revoked',
    ];

    public function __invoke(Request $request, User $user): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'string', Rule::in(self::EVENT_TYPES)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        $query = DB::table('auth_security_events')
            ->where('user_id', $user->getKey())
            ->select(['id', 'event_type', 'actor_user_id', 'created_at']);

        if (isset($filters['type'])) {
            $query->where('event_type', $filters['type']);
        }
        if (isset($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return response()->json([
            'user_id' => $user->getKey(),
            'events' => $query->orderByDesc('id')->paginate(15),
        ])->header('Cache-Control', 'no-store');
    }
}
