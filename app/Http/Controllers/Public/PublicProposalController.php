<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProposalStatus;
use App\Enums\Species;
use App\Http\Controllers\Controller;
use App\Models\ContactVerification;
use App\Models\Contract;
use App\Models\Proposal;
use App\Services\Billing\CheckoutService;
use App\Services\Contracts\ContractPreview;
use App\Services\Contracts\ContractService;
use App\Services\Proposals\ContactVerificationService;
use App\Services\Proposals\CustomerFormService;
use App\Services\Proposals\ProposalService;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Link individual e continuação do site (seções 5 e 6). O token é aleatório e sem dados pessoais;
 * valores e benefícios vêm do servidor, nunca do formulário enviado pelo navegador.
 */
class PublicProposalController extends Controller
{
    public function __construct(protected ProposalService $proposals, protected CustomerFormService $forms) {}

    public function show(Request $request, string $token, ContractPreview $preview, CheckoutService $checkout)
    {
        $proposal = $this->resolve($token);
        if (! $proposal) {
            return response()->view('public.invalid', [], 404);
        }
        if (! $proposal->link_first_opened_at) {
            $proposal->forceFill(['link_first_opened_at' => now()])->save();
        }
        $proposal->load('planVersion', 'subscription', 'contract');

        if ($proposal->contract || in_array($proposal->status, [ProposalStatus::AwaitingPayment, ProposalStatus::Completed], true)) {
            $sub = $proposal->subscription;

            return view('public.status', [
                'proposal' => $proposal,
                'token' => $token,
                'subscription' => $sub,
                'invoice' => $sub ? $checkout->initialInvoice($sub) : null,
                'finalFile' => $proposal->contract?->currentFinalFile(),
            ]);
        }

        if (! $proposal->isOpen()) {
            return response()->view('public.invalid', [], 410);
        }

        $editing = $request->boolean('editar') || $proposal->status === ProposalStatus::AwaitingFill;
        $merged = $this->forms->merged($proposal);

        if ($editing) {
            return view('public.form', compact('proposal', 'token', 'merged'));
        }

        $verificationId = $request->session()->get('otp.'.$proposal->id);
        $verification = $verificationId ? ContactVerification::find($verificationId) : null;

        return view('public.review', [
            'proposal' => $proposal,
            'token' => $token,
            'merged' => $merged,
            'missing' => $this->forms->missing($proposal),
            'contractText' => $preview->render($proposal, $merged),
            'verification' => $verification,
            'otpRequired' => config('planos.otp.required'),
        ]);
    }

    public function saveForm(Request $request, string $token)
    {
        $proposal = $this->resolveOrFail($token);
        $request->merge(['customer' => array_merge($request->input('customer', []), [
            'document' => Cpf::normalize($request->input('customer.document')),
        ])]);
        $required = $proposal->customer_id ? 'nullable' : 'required';

        $data = $request->validate([
            'customer.name' => "$required|string|max:255",
            'customer.document' => [$required, 'nullable', 'digits:11', fn ($a, $v, $f) => $v && ! Cpf::isValid($v) ? $f('CPF inválido.') : null],
            'customer.email' => "$required|nullable|email|max:255",
            'customer.phone' => "$required|nullable|string|min:10|max:30",
            'customer.zip_code' => "$required|nullable|string|max:9",
            'customer.street' => "$required|nullable|string|max:255",
            'customer.number' => "$required|nullable|string|max:20",
            'customer.complement' => 'nullable|string|max:255',
            'customer.district' => 'nullable|string|max:120',
            'customer.city' => "$required|nullable|string|max:120",
            'customer.state' => "$required|nullable|string|size:2",
            'pet.name' => ($proposal->pet_id ? 'nullable' : 'required').'|string|max:255',
            'pet.species' => [$proposal->pet_id ? 'nullable' : 'required', Rule::enum(Species::class)],
            'pet.breed' => 'nullable|string|max:120',
            'pet.sex' => 'nullable|in:macho,femea',
            'pet.birth_date' => 'nullable|date|before_or_equal:today',
            'pet.estimated_age_months' => 'nullable|integer|min:0|max:400',
        ]);
        if (isset($data['customer']['email'])) {
            $data['customer']['email'] = strtolower($data['customer']['email']);
        }
        if (isset($data['customer']['state'])) {
            $data['customer']['state'] = strtoupper($data['customer']['state']);
        }

        $this->forms->save($proposal, $data);

        return redirect()->route('public.proposal', $token)->with('success', 'Dados salvos. Revise as condições antes de aceitar.');
    }

