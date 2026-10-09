<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\Authorization;
use App\Models\BenefitRule;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\Access\FranchiseContext;
use App\Services\Benefits\BenefitService;
use App\Services\Benefits\EligibilityService;
use App\Services\Financial\FinancialStatusService;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Recepção: qualquer unidade identifica o cliente e consulta elegibilidade (inclusive bloqueio de outra
 * unidade), sem acesso aos contratos e financeiro detalhado de outras franquias (seção 27.2).
 */
class BenefitController extends Controller
{
    public function __construct(protected FranchiseContext $ctx) {}

    public function index(Request $request, FinancialStatusService $financial)
    {
        $q = trim((string) $request->input('q'));
        $results = collect();
        if ($q !== '') {
            $digits = Cpf::normalize($q);
            $results = Customer::query()
                ->when($digits && strlen($digits) === 11, fn ($w) => $w->where('document_normalized', $digits),
                    fn ($w) => $w->where('name', 'like', '%'.$q.'%'))
                ->with(['subscriptions' => fn ($s) => $s->whereNotIn('status', [SubscriptionStatus::Ended->value])->with('pet', 'planVersion.plan')])
                ->limit(20)->get()
                ->map(fn ($c) => ['customer' => $c, 'financial' => $financial->evaluate($c)]);
        }

        return view('benefits.index', compact('q', 'results'));
    }

    public function subscription(Subscription $subscription, EligibilityService $eligibility, FinancialStatusService $financial)
    {
        $subscription->load('customer', 'pet', 'planVersion.plan', 'planVersion.benefitRules', 'contract');
        $evaluations = $subscription->planVersion->benefitRules->map(fn (BenefitRule $rule) => [
            'rule' => $rule,
            'decision' => $eligibility->evaluate($subscription, $rule, $subscription->pet_id, $this->ctx->id()),
        ]);

        return view('benefits.subscription', [
            'subscription' => $subscription,
            'evaluations' => $evaluations,
            'financial' => $financial->evaluate($subscription->customer),
            'reservations' => Authorization::where('subscription_id', $subscription->id)->where('status', 'reserved')->with('benefitRule')->latest()->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function reserve(Request $request, Subscription $subscription, BenefitService $benefits)
    {
        $data = $request->validate([
            'benefit_rule_id' => 'required|integer',
            'quantity' => 'required|integer|min:1|max:100',
            'idempotency_key' => 'required|string|max:80|regex:/^[A-Za-z0-9-]+$/',
            'notes' => 'nullable|string|max:1000',
        ]);
        $rule = BenefitRule::where('plan_version_id', $subscription->plan_version_id)->findOrFail($data['benefit_rule_id']);

        $result = $benefits->reserve($subscription, $rule, (int) $data['quantity'], $request->user(), $this->ctx->franchise(), $data['idempotency_key'], $data['notes'] ?? null);

        if (! $result['authorization']) {
            return back()->withErrors(['benefit' => $result['decision']->message])->with('denied_code', $result['decision']->code);
        }

        return redirect()->route('benefits.show', $result['authorization'])->with('success', 'Benefício reservado. Confirme a execução após o atendimento.');
    }

    public function show(Authorization $authorization)
    {
        $authorization->load('subscription.customer', 'pet', 'benefitRule', 'user', 'servicingFranchise', 'ledger.actor', 'period');

        return view('benefits.show', ['authorization' => $authorization]);
    }

    public function consume(Request $request, Authorization $authorization, BenefitService $benefits)
    {
        $data = $request->validate(['external_procedure_id' => 'nullable|string|max:80']);
        $decision = $benefits->consume($authorization, $request->user(), $this->ctx->franchise(), $data['external_procedure_id'] ?? null);

        return $decision->allowed
            ? back()->with('success', 'Execução confirmada e consumo registrado.')
            : back()->withErrors(['benefit' => $decision->message]);
    }

    public function release(Request $request, Authorization $authorization, BenefitService $benefits)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $benefits->release($authorization, $request->user(), $data['reason']);

        return back()->with('success', 'Reserva cancelada; o saldo voltou a ficar disponível.');
    }

    public function reverse(Request $request, Authorization $authorization, BenefitService $benefits)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $benefits->reverse($authorization, $request->user(), $data['reason']);

        return back()->with('success', 'Estorno registrado como lançamento inverso vinculado ao original.');
    }
}
