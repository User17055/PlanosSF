<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\FinancialAdjustment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Access\FranchiseContext;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\InPersonPaymentService;
use App\Services\Billing\ReconciliationService;
use App\Services\Settings;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class FinancialController extends Controller
{
    public function __construct(protected FranchiseContext $ctx, protected AuditLogger $audit) {}

    public function index(Request $request)
    {
        $filter = $request->input('filter', 'overdue');
        $scoped = fn ($q) => $this->ctx->scope($q);

        $invoices = Invoice::query()->whereHas('subscription', $scoped)
            ->with('subscription.customer', 'subscription.pet', 'subscription.originatingFranchise')
            ->when($filter === 'overdue', fn ($q) => $q->overdue()->orderBy('due_date'))
            ->when($filter === 'open', fn ($q) => $q->open()->orderBy('due_date'))
            ->when($filter === 'paid', fn ($q) => $q->where('status', 'paid')->orderByDesc('paid_at'))
            ->when($filter === 'all', fn ($q) => $q->orderByDesc('due_date'))
            ->when($request->franchise_id && $this->ctx->isCentral(), fn ($q) => $q->whereHas('subscription', fn ($s) => $s->where('originating_franchise_id', $request->franchise_id)))
            ->paginate(40)->withQueryString();

        $pendingValidation = Payment::where('status', PaymentStatus::PendingValidation->value)
            ->whereHas('invoice.subscription', $scoped)
            ->with('invoice.subscription.customer', 'registeredBy')->get();

        $alerts = SubscriptionEvent::whereIn('event', ['late_payment_after_cancellation', 'payment_reversed', 'external_cancellation', 'billing_error'])
            ->whereHas('subscription', $scoped)->with('subscription.customer')->latest('recorded_at')->limit(20)->get();

        // Totais de inadimplência sem contar o mesmo cliente mais de uma vez (seção 27.7).
        $overdueAll = Invoice::overdue()->whereHas('subscription', $scoped)->with('subscription:id,customer_id')->get();
        $totals = [
            'customers' => $overdueAll->pluck('subscription.customer_id')->unique()->count(),
            'amount' => $overdueAll->sum(fn ($i) => $i->outstandingCents()),
            'invoices' => $overdueAll->count(),
        ];

        return view('financial.index', compact('invoices', 'filter', 'pendingValidation', 'alerts', 'totals'));
    }

    public function invoice(Invoice $invoice, Settings $settings)
    {
        $invoice->load('subscription.customer', 'subscription.pet', 'subscription.proposal', 'payments.registeredBy', 'payments.validatedBy', 'adjustments.requester');
        $this->authorizeInvoice($invoice);
        abort_unless(auth()->user()->can('financial.view') || auth()->user()->can('documents.upload'), 403);

        return view('financial.invoice', [
            'invoice' => $invoice,
            'inPersonEnabled' => $settings->enabled('in_person_payment.enabled'),
        ]);
    }

    /** Registro do recebimento presencial pela equipe; a validação é do financeiro. */
    public function registerPayment(Request $request, Invoice $invoice, InPersonPaymentService $service)
    {
        $this->authorizeInvoice($invoice);
        abort_unless($request->user()->can('documents.upload') || $request->user()->can('financial.confirm_in_person'), 403);
        $d = $request->validate([
            'method' => 'required|in:pix,dinheiro,cartao_maquininha,outro',
            'amount' => 'required|string|max:20',
            'paid_at' => 'required|date|before_or_equal:now',
            'reference' => 'required|string|max:120',
            'notes' => 'nullable|string|max:1000',
        ]);
        $service->register($invoice, [
            'method' => $d['method'],
            'amount_cents' => Money::parse($d['amount']),
            'paid_at' => CarbonImmutable::parse($d['paid_at'], BusinessTime::tz())->utc(),
            'reference' => $d['reference'],
            'notes' => $d['notes'] ?? null,
        ], $request->user());

        return back()->with('success', 'Recebimento registrado. Aguardando validação do financeiro para ativar o plano.');
    }

    public function validatePayment(Request $request, Payment $payment, InPersonPaymentService $service)
    {
        $this->authorizeInvoice($payment->invoice);
        $d = $request->validate(['decision' => 'required|in:approve,reject', 'reason' => 'nullable|string|max:500']);
        $service->validate($payment, $request->user(), $d['decision'] === 'approve', $d['reason'] ?? null);

        return back()->with('success', $d['decision'] === 'approve' ? 'Recebimento validado.' : 'Recebimento rejeitado.');
    }

    /** Ajuste manual exige motivo e trilha de aprovação, sem apagar o valor original. */
    public function requestAdjustment(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($invoice);
        $d = $request->validate([
            'type' => 'required|in:correcao,isencao,abatimento,renegociacao',
            'amount' => 'required|string|max:20',
            'reason' => 'required|string|max:1000',
            'evidence_reference' => 'required|string|max:255',
        ]);
        $adj = FinancialAdjustment::create([
            'invoice_id' => $invoice->id,
            'type' => $d['type'],
            'amount_cents' => Money::parse($d['amount']),
            'reason' => $d['reason'],
            'evidence_reference' => $d['evidence_reference'],
            'status' => 'requested',
            'requested_by' => $request->user()->id,
        ]);
        $this->audit->log('financial.adjustment_requested', $invoice, ['ajuste' => $adj->id, 'tipo' => $d['type']], $d['reason']);

        return back()->with('success', 'Solicitação de ajuste registrada para análise. Ela não altera a fatura nem libera o cliente até ser tratada.');
    }

    public function reconcile(Subscription $subscription, ReconciliationService $reconciliation)
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($subscription->originating_franchise_id), 403);
        $n = $reconciliation->reconcileSubscription($subscription);
        $this->audit->log('financial.reconcile_manual', $subscription, ['faturas' => $n]);

        return back()->with('success', "{$n} fatura(s) conferida(s) com o provedor.");
    }

    protected function authorizeInvoice(Invoice $invoice): void
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($invoice->subscription->originating_franchise_id), 403);
    }
}