    public function sendCode(Request $request, string $token, ContactVerificationService $otp)
    {
        $proposal = $this->resolveOrFail($token);
        $email = $this->forms->merged($proposal)['customer']['email'] ?? null;
        abort_unless($email, 422, 'Informe um e-mail nos dados do titular.');

        $verification = $otp->send('proposal_acceptance', $email, $proposal);
        $request->session()->put('otp.'.$proposal->id, $verification->id);

        return back()->with('success', 'Enviamos um código para '.$verification->destination_masked.'.');
    }

    public function verifyCode(Request $request, string $token, ContactVerificationService $otp)
    {
        $proposal = $this->resolveOrFail($token);
        $request->validate(['code' => 'required|string|max:10']);
        $verification = ContactVerification::where('proposal_id', $proposal->id)->find($request->session()->get('otp.'.$proposal->id));
        abort_unless($verification, 422, 'Solicite um novo código.');
        $otp->verify($verification, $request->input('code'));

        return back()->with('success', 'Contato confirmado.');
    }

    /** Aceite explícito do titular: o checkbox nunca vem marcado. */
    public function accept(Request $request, string $token, ContractService $contracts, ContactVerificationService $otp)
    {
        $proposal = $this->resolveOrFail($token);
        $request->validate(['terms' => 'accepted'], ['terms.accepted' => 'Para concluir, confirme que leu e concorda com o contrato.']);

        $email = $this->forms->merged($proposal)['customer']['email'] ?? '';
        $verification = $otp->verifiedFor($proposal, $email);

        $contracts->acceptDigital($proposal, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'session_id' => $request->session()->getId(),
            'affirmative_action' => 'Titular marcou "Li e concordo com o contrato" e clicou em "Aceitar e continuar para o pagamento".',
        ], $verification);

        return redirect()->route('public.proposal', $token);
    }

    /** Cópia do documento efetivamente aceito, por mecanismo temporário autorizado (link da proposta). */
    public function contract(string $token)
    {
        $proposal = $this->resolveOrFail($token, allowAccepted: true);
        $file = $proposal->contract?->currentFinalFile();
        abort_unless($file && $file->kind === Contract::KIND_FINAL_PDF, 404);

        return Storage::disk($file->disk)->download($file->storage_key, 'contrato-'.$proposal->contract->number.'.pdf', [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Leva ao pagamento hospedado no provedor, reutilizando a cobrança inicial existente. */
    public function checkout(string $token, CheckoutService $checkout)
    {
        $proposal = $this->resolveOrFail($token, allowAccepted: true);
        $sub = $proposal->subscription;
        abort_unless($sub && $sub->status->value === 'awaiting_regularization', 409, 'Não há pagamento pendente para esta proposta.');

        $invoice = $checkout->start($sub);
        if ($invoice->status->value === 'paid' || ! $invoice->payment_url) {
            return redirect()->route('public.proposal', $token);
        }

        return redirect()->away($invoice->payment_url);
    }

    /** Consulta moderada do andamento. Nunca altera estado. */
    public function status(string $token)
    {
        $proposal = $this->resolve($token);
        abort_unless($proposal, 404);
        $sub = $proposal->subscription;

        return response()->json([
            'done' => $sub && $sub->isActive(),
            'label' => $sub ? $sub->status->label() : $proposal->status->label(),
        ]);
    }

    protected function resolve(string $token): ?Proposal
    {
        $proposal = $this->proposals->findByToken($token);
        if (! $proposal || $proposal->token_revoked_at || ! $proposal->token_expires_at || $proposal->token_expires_at->isPast()) {
            return null;
        }

        return $proposal;
    }

    protected function resolveOrFail(string $token, bool $allowAccepted = false): Proposal
    {
        $proposal = $this->resolve($token);
        abort_unless($proposal, 404);
        if (! $allowAccepted) {
            abort_if($proposal->hasBeenAccepted() || ! $proposal->isOpen(), 409, 'Esta proposta não aceita mais alterações.');
        }

        return $proposal;
    }
}
