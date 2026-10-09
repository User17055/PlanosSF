<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-03 / RF-04: propostas, links, contratos, arquivos e evidências de aceite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('pet_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('fill_mode', 20)->default('seller');
            $table->string('signature_method', 20);
            $table->string('status', 30);
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('originating_franchise_id')->constrained('franchises')->restrictOnDelete();
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('enrollment_fee_cents')->default(0);
            $table->json('conditions_snapshot');
            $table->json('form_data')->nullable();
            $table->date('desired_start_date')->nullable();
            $table->timestamp('expires_at');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_revoked_at')->nullable();
            $table->foreignId('token_revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('link_sent_channel', 20)->nullable();
            $table->timestamp('link_sent_at')->nullable();
            $table->foreignId('link_sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('link_first_opened_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('proposals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['pet_id', 'plan_version_id']);
        });

        Schema::create('contact_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 30);
            $table->foreignId('proposal_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('channel', 10);
            $table->string('destination_masked', 120);
            $table->string('destination_hash', 64);
            $table->string('code_hash', 255);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['destination_hash', 'created_at']);
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->unique()->constrained()->restrictOnDelete();
            $table->string('number', 30)->unique();
            $table->string('signature_method', 20);
            $table->string('document_status', 30);
            $table->longText('body_text');
            $table->json('frozen_data');
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('pdf_failed_at')->nullable();
            $table->string('pdf_error', 500)->nullable();
            $table->timestamps();
            $table->index('document_status');
        });

        Schema::create('contract_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->unsignedInteger('version');
            $table->string('disk', 30);
            $table->string('storage_key');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->string('mime', 80);
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->string('review_status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('contract_files')->nullOnDelete();
            $table->string('replacement_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['contract_id', 'kind', 'version']);
        });

        Schema::create('acceptance_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->string('method', 20);
            $table->string('terms_version', 60);
            $table->string('affirmative_action', 500);
            $table->string('session_hash', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->foreignId('contact_verification_id')->nullable()->constrained()->nullOnDelete();
            $table->string('verification_result', 20)->nullable();
            $table->string('signature_image_key')->nullable();
            $table->string('signature_image_sha256', 64)->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamps();
        });

        Schema::create('customer_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable();
            $table->string('kind', 30);
            $table->string('disk', 30);
            $table->string('storage_key');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->string('mime', 80);
            $table->string('description', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_attachments');
        Schema::dropIfExists('acceptance_evidence');
        Schema::dropIfExists('contract_files');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('contact_verifications');
        Schema::dropIfExists('proposals');
    }
};
