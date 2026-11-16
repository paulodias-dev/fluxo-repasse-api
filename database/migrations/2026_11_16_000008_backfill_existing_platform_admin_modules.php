<?php

use App\Support\OperationalAccessCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Safe one-time data backfill. ONLY existing members of the already
     * provisioned platform_admin role receive new technical module access.
     * No user or role is created or automatically promoted by this migration.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $roleId = DB::table('roles')->where('slug', 'platform_admin')
                ->lockForUpdate()->value('id');
            if (! $roleId) return;

            $now = now();
            foreach (OperationalAccessCatalog::TECHNICAL_PERMISSIONS as $key => $description) {
                DB::table('permissions')->insertOrIgnore([
                    'key' => $key, 'description' => $description,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                // Preserve original created_at when the permission already exists.
                DB::table('permissions')->where('key', $key)
                    ->update(['description' => $description, 'updated_at' => $now]);
            }

            $ids = DB::table('permissions')
                ->whereIn('key', OperationalAccessCatalog::keys())->pluck('id')->all();
            $existing = DB::table('permission_role')->where('role_id', $roleId)
                ->pluck('permission_id')->all();
            $missing = array_values(array_diff($ids, $existing));
            if ($missing === []) return;

            foreach ($missing as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permissionId,
                ]);
            }

            // New permissions must not silently elevate an already issued JWT.
            // Any existing role member must authenticate again.
            $userIds = DB::table('role_user')->where('role_id', $roleId)->pluck('user_id');
            if ($userIds->isNotEmpty()) {
                DB::table('users')->whereIn('id', $userIds)->increment('auth_version');
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-revoking: rolling back application code must never
        // silently remove privileges that might have been granted independently.
        // Use the explicit administrator-revocation command after approval.
    }
};
