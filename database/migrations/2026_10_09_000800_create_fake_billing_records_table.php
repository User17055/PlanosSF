<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simulador de cobrança usado somente em homologação (PLANOS_BILLING_DRIVER=fake).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fake_billing_records', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('code', 80)->nullable();
            $table->string('parent_id', 40)->nullable();
            $table->json('data');
            $table->timestamps();
            $table->index(['type', 'code']);
            $table->index(['type', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_billing_records');
    }
};
