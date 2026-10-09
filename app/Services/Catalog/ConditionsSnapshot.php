<?php

namespace App\Services\Catalog;

use App\Models\PlanVersion;

/**
 * Fotografia das condições comerciais fixada na proposta: o aceite preserva a condição
 * aceita sem ser substituído pelo catálogo atual (seção 5 — Abandono e retomada).
 */
class ConditionsSnapshot
{
    /** @return array<string, mixed> */
    public function build(PlanVersion $version): array
    {
        $version->loadMissing('plan', 'benefitRules', 'contractTemplate');

        return [
            'plan' => [
                'id' => $version->plan->id,
                'code' => $version->plan->code,
                'name' => $version->plan->name,
            ],
            'version' => [
                'id' => $version->id,
                'number' => $version->version,
                'display_name' => $version->display_name,
                'description' => $version->description,
                'published_at' => $version->published_at?->toIso8601String(),
            ],
            'price_cents' => $version->effectivePriceCents(),
            'base_price_cents' => $version->price_cents,
            'enrollment_fee_cents' => $version->enrollment_fee_cents,
            'promo' => $version->promo_price_cents !== null ? [
                'price_cents' => $version->promo_price_cents,
                'duration_months' => $version->promo_duration_months,
                'origin' => $version->promo_origin,
                'valid_until' => $version->promo_valid_until?->toIso8601String(),
            ] : null,
            'billing_interval_months' => $version->billing_interval_months,
            'contract_duration_months' => $version->contract_duration_months,
            'renewal_policy' => $version->renewal_policy,
            'eligible_species' => $version->eligible_species,
            'min_age_months' => $version->min_age_months,
            'max_age_months' => $version->max_age_months,
            'benefits' => $version->benefitRules->map(fn ($r) => [
                'id' => $r->id,
                'service_code' => $r->service_code,
                'description' => $r->description,
                'coverage_type' => $r->coverage_type->value,
                'coverage_label' => $r->coverage_type->label(),
                'discount_percent' => $r->discount_percent,
                'quantity_limit' => $r->quantity_limit,
                'unit' => $r->unit,
                'period_type' => $r->period_type?->value,
                'period_label' => $r->period_type?->label(),
                'rolling_window_days' => $r->rolling_window_days,
                'waiting_days' => $r->waiting_days,
                'copay_cents' => $r->copay_cents,
                'copay_receiver' => $r->copay_receiver,
                'copay_stage' => $r->copay_stage,
                'monetary_limit_cents' => $r->monetary_limit_cents,
                'min_interval_days' => $r->min_interval_days,
                'requires_authorization' => $r->requires_authorization,
                'coverage_group' => $r->coverage_group,
                'group_limit' => $r->group_limit,
                'exclusions' => $r->exclusions,
            ])->all(),
            'contract_template' => [
                'id' => $version->contractTemplate?->id,
                'name' => $version->contractTemplate?->name,
                'version' => $version->contractTemplate?->version,
                'body' => $version->contractTemplate?->body,
            ],
        ];
    }
}
