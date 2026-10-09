<?php

namespace App\Services\Catalog;

use App\Enums\CoverageType;
use App\Enums\PeriodType;
use App\Enums\PlanVersionStatus;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanCatalogService
{
    public function __construct(protected AuditLogger $audit) {}

    public function createPlan(string $code, string $name, User $user): Plan
    {
        return DB::transaction(function () use ($code, $name, $user) {
            $plan = Plan::create(['code' => strtoupper($code), 'name' => $name, 'created_by' => $user->id]);
            $plan->versions()->create([
                'version' => 1,
                'display_name' => $name,
                'status' => PlanVersionStatus::Draft,
                'created_by' => $user->id,
            ]);
            $this->audit->log('plan.created', $plan, ['code' => $plan->code, 'name' => $name]);

            return $plan;
        });
    }

    /**
     * Nova versão em rascunho copiando a anterior. Alteração de preço/cobertura sempre cria nova versão (seção 4).
     */
    public function createDraftFrom(PlanVersion $source, User $user): PlanVersion
    {
        return DB::transaction(function () use ($source, $user) {
            $plan = Plan::whereKey($source->plan_id)->lockForUpdate()->firstOrFail();
            if ($plan->versions()->where('status', PlanVersionStatus::Draft->value)->exists()) {
                throw ValidationException::withMessages(['version' => 'Já existe uma versão em rascunho para este plano.']);
            }
            $next = (int) $plan->versions()->max('version') + 1;

            $attributes = collect($source->getAttributes())->except([
                'id', 'version', 'status', 'published_at', 'published_by', 'unpublished_at', 'unpublished_by',
                'created_at', 'updated_at', 'created_by',
            ])->all();

            $draft = new PlanVersion($attributes);
            $draft->forceFill(['version' => $next, 'status' => PlanVersionStatus::Draft, 'created_by' => $user->id]);
            $draft->save();

            foreach ($source->benefitRules as $rule) {
                $draft->benefitRules()->create(collect($rule->getAttributes())->except(['id', 'plan_version_id', 'created_at', 'updated_at'])->all());
            }
            $this->audit->log('plan_version.draft_created', $draft, ['origem' => $source->version, 'versao' => $next]);

            return $draft;
        });
    }

    /** @return array<int, string> mensagens de bloqueio da publicação */
    public function publicationErrors(PlanVersion $version): array
    {
        $errors = [];
        if (! $version->isDraft()) {
            $errors[] = 'Somente versões em rascunho podem ser publicadas.';
        }
        if (! $version->price_cents || $version->price_cents <= 0) {
            $errors[] = 'Informe o preço da mensalidade.';
        }
        if (! in_array($version->billing_interval_months, [1, 3, 6, 12], true)) {
            $errors[] = 'Informe a periodicidade de cobrança.';
        }
        if (! $version->contract_duration_months) {
            $errors[] = 'Informe a duração contratual.';
        }
        if (blank($version->renewal_policy)) {
            $errors[] = 'Descreva a política de renovação.';
        }
        if (empty($version->eligible_species)) {
            $errors[] = 'Informe as espécies elegíveis.';
        }
        if (! $version->contract_template_id) {
            $errors[] = 'Associe um modelo de contrato.';
        }
        if ($version->promo_price_cents !== null && (blank($version->promo_origin) || ! $version->promo_valid_until)) {
            $errors[] = 'Promoção exige prazo e origem.';
        }
        if ($version->min_age_months !== null && $version->max_age_months !== null && $version->min_age_months > $version->max_age_months) {
            $errors[] = 'Idade mínima maior que a máxima.';
        }

        $rules = $version->benefitRules;
        if ($rules->isEmpty()) {
            $errors[] = 'Cadastre ao menos um benefício.';
        }
        foreach ($rules as $rule) {
            $label = "Benefício {$rule->service_code}";
            if ($rule->quantity_limit !== null && ! $rule->period_type) {
                $errors[] = "{$label}: limite de quantidade exige período de apuração.";
            }
            if ($rule->period_type === PeriodType::RollingWindow && ! $rule->rolling_window_days) {
                $errors[] = "{$label}: janela móvel exige quantidade de dias.";
            }
            if ($rule->coverage_type === CoverageType::Discount && ($rule->discount_percent === null || (float) $rule->discount_percent <= 0)) {
                $errors[] = "{$label}: desconto exige percentual.";
            }
            if ($rule->copay_cents !== null && (blank($rule->copay_receiver) || blank($rule->copay_stage))) {
                $errors[] = "{$label}: coparticipação exige quem recebe e em qual etapa é cobrada.";
            }
            if ($rule->group_limit !== null && blank($rule->coverage_group)) {
                $errors[] = "{$label}: limite compartilhado exige grupo de cobertura.";
            }
            if ($rule->cumulative) {
                $errors[] = "{$label}: acúmulo de saldo depende de regra específica ainda não implementada.";
            }
        }

        return $errors;
    }

    public function publish(PlanVersion $version, User $user): PlanVersion
    {
        return DB::transaction(function () use ($version, $user) {
            $version = PlanVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            $errors = $this->publicationErrors($version);
            if ($errors !== []) {
                throw ValidationException::withMessages(['publish' => $errors]);
            }

            $template = $version->contractTemplate;
            if (! $template->isLocked()) {
                $template->forceFill(['locked_at' => now(), 'status' => 'active'])->save();
            }

            $version->forceFill([
                'status' => PlanVersionStatus::Published,
                'published_at' => now(),
                'published_by' => $user->id,
                'offer_starts_at' => $version->offer_starts_at ?? now(),
            ])->save();

            $this->audit->log('plan_version.published', $version, ['versao' => $version->version]);

            return $version;
        });
    }

    /** Despublicar impede novas vendas, mas não invalida contratos vigentes. */
    public function unpublish(PlanVersion $version, User $user, string $reason): PlanVersion
    {
        if ($version->status !== PlanVersionStatus::Published) {
            throw ValidationException::withMessages(['unpublish' => 'Somente versões publicadas podem ser despublicadas.']);
        }
        $version->forceFill([
            'status' => PlanVersionStatus::Unpublished,
            'unpublished_at' => now(),
            'unpublished_by' => $user->id,
            'offer_ends_at' => $version->offer_ends_at && $version->offer_ends_at->isPast() ? $version->offer_ends_at : now(),
        ])->save();
        $this->audit->log('plan_version.unpublished', $version, null, $reason);

        return $version;
    }
}
