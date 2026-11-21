<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Relações entre contas são globais por natureza, mas acessíveis somente
        // pelos dois usuários identificados pelo JWT. Não usam TenantDB.
        Schema::create('user_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_low_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('user_high_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 16)->default('pending');
            $t->boolean('favorite_low')->default(false);
            $t->boolean('favorite_high')->default(false);
            $t->timestamps();
            $t->unique(['user_low_id', 'user_high_id']);
        });

        Schema::create('vehicle_collaboration_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained('repasse_cases')->restrictOnDelete();
            $t->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('recipient_email', 255);
            $t->char('token_hash', 64)->nullable()->unique();
            $t->string('kind', 12); // share | transfer
            $t->boolean('include_history');
            $t->boolean('allow_write')->default(false);
            $t->string('status', 16)->default('pending');
            $t->timestamp('expires_at');
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['owner_tenant_id', 'vehicle_id', 'status'], 'vehicle_collab_requests_owner_idx');
            $t->index(['recipient_email', 'status'], 'vehicle_collab_requests_email_idx');
        });

        Schema::create('vehicle_access_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained('repasse_cases')->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->boolean('include_history');
            $t->boolean('allow_write');
            $t->unsignedBigInteger('entry_floor_id')->default(0);
            $t->unsignedBigInteger('document_floor_id')->default(0);
            $t->string('status', 16)->default('active');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['vehicle_id', 'recipient_id'], 'vehicle_collab_unique_recipient');
            $t->index(['recipient_id', 'status'], 'vehicle_collab_recipient_idx');
        });

        Schema::create('vehicle_ownership_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_id')->unique()->constrained('vehicle_collaboration_requests')->restrictOnDelete();
            $t->foreignId('source_tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->foreignId('source_vehicle_id')->unique()->constrained('repasse_cases')->restrictOnDelete();
            $t->foreignId('target_tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->foreignId('target_vehicle_id')->unique()->constrained('repasse_cases')->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->boolean('include_history');
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('collab_conversations', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 12); // direct | vehicle
            $t->foreignId('vehicle_id')->nullable()->constrained('repasse_cases')->restrictOnDelete();
            $t->foreignId('owner_tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $t->foreignId('connection_id')->nullable()->unique()->constrained('user_connections')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['vehicle_id', 'kind'], 'collab_vehicle_group_unique');
        });

        Schema::create('collab_conversation_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('collab_conversations')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('joined_after_message_id')->default(0);
            $t->unsignedBigInteger('last_read_message_id')->default(0);
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'user_id'], 'collab_member_unique');
        });

        Schema::create('collab_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('collab_conversations')->restrictOnDelete();
            $t->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $t->text('body')->nullable();
            $t->string('file_path', 500)->nullable()->unique();
            $t->string('file_name', 180)->nullable();
            $t->string('file_mime', 80)->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->char('file_sha256', 64)->nullable();
            $t->uuid('request_key')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['conversation_id', 'sender_id', 'request_key'], 'collab_message_dedupe');
            $t->index(['conversation_id', 'id'], 'collab_message_cursor');
        });

        Schema::create('collab_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->string('type', 40);
            $t->json('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['recipient_id', 'read_at', 'id'], 'collab_notif_unread');
        });

        Schema::table('repasse_case_financial_entries', function (Blueprint $t) {
            $t->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->uuid('collaboration_request_key')->nullable();
            $t->index(['repasse_case_id', 'paid_by_user_id'], 'case_fin_payer_index');
            $t->unique(['repasse_case_id', 'collaboration_request_key'], 'case_fin_collab_idempotency');
        });
    }

    public function down(): void
    {
        Schema::table('repasse_case_financial_entries', function (Blueprint $t) {
            $t->dropUnique('case_fin_collab_idempotency');
            $t->dropIndex('case_fin_payer_index');
            $t->dropConstrainedForeignId('paid_by_user_id');
            $t->dropColumn('collaboration_request_key');
        });
        foreach ([
            'collab_notifications', 'collab_messages', 'collab_conversation_members',
            'collab_conversations', 'vehicle_ownership_transfers', 'vehicle_access_grants',
            'vehicle_collaboration_requests', 'user_connections',
        ] as $table) Schema::dropIfExists($table);
    }
};
