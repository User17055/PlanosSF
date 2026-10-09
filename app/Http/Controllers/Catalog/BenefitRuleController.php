<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\CoverageType;
use App\Enums\PeriodType;
use App\Http\Controllers\Controller;
use App\Models\BenefitRule;
use App\Models\PlanVersion;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BenefitRuleController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function store(Request $request, PlanVersion $version)
    {
        $rule = $version->benefitRules()->create($this->validated($request, $version));
        $this->audit->log('benefit_rule.created', $rule, ['servico' => $rule->service_code]);

        return back()->with('success', 'Benefício adicionado.');
    }

    public function update(Request $request, BenefitRule $rule)
    {
        $rule->fill($this->validated($request, $rule->planVersion, $rule));
        if ($rule->isDirty()) {
            $this->audit->log('benefit_rule.updated', $rule, $this->audit->diff($rule));
            $rule->save();
        }

        return back()->with('success', 'Benefício atualizado.');
    }

    public function destroy(BenefitRule $rule)
    {
        $this->audit->log('benefit_rule.deleted', $rule, ['servico' => $rule->service_code]);
        $rule->delete();

        return back()->with('success', 'Benefício removido do rascunho.');
    }

    protected function validated(Request $request, PlanVersion $version, ?BenefitRule $rule = null): array
    {
        $d = $request->validate([
            'service_code' => ['required', 'alpha_dash', 'max:60', Rule::unique('benefit_rules')->where('plan_version_id', $version->id)->ignore($rule?->id)],
            'description' => 'required|string|max:255',
            'coverage_type' => ['required', Rule::enum(CoverageType::class)],
            'discount_percent' => 'nullable|numeric|min:0.01|max:100',
            'quantity_limit' => 'nullable|integer|min:1|max:10000',
            'unit' => 'required|string|max:40',
            'period_type' => ['nullable', Rule::enum(PeriodType::class)],
            'rolling_window_days' => 'nullable|integer|min:1|max:3650',
            'waiting_days' => 'required|integer|min:0|max:3650',
            'copay' => 'nullable|string|max:20',
            'copay_receiver' => 'nullable|string|max:255',
            'copay_stage' => 'nullable|in:antes_da_execucao,pendencia_externa',
            'monetary_limit' => 'nullable|string|max:20',
            'requires_authorization' => 'nullable|boolean',
            'min_interval_days' => 'nullable|integer|min:1|max:3650',
            'exclusions' => 'nullable|string|max:5000',
            'coverage_group' => 'nullable|alpha_dash|max:60',
            'group_limit' => 'nullable|integer|min:1|max:10000',
            'allowed_franchise_ids' => 'nullable|array',
            'allowed_franchise_ids.*' => 'integer|exists:franchises,id',
        ]);

        return [
            'service_code' => strtoupper($d['service_code']),
            'description' => $d['description'],
            'coverage_type' => $d['coverage_type'],
            'discount_percent' => $d['coverage_type'] === CoverageType::Discount->value ? ($d['discount_percent'] ?? null) : null,
            'quantity_limit' => $d['quantity_limit'] ?? null,
            'unit' => $d['unit'],
            'period_type' => $d['period_type'] ?? null,
            'rolling_window_days' => ($d['period_type'] ?? null) === PeriodType::RollingWindow->value ? ($d['rolling_window_days'] ?? null) : null,
            'waiting_days' => (int) $d['waiting_days'],
            'copay_cents' => Money::parse($d['copay'] ?? null) ?: null,
            'copay_receiver' => $d['copay_receiver'] ?? null,
            'copay_stage' => $d['copay_stage'] ?? null,
            'monetary_limit_cents' => Money::parse($d['monetary_limit'] ?? null) ?: null,
            'requires_authorization' => $request->boolean('requires_authorization', true),
            'min_interval_days' => $d['min_interval_days'] ?? null,
            'exclusions' => $d['exclusions'] ?? null,
            'coverage_group' => $d['coverage_group'] ?? null,
            'group_limit' => $d['group_limit'] ?? null,
            'allowed_franchise_ids' => array_map('intval', $d['allowed_franchise_ids'] ?? []) ?: null,
        ];
    }
}
