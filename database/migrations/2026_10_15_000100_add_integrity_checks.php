<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restrições CHECK no banco: estados só com os valores dos enums e lançamentos de benefício coerentes.
 * As listas são uma foto dos enums em 15/10/2026: novo valor de enum exige migration nova (o teste
 * IntegrityChecksTest acusa a divergência). Só MySQL; o SQLite dos testes rápidos não altera CHECK.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> */
    protected array $allowed = [
        'proposals' => [
            'status' => ['created', 'awaiting_fill', 'awaiting_acceptance', 'awaiting_document', 'awaiting_payment', 'completed', 'expired', 'revoked', 'canceled'],
            'channel' => ['site', 'link', 'in_person'],
            'fill_mode' => ['seller', 'customer'],
            'signature_method' => ['digital', 'tablet', 'paper'],
        ],
        'contracts' => [
            'signature_method' => ['digital', 'tablet', 'paper'],
            'document_status' => ['pending', 'awaiting_signature', 'awaiting_review', 'valid', 'rejected'],
        ],
        'contract_files' => [
            'review_status' => ['pending', 'approved', 'rejected', 'superseded'],
        ],
        'plan_versions' => [
            'status' => ['draft', 'published', 'unpublished'],
        ],
        'benefit_rules' => [
            'coverage_type' => ['included', 'discount'],
            'period_type' => ['calendar_month', 'monthly_cycle', 'contract_year', 'rolling_window', 'whole_term'],
        ],
        'pets' => [
            'species' => ['dog', 'cat', 'other'],
        ],
        'subscriptions' => [
            'status' => ['draft', 'awaiting_regularization', 'active', 'suspended', 'cancellation_scheduled', 'canceled', 'ended'],
        ],
        'invoices' => [
            'status' => ['pending', 'paid', 'partially_paid', 'canceled', 'refunded', 'disputed'],
        ],
        'payments' => [
            'status' => ['pending_validation', 'confirmed', 'rejected', 'refunded', 'disputed'],
        ],
        'customer_financial_status' => [
            'status' => ['regular', 'delinquent', 'pending_verification'],
        ],
        'authorizations' => [
            'status' => ['reserved', 'consumed', 'released', 'expired'],
        ],
        'integration_jobs' => [
            'status' => ['pending', 'processing', 'synced', 'error', 'needs_intervention'],
        ],
    ];

    /** @var array<string, array<string, string>> */
    protected array $rules = [
        'authorizations' => [
            'chk_authorizations_quantity' => 'quantity > 0',
        ],
        'benefit_ledger' => [
            // Consumo é positivo e sem origem; estorno é negativo e aponta o consumo estornado.
            'chk_benefit_ledger_entry' => "(type = 'consume' AND quantity > 0 AND original_entry_id IS NULL)"
                ." OR (type = 'reversal' AND quantity < 0 AND original_entry_id IS NOT NULL)",
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->constraints() as [$table, $name, $expression]) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->constraints() as [$table, $name]) {
            DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$name}`");
        }
    }

    /** @return list<array{string, string, string}> */
    protected function constraints(): array
    {
        $list = [];
        foreach ($this->allowed as $table => $columns) {
            foreach ($columns as $column => $values) {
                $in = implode(', ', array_map(fn ($v) => "'{$v}'", $values));
                $list[] = [$table, "chk_{$table}_{$column}", "`{$column}` IN ({$in})"];
            }
        }
        foreach ($this->rules as $table => $rules) {
            foreach ($rules as $name => $expression) {
                $list[] = [$table, $name, $expression];
            }
        }

        return $list;
    }
};
