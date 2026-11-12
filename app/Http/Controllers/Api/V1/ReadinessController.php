<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReadinessController extends Controller
{
    private const REQUIRED_TABLES = ['users', 'roles', 'permissions', 'role_user', 'permission_role', 'auth_security_events', 'migrations'];

    public function __invoke(): JsonResponse
    {
        try {
            // Liveness (/health) não consulta dependências. Readiness verifica o banco e a estrutura mínima.
            DB::select('SELECT 1');

            foreach (self::REQUIRED_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    return $this->response(false);
                }
            }

            return $this->response(true);
        } catch (Throwable) {
            // Não retornar exceção, nome do banco, hostname, SQL, variáveis de ambiente ou stack trace.
            return $this->response(false);
        }
    }

    private function response(bool $ready): JsonResponse
    {
        return response()->json([
            'status' => $ready ? 'ready' : 'unavailable',
            'service' => 'fluxo-repasse-api',
            'version' => 'v1',
        ], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
