<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-10 / RF-12: mapeamento de IDs externos, outbox de integrações e caixa de entrada de webhooks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('system', 20);
            $table->string('account_scope', 60)->default('default');
            $table->string('local_type', 40);
            $table->unsignedBigInteger('local_id');
            $table->string('external_id', 120);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['system', 'account_scope', 'local_type', 'local_id'], 'ext_map_local_unique');
            $table->unique(['system', 'account_scope', 'local_type', 'external_id'], 'ext_map_external_unique');
        });

        Schema::create('integration_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('destination', 20);
            $table->string('operation', 60);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('operation_key', 160)->unique();
            $table->string('mode', 20);
            $table->string('status', 30);
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(8);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->string('error_kind', 20)->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('completion_note', 1000)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('webhook_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id', 120)->nullable();
            $table->string('event_type', 80);
            $table->string('payload_hash', 64);
            $table->longText('payload');
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('received_at');
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->string('result', 1000)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
            $table->unique(['provider', 'payload_hash']);
            $table->index(['status', 'received_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120);
            $table->string('scope', 120);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'key']);
        });

        Schema::create('scheduler_heartbeats', function (Blueprint $table) {
            $table->string('name', 80)->primary();
            $table->timestamp('last_run_at');
            $table->string('last_result', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('webhook_inbox');
        Schema::dropIfExists('integration_jobs');
        Schema::dropIfExists('external_mappings');
    }
};
