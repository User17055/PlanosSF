<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-05 a RF-07, RF-14: assinaturas, faturas, pagamentos, cancelamentos e situação financeira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('pet_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('proposal_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('originating_franchise_id')->constrained('franchises')->restrictOnDelete();
            $table->string('status', 30);
            $table->date('desired_start_date')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('effective_start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('billing_provider', 20)->nullable();
            $table->string('billing_status', 30)->default('not_created');
            $table->timestamp('billing_requested_at')->nullable();
            $table->string('card_number', 30)->nullable()->unique();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('event', 60);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('data')->nullable();
            $table->string('source', 20)->default('system');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');
            $table->index(['subscription_id', 'occurred_at']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('external_id', 80)->nullable();
            $table->boolean('is_initial')->default(false);
            $table->unsignedInteger('cycle')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('due_date');
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('paid_cents')->default(0);
            $table->string('status', 30);
            $table->string('provider_status', 40)->nullable();
            $table->text('payment_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('source', 20);
            $table->string('external_transaction_id', 120)->nullable();
            $table->string('method', 30)->nullable();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('net_amount_cents')->nullable();
            $table->timestamp('paid_at');
            $table->string('status', 30);
            $table->string('provider_status', 40)->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('receiving_franchise_id')->nullable()->constrained('franchises')->nullOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_transaction_id']);
        });

        Schema::create('financial_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->integer('amount_cents');
            $table->string('reason', 1000);
            $table->string('evidence_reference', 255)->nullable();
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('reason', 1000);
            $table->string('requested_via', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_at');
            $table->date('last_covered_period_end')->nullable();
            $table->string('status', 30);
            $table->string('provider_status', 30)->default('pending');
            $table->string('provider_error', 500)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_financial_status', function (Blueprint $table) {
            $table->foreignId('customer_id')->primary()->constrained()->restrictOnDelete();
            $table->string('status', 30);
            $table->unsignedInteger('overdue_cents')->default(0);
            $table->unsignedInteger('overdue_invoices')->default(0);
            $table->date('oldest_due_date')->nullable();
            $table->timestamp('computed_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('customer_financial_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('event', 30);
            $table->string('reason', 500);
            $table->json('invoice_ids')->nullable();
            $table->string('source', 40);
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_financial_events');
        Schema::dropIfExists('customer_financial_status');
        Schema::dropIfExists('cancellation_requests');
        Schema::dropIfExists('financial_adjustments');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
    }
};
