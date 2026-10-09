<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RF-01: tutores (identidade central da rede) e pets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('document_type', 10)->default('cpf');
            $table->string('document_normalized', 20)->nullable()->unique();
            $table->string('email')->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('zip_code', 9)->nullable();
            $table->string('street')->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement')->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('marketing_opt_in_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_franchise_id')->nullable()->constrained('franchises')->nullOnDelete();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('species', 20);
            $table->string('breed', 120)->nullable();
            $table->string('sex', 10)->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedSmallInteger('estimated_age_months')->nullable();
            $table->date('estimated_age_reference_date')->nullable();
            $table->string('identifier', 60)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pets');
        Schema::dropIfExists('customers');
    }
};
