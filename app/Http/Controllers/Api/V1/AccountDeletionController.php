<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AccountDeletionRequest;
use App\Services\AuthEventRecorder;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
final class AccountDeletionController extends Controller
{
    private function response(): JsonResponse
    {
        $row = DB::table('account_deletion_requests')->where('tenant_id', TenantContext::requireId())
            ->where('user_id', Auth::guard('api')->id())->first(['status','requested_at','cancelled_at']);
        return response()->json(['data' => $row])->header('Cache-Control', 'no-store, private');
    }
    public function show(): JsonResponse { return $this->response(); }
    public function store(AccountDeletionRequest $request, AuthEventRecorder $events): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $tenant = TenantContext::requireId();
        DB::transaction(function () use ($user, $tenant, $request, $events): void {
            DB::table('users')->where('id', $user->id)->where('tenant_id', $tenant)->lockForUpdate()->firstOrFail();
            $row = DB::table('account_deletion_requests')->where('tenant_id', $tenant)->where('user_id', $user->id)->first();
            if ($row && $row->status === 'pending') { return; }
            abort_if($row && $row->status !== 'cancelled', 409, 'Solicitação já em tratamento.');
            DB::table('account_deletion_requests')->updateOrInsert(['tenant_id' => $tenant, 'user_id' => $user->id], [
                'status' => 'pending', 'requested_at' => now(), 'cancelled_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $events->record('account_deletion_requested', $request, $user);
        }, 3);
        return $this->response();
    }
    public function cancel(Request $request, AuthEventRecorder $events): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $tenant = TenantContext::requireId();
        DB::transaction(function () use ($user, $tenant, $request, $events): void {
            DB::table('users')->where('id', $user->id)->where('tenant_id', $tenant)->lockForUpdate()->firstOrFail();
            $changed = DB::table('account_deletion_requests')->where('tenant_id', $tenant)->where('user_id', $user->id)
                ->where('status', 'pending')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
            if ($changed) { $events->record('account_deletion_cancelled', $request, $user); }
        }, 3);
        return $this->response();
    }
}
