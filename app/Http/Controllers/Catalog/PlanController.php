<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\PlanCatalogService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::with(['versions' => fn ($q) => $q->withCount('benefitRules')])->orderBy('name')->get();

        return view('catalog.plans.index', compact('plans'));
    }

    public function create()
    {
        return view('catalog.plans.create');
    }

    public function store(Request $request, PlanCatalogService $catalog)
    {
        $data = $request->validate([
            'code' => 'required|alpha_dash|max:40|unique:plans,code',
            'name' => 'required|string|max:255',
        ]);
        $plan = $catalog->createPlan($data['code'], $data['name'], $request->user());

        return redirect()->route('versions.show', $plan->versions()->first())->with('success', 'Plano criado com a versão 1 em rascunho.');
    }

    public function show(Plan $plan)
    {
        $plan->load(['versions' => fn ($q) => $q->withCount('benefitRules')]);

        return view('catalog.plans.show', compact('plan'));
    }

    /** Inativação administrativa: exclusão física de plano referenciado é bloqueada. */
    public function toggleStatus(Plan $plan, AuditLogger $audit)
    {
        $plan->status = $plan->status === 'active' ? 'inactive' : 'active';
        $audit->log('plan.status_changed', $plan, $audit->diff($plan));
        $plan->save();

        return back()->with('success', 'Situação do plano atualizada.');
    }
}
