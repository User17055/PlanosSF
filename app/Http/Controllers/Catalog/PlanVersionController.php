<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\Species;
use App\Http\Controllers\Controller;
use App\Models\ContractTemplate;
use App\Models\Franchise;
use App\Models\PlanVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\PlanCatalogService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlanVersionController extends Controller
{
    public function __construct(protected PlanCatalogService $catalog, protected AuditLogger $audit) {}

    public function show(PlanVersion $version)
    {
        $version->load('plan', 'benefitRules', 'contractTemplate');

        return view('catalog.versions.show', [
            'version' => $version,
            'templates' => ContractTemplate::orderBy('name')->orderByDesc('version')->get(),
            'franchises' => Franchise::where('is_central', false)->orderBy('name')->get(),
            'errors_publish' => $version->isDraft() ? $this->catalog->publicationErrors($version) : [],
        ]);
    }

    public function update(Request $request, PlanVersion $version)
    {
        if (! $version->isDraft()) {
            throw ValidationException::withMessages(['version' => 'Versão publicada é imutável. Crie uma nova versão.']);
        }
        $d = $request->validate([
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'price' => 'required|string|max:20',
            'enrollment_fee' => 'nullable|string|max:20',
            'billing_interval_months' => 'required|in:1,3,6,12',
            'contract_duration_months' => 'required|integer|min:1|max:120',
            'renewal_policy' => 'required|string|max:5000',
            'eligible_species' => 'required|array|min:1',
            'eligible_species.*' => [Rule::enum(Species::class)],
            'min_age_months' => 'nullable|integer|min:0|max:400',
            'max_age_months' => 'nullable|integer|min:0|max:400',
            'eligible_franchise_ids' => 'nullable|array',
            'eligible_franchise_ids.*' => 'integer|exists:franchises,id',
            'contract_template_id' => 'nullable|exists:contract_templates,id',
            'promo_price' => 'nullable|string|max:20',
            'promo_duration_months' => 'nullable|integer|min:1|max:60',
            'promo_origin' => 'nullable|string|max:255',
            'promo_valid_until' => 'nullable|date',
            'offer_starts_at' => 'nullable|date',
            'offer_ends_at' => 'nullable|date|after:offer_starts_at',
        ]);

        $version->fill([
            'display_name' => $d['display_name'],
            'description' => $d['description'] ?? null,
            'price_cents' => Money::parse($d['price']),
            'enrollment_fee_cents' => Money::parse($d['enrollment_fee'] ?? '0') ?? 0,
            'billing_interval_months' => (int) $d['billing_interval_months'],
            'contract_duration_months' => (int) $d['contract_duration_months'],
            'renewal_policy' => $d['renewal_policy'],
            'eligible_species' => array_values($d['eligible_species']),
            'min_age_months' => $d['min_age_months'] ?? null,
            'max_age_months' => $d['max_age_months'] ?? null,
            'eligible_franchise_ids' => array_map('intval', $d['eligible_franchise_ids'] ?? []) ?: null,
            'contract_template_id' => $d['contract_template_id'] ?? null,
            'promo_price_cents' => Money::parse($d['promo_price'] ?? null),
            'promo_duration_months' => $d['promo_duration_months'] ?? null,
            'promo_origin' => $d['promo_origin'] ?? null,
            'promo_valid_until' => $d['promo_valid_until'] ?? null,
            'offer_starts_at' => $d['offer_starts_at'] ?? null,
            'offer_ends_at' => $d['offer_ends_at'] ?? null,
        ]);
        if ($version->isDirty()) {
            $this->audit->log('plan_version.updated', $version, $this->audit->diff($version));
            $version->save();
        }

        return back()->with('success', 'Rascunho salvo.');
    }

    public function draftFrom(Request $request, PlanVersion $version)
    {
        $draft = $this->catalog->createDraftFrom($version, $request->user());

        return redirect()->route('versions.show', $draft)->with('success', 'Nova versão em rascunho criada a partir da v'.$version->version.'.');
    }

    public function publish(Request $request, PlanVersion $version)
    {
        $this->catalog->publish($version, $request->user());

        return back()->with('success', 'Versão publicada. A partir de agora ela é imutável.');
    }

    public function unpublish(Request $request, PlanVersion $version)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->catalog->unpublish($version, $request->user(), $data['reason']);

        return back()->with('success', 'Versão despublicada: novas vendas bloqueadas; contratos vigentes preservados.');
    }

    public function destroy(PlanVersion $version)
    {
        if (! $version->isDraft() || $version->version === 1) {
            throw ValidationException::withMessages(['version' => 'Somente rascunhos de versões posteriores podem ser descartados.']);
        }
        $plan = $version->plan;
        $version->benefitRules()->each(fn ($r) => $r->delete());
        $this->audit->log('plan_version.draft_discarded', $version);
        $version->delete();

        return redirect()->route('plans.show', $plan)->with('success', 'Rascunho descartado.');
    }
}
