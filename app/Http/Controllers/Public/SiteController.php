<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProposalChannel;
use App\Http\Controllers\Controller;
use App\Models\Franchise;
use App\Models\PlanVersion;
use App\Services\Proposals\ProposalService;

/**
 * Fluxo A — site institucional. Os dados comerciais vêm do mesmo catálogo versionado.
 * Até a regra de franquia de origem ser aprovada, a proposta fica no canal central (seção 27.1).
 */
class SiteController extends Controller
{
    public function plans()
    {
        $versions = PlanVersion::offerable()->with('plan', 'benefitRules')->whereNull('eligible_franchise_ids')->get()
            ->filter(fn ($v) => $v->plan->status === 'active');

        return view('public.plans', compact('versions'));
    }

    public function show(PlanVersion $version)
    {
        abort_unless($version->isOfferable() && $version->plan->status === 'active', 404);

        return view('public.plan', ['version' => $version->load('plan', 'benefitRules')]);
    }

    public function start(PlanVersion $version, ProposalService $proposals)
    {
        abort_unless($version->isOfferable(), 404);
        $proposal = $proposals->create([
            'plan_version_id' => $version->id,
            'channel' => ProposalChannel::Site->value,
        ], null, Franchise::central());

        $token = $proposals->issueLink($proposal, null);

        return redirect()->route('public.proposal', $token);
    }
}
