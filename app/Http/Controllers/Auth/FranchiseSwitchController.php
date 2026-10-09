<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Access\FranchiseContext;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

class FranchiseSwitchController extends Controller
{
    public function select(Request $request)
    {
        $memberships = $request->user()->activeMemberships()->with('franchise', 'role')->get();

        return view('auth.franchise-select', compact('memberships'));
    }

    /** A escolha é validada contra os vínculos ativos; o servidor revalida a cada requisição. */
    public function switch(Request $request, AuditLogger $audit, FranchiseContext $context)
    {
        $data = $request->validate(['franchise_id' => 'required|integer']);
        $membership = $request->user()->membershipFor((int) $data['franchise_id']);
        abort_unless($membership, 403, 'Sem vínculo ativo com esta unidade.');

        $request->session()->put(FranchiseContext::SESSION_KEY, $membership->franchise_id);
        $context->set($request->user(), $membership->franchise);
        $audit->log('auth.franchise_selected', $membership->franchise);

        return redirect()->route('dashboard');
    }
}
