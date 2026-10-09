<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-02: catálogo versionado de planos, benefícios e modelos de contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('version');
            $table->longText('body');
            $table->string('status', 20)->default('draft');
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['name', 'version']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('price_cents')->nullable();
            $table->unsignedInteger('enrollment_fee_cents')->default(0);
            $table->unsignedTinyInteger('billing_interval_months')->nullable();
            $table->unsignedSmallInteger('contract_duration_months')->nullable();
            $table->text('renewal_policy')->nullable();
            $table->json('eligible_species')->nullable();
            $table->unsignedSmallInteger('min_age_months')->nullable();
            $table->unsignedSmallInteger('max_age_months')->nullable();
            $table->json('eligible_franchise_ids')->nullable();
            $table->foreignId('contract_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('promo_price_cents')->nullable();
            $table->unsignedTinyInteger('promo_duration_months')->nullable();
            $table->string('promo_origin')->nullable();
            $table->timestamp('promo_valid_until')->nullable();
            $table->timestamp('offer_starts_at')->nullable();
            $table->timestamp('offer_ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unpublished_at')->nullable();
            $table->foreignId('unpublished_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['plan_id', 'version']);
            $table->index('status');
        });

        Schema::create('benefit_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->string('service_code', 60);
            $table->string('description');
            $table->string('coverage_type', 20);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->unsignedInteger('quantity_limit')->nullable();
            $table->string('unit', 40)->default('unidade');
            $table->string('period_type', 30)->nullable();
            $table->unsignedSmallInteger('rolling_window_days')->nullable();
            $table->unsignedSmallInteger('waiting_days')->default(0);
            $table->unsignedInteger('copay_cents')->nullable();
            $table->string('copay_receiver')->nullable();
            $table->string('copay_stage', 30)->nullable();
            $table->unsignedInteger('monetary_limit_cents')->nullable();
            $table->boolean('requires_authorization')->default(true);
            $table->unsignedSmallInteger('min_interval_days')->nullable();
            $table->text('exclusions')->nullable();
            $table->string('coverage_group', 60)->nullable();
            $table->unsignedInteger('group_limit')->nullable();
            $table->json('allowed_franchise_ids')->nullable();
            $table->boolean('cumulative')->default(false);
            $table->timestamps();
            $table->unique(['plan_version_id', 'service_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_rules');
        Schema::dropIfExists('plan_versions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('contract_templates');
    }
};
