<?php

namespace App\Http\Middleware;

use App\Models\Franchise;
use App\Services\Access\FranchiseContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetFranchiseContext
{
    public function __construct(protected FranchiseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Acesso desativado.']);
        }

        $franchiseId = (int) $request->session()->get(FranchiseContext::SESSION_KEY);
        $membership = $franchiseId ? $user->membershipFor($franchiseId) : null;

        if (! $membership) {
            $request->session()->forget(FranchiseContext::SESSION_KEY);
            $memberships = $user->activeMemberships()->get();

            if ($memberships->count() === 1) {
                $membership = $user->membershipFor($memberships->first()->franchise_id);
                $request->session()->put(FranchiseContext::SESSION_KEY, $membership->franchise_id);
            } elseif (! $request->routeIs('franchise.select', 'franchise.switch', 'logout')) {
                return $memberships->isEmpty()
                    ? $this->denyWithoutMembership($request)
                    : redirect()->route('franchise.select');
            }
        }

        if ($membership) {
            $this->context->set($user, Franchise::findOrFail($membership->franchise_id));
        }

        return $next($request);
    }

    protected function denyWithoutMembership(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();

        return redirect()->route('login')->withErrors(['email' => 'Seu usuário não possui vínculo ativo com nenhuma unidade.']);
    }
}
