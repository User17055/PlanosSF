<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-08 / RF-09: períodos de benefício, reservas (autorizações) e lançamentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('benefit_rule_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('limit_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'benefit_rule_id', 'starts_at']);
        });

        Schema::create('authorizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('benefit_rule_id')->constrained()->restrictOnDelete();
            $table->foreignId('benefit_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('pet_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20);
            $table->timestamp('expires_at');
            $table->foreignId('servicing_franchise_id')->constrained('franchises')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 80)->unique();
            $table->string('external_procedure_id', 80)->nullable()->unique();
            $table->json('evaluation');
            $table->string('notes', 1000)->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['benefit_period_id', 'status']);
        });

        Schema::create('benefit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authorization_id')->constrained()->restrictOnDelete();
            $table->foreignId('benefit_period_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->integer('quantity');
            $table->foreignId('original_entry_id')->nullable()->constrained('benefit_ledger')->restrictOnDelete();
            $table->string('dedupe_key', 120)->unique();
            $table->foreignId('servicing_franchise_id')->constrained('franchises')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['benefit_period_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_ledger');
        Schema::dropIfExists('authorizations');
        Schema::dropIfExists('benefit_periods');
    }
};
