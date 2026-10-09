<?php

namespace App\Http\Controllers;

use App\Models\Authorization;
use App\Models\CancellationRequest;
use App\Models\Subscription;
use App\Services\Access\FranchiseContext;
use App\Services\Benefits\EligibilityService;
use App\Services\Benefits\PeriodCalculator;
use App\Services\Billing\CheckoutService;
use App\Services\Subscriptions\ActivationService;
use App\Services\Subscriptions\CancellationService;
use App\Support\Money;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(protected FranchiseContext $ctx) {}

    public function index(Request $request)
    {
        $subscriptions = $this->ctx->scope(Subscription::query())
            ->with('customer', 'pet', 'planVersion.plan', 'originatingFranchise')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(30)->withQueryString();

        return view('subscriptions.index', compact('subscriptions'));
    }

    /**
     * Perfil da assinatura: a equipe não precisa abrir o PDF para saber se uma consulta está disponível.
     */
    public function show(Subscription $subscription, ActivationService $activation, EligibilityService $eligibility, PeriodCalculator $periods, CancellationService $cancellation)
    {
        $this->authorizeRecord($subscription);
        $subscription->load('customer', 'pet', 'planVersion.plan', 'planVersion.benefitRules', 'contract.files', 'proposal', 'invoices.payments', 'events.user', 'cancellationRequests.requester', 'originatingFranchise');

        $benefits = $subscription->planVersion->benefitRules->map(function ($rule) use ($subscription, $eligibility, $periods) {
            $decision = $eligibility->evaluate($subscription, $rule, $subscription->pet_id, $this->ctx->id());

            return [
                'rule' => $rule,
                'decision' => $decision,
                'available_from' => $subscription->effective_start_at ? $periods->availableFrom($rule, $subscription->effective_start_at) : null,
            ];
        });

        return view('subscriptions.show', [
            'subscription' => $subscription,
            'impediments' => $activation->impediments($subscription),
            'benefits' => $benefits,
            'authorizations' => Authorization::where('subscription_id', $subscription->id)->with('benefitRule', 'user', 'servicingFranchise', 'ledger')->latest()->limit(30)->get(),
            'cancelSummary' => $subscription->isTerminal() ? null : $cancellation->summary($subscription),
        ]);
    }

    /** Gera (ou recupera) a cobrança inicial sem duplicar a assinatura no provedor. */
    public function checkout(Subscription $subscription, CheckoutService $checkout)
    {
        $this->authorizeRecord($subscription);
        $invoice = $checkout->start($subscription);

        return back()->with('success', 'Cobrança inicial disponível: '.Money::format($invoice->amount_cents).'.');
    }

    public function cancel(Request $request, Subscription $subscription, CancellationService $cancellation)
    {
        $this->authorizeRecord($subscription);
        $data = $request->validate([
            'type' => 'required|in:immediate,end_of_period',
            'reason' => 'required|string|max:1000',
            'confirm' => 'accepted',
        ], ['confirm.accepted' => 'Confirme que revisou débitos, período pago e cobranças futuras.']);
        $cancellation->request($subscription, $data['type'], $data['reason'], $request->user());

        return back()->with('success', $data['type'] === 'immediate' ? 'Assinatura cancelada.' : 'Encerramento agendado para o fim do período pago.');
    }

    public function approveCancellation(Request $request, CancellationRequest $cancellation, CancellationService $service)
    {
        $this->authorizeRecord($cancellation->subscription);
        $service->approveCustomerRequest($cancellation, $request->user());

        return back()->with('success', 'Solicitação de cancelamento aprovada.');
    }

    protected function authorizeRecord(Subscription $subscription): void
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($subscription->originating_franchise_id), 403);
    }
}
