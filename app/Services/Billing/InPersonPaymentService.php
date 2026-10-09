<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Access\FranchiseContext;
use App\Services\Audit\AuditLogger;
use App\Services\Financial\FinancialStatusService;
use App\Services\Settings;
use App\Services\Subscriptions\ActivationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recebimento presencial (seção 7.3): registro com referência da transação e validação do financeiro.
 * Comprovante enviado pelo cliente não libera o plano automaticamente.
 */
class InPersonPaymentService
{
    public function __construct(
        protected Settings $settings,
        protected AuditLogger $audit,
        protected FinancialStatusService $financial,
        protected ActivationService $activation,
        protected FranchiseContext $context,
    ) {}

    public function ensureEnabled(): void
    {
        if (! $this->settings->enabled('in_person_payment.enabled')) {
            throw ValidationException::withMessages(['payment' => 'Recebimento presencial depende de decisão pendente (seção 7.3). A contratação permanece aguardando confirmação financeira.']);
        }
    }

    /** @param array{method: string, amount_cents: int, paid_at: string, reference: string, notes?: ?string} $data */
    public function register(Invoice $invoice, array $data, User $user): Payment
    {
        $this->ensureEnabled();
        if ($invoice->provider !== 'manual') {
            throw ValidationException::withMessages(['payment' => 'Esta fatura é cobrada pelo provedor; não registre recebimento manual.']);
        }
        if ($invoice->status === InvoiceStatus::Paid) {
            throw ValidationException::withMessages(['payment' => 'Fatura já quitada. Não gere outra cobrança para período já pago.']);
        }

        return DB::transaction(function () use ($invoice, $data, $user) {
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'source' => 'in_person',
                'external_transaction_id' => $data['reference'],
                'method' => $data['method'],
                'amount_cents' => $data['amount_cents'],
                'paid_at' => $data['paid_at'],
                'status' => PaymentStatus::PendingValidation,
                'reference' => $data['reference'],
                'notes' => $data['notes'] ?? null,
                'receiving_franchise_id' => $this->context->id(),
                'registered_by' => $user->id,
            ]);
            $this->audit->log('payment.in_person_registered', $payment, [
                'valor' => $data['amount_cents'], 'metodo' => $data['method'], 'referencia' => $data['reference'],
            ]);

            return $payment;
        });
    }

    public function validate(Payment $payment, User $validator, bool $approve, ?string $reason): Payment
    {
        if ($payment->status !== PaymentStatus::PendingValidation) {
            throw ValidationException::withMessages(['payment' => 'Recebimento não está aguardando validação.']);
        }
        if (! $approve && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo da rejeição.']);
        }

        $invoice = DB::transaction(function () use ($payment, $validator, $approve, $reason) {
            $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $payment->forceFill([
                'status' => $approve ? PaymentStatus::Confirmed : PaymentStatus::Rejected,
                'validated_by' => $validator->id,
                'validated_at' => now(),
                'rejection_reason' => $approve ? null : $reason,
            ])->save();

            if ($approve) {
                $paid = (int) $invoice->payments()->where('status', PaymentStatus::Confirmed->value)->sum('amount_cents');
                $invoice->forceFill([
                    'paid_cents' => $paid,
                    'status' => $paid >= $invoice->amount_cents ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid,
                    'paid_at' => $paid >= $invoice->amount_cents ? ($invoice->paid_at ?? $payment->paid_at) : null,
                    'last_synced_at' => now(),
                ])->save();
            }
            $this->audit->log($approve ? 'payment.in_person_validated' : 'payment.in_person_rejected', $payment, [
                'registrado_por' => $payment->registered_by, 'validado_por' => $validator->id,
            ], $reason);

            return $invoice;
        });

        $subscription = $invoice->subscription;
        $this->financial->recompute($subscription->customer, 'recebimento presencial');
        $this->activation->tryActivate($subscription, 'Recebimento presencial validado');

        return $payment->fresh();
    }
}
