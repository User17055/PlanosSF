<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Benefits\EligibilityService;
use App\Services\Billing\CheckoutService;
use App\Services\Financial\FinancialStatusService;
use App\Services\Subscriptions\CancellationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Portal do cliente: somente seus dados e pets. A propriedade de cada objeto é validada em cada
 * requisição — conhecer um ID não concede acesso. Bloqueio de benefícios não impede pagar.
 */
class PortalController extends Controller
{
    public function home(Request $request, FinancialStatusService $financial)
    {
        $customer = $this->customer($request);
        $subscriptions = $customer->subscriptions()->with('pet', 'planVersion.plan')->latest()->get();
        $invoices = Invoice::whereIn('subscription_id', $subscriptions->pluck('id'))
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::PartiallyPaid->value])
            ->orderBy('due_date')->get();

        return view('portal.home', [
            'customer' => $customer,
            'subscriptions' => $subscriptions,
            'openInvoices' => $invoices,
            'financial' => $financial->evaluate($customer),
        ]);
    }

    public function subscription(Request $request, Subscription $subscription, EligibilityService $eligibility)
    {
        $this->own($request, $subscription);
        $subscription->load('pet', 'planVersion.plan', 'planVersion.benefitRules', 'contract', 'invoices', 'cancellationRequests', 'authorizations.benefitRule');
        $benefits = $subscription->planVersion->benefitRules->map(fn ($r) => [
            'rule' => $r,
            'decision' => $eligibility->evaluate($subscription, $r, $subscription->pet_id, $subscription->originating_franchise_id),
        ]);

        return view('portal.subscription', compact('subscription', 'benefits'));
    }

    public function contract(Request $request, Subscription $subscription, AuditLogger $audit)
    {
        $this->own($request, $subscription);
        $file = $subscription->contract->currentFinalFile();
        abort_unless($file && $file->review_status->value === 'approved', 404);
        $audit->log('portal.contract_downloaded', $subscription->contract, null, null, 'customer');
        $ext = pathinfo($file->storage_key, PATHINFO_EXTENSION);

        return Storage::disk($file->disk)->download($file->storage_key, 'contrato-'.$subscription->contract->number.'.'.$ext, ['Cache-Control' => 'no-store, private']);
    }

    /** Usa o link da própria fatura, sem criar cobrança duplicada. */
    public function pay(Request $request, Invoice $invoice, CheckoutService $checkout)
    {
        $this->own($request, $invoice->subscription);
        abort_if($invoice->status === InvoiceStatus::Paid, 409, 'Fatura já paga.');
        if (! $invoice->payment_url && $invoice->is_initial && $invoice->provider !== 'manual') {
            $invoice = $checkout->start($invoice->subscription);
        }
        abort_unless($invoice->payment_url, 409, 'Esta parcela é paga na unidade. Procure a unidade responsável.');

        return redirect()->away($invoice->payment_url);
    }

    public function requestCancellation(Request $request, Subscription $subscription, CancellationService $cancellation)
    {
        $this->own($request, $subscription);
        $d = $request->validate(['reason' => 'required|string|max:1000', 'type' => 'required|in:immediate,end_of_period']);
        abort_if($subscription->cancellationRequests()->whereIn('status', ['requested', 'scheduled'])->exists(), 409, 'Já existe uma solicitação em andamento.');
        $cancellation->request($subscription, $d['type'], $d['reason'], null, 'customer');

        return back()->with('success', 'Solicitação de cancelamento registrada. A unidade confirmará o encerramento e as próximas cobranças.');
    }

    /** Atualização de contato pelo titular (e-mail de acesso é alterado somente pela unidade). */
    public function updateContact(Request $request, AuditLogger $audit)
    {
        $customer = $this->customer($request);
        $d = $request->validate([
            'phone' => 'required|string|min:10|max:30',
            'zip_code' => 'required|string|max:9',
            'street' => 'required|string|max:255',
            'number' => 'required|string|max:20',
            'complement' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:120',
            'city' => 'required|string|max:120',
            'state' => 'required|string|size:2',
        ]);
        $customer->fill($d + ['state' => strtoupper($d['state'])]);
        if ($customer->isDirty()) {
            $audit->log('customer.updated_by_customer', $customer, $audit->diff($customer), 'Portal do cliente', 'customer');
            $customer->save();
        }

        return back()->with('success', 'Dados atualizados.');
    }

    protected function customer(Request $request): Customer
    {
        return $request->attributes->get('portal_customer');
    }

    protected function own(Request $request, Subscription $subscription): void
    {
        abort_unless($subscription->customer_id === $this->customer($request)->id, 404);
    }
}
