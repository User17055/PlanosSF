<?php

namespace App\Http\Controllers;

use App\Enums\ProposalChannel;
use App\Enums\ProposalStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PlanVersion;
use App\Models\Proposal;
use App\Services\Access\FranchiseContext;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\CheckoutService;
use App\Services\Proposals\CustomerFormService;
use App\Services\Proposals\ProposalService;
use App\Services\Settings;
use App\Services\Subscriptions\ActivationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProposalController extends Controller
{
    public function __construct(protected ProposalService $proposals, protected FranchiseContext $ctx, protected AuditLogger $audit) {}

    public function index(Request $request)
    {
        $proposals = $this->ctx->scope(Proposal::query())
            ->with('customer', 'pet', 'planVersion.plan', 'seller', 'originatingFranchise')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->channel, fn ($q, $c) => $q->where('channel', $c))
            ->when($request->mine, fn ($q) => $q->where('seller_id', $request->user()->id))
            ->latest()->paginate(30)->withQueryString();

        return view('proposals.index', compact('proposals'));
    }

    public function create(Request $request)
    {
        $customer = $request->customer_id ? Customer::with('pets')->find($request->customer_id) : null;
        $versions = PlanVersion::offerable()->with('plan')->get()
            ->filter(fn ($v) => $this->ctx->isCentral() || $v->availableForFranchise($this->ctx->id()));

        return view('proposals.create', compact('customer', 'versions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_version_id' => 'required|integer|exists:plan_versions,id',
            'channel' => ['required', Rule::in([ProposalChannel::Link->value, ProposalChannel::InPerson->value])],
            'fill_mode' => 'required_if:channel,link|in:seller,customer',
            'signature_method' => 'required_if:channel,in_person|nullable|in:tablet,paper',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'pet_id' => 'nullable|integer|exists:pets,id',
            'desired_start_date' => 'nullable|date|after_or_equal:today',
            'supersedes_id' => 'nullable|integer|exists:proposals,id',
            'revoke_superseded' => 'nullable|boolean',
        ]);

        $proposal = $this->proposals->create($data, $request->user(), $this->ctx->franchise());
        $warnings = $this->proposals->duplicateWarnings($proposal->pet_id, $proposal->plan_version_id, $proposal->id);

        return redirect()->route('proposals.show', $proposal)
            ->with('success', 'Proposta '.$proposal->number.' criada.')
            ->with('warnings', $warnings);
    }

    public function show(Proposal $proposal, CustomerFormService $forms, ActivationService $activation, Settings $settings, CheckoutService $checkout)
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($proposal->originating_franchise_id), 403);
        $proposal->load('customer', 'pet', 'planVersion.plan', 'seller', 'originatingFranchise', 'contract.files.uploader', 'contract.files.reviewer', 'contract.evidence', 'subscription.contract');

        $subscription = $proposal->subscription;

        return view('proposals.show', [
            'proposal' => $proposal,
            'merged' => $forms->merged($proposal),
            'missing' => $forms->missing($proposal),
            'warnings' => $this->proposals->duplicateWarnings($proposal->pet_id, $proposal->plan_version_id, $proposal->id),
            'subscription' => $subscription,
            'impediments' => $subscription ? $activation->impediments($subscription) : [],
            'initialInvoice' => $subscription ? $checkout->initialInvoice($subscription) : null,
            'inPersonEnabled' => $settings->enabled('in_person_payment.enabled'),
            'history' => AuditLog::where('auditable_type', 'Proposal')->where('auditable_id', $proposal->id)->with('user')->latest('created_at')->get(),
            'newLink' => session('new_link'),
        ]);
    }

    public function issueLink(Request $request, Proposal $proposal)
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($proposal->originating_franchise_id), 403);
        $token = $this->proposals->issueLink($proposal, $request->user());

        // O token é exibido somente agora; apenas o hash fica armazenado.
        return back()->with('new_link', route('public.proposal', $token))
            ->with('success', 'Link gerado. Copie e envie ao cliente — por segurança ele não poderá ser exibido de novo; se perder, gere outro (o anterior é revogado).');
    }

    public function revokeLink(Request $request, Proposal $proposal)
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($proposal->originating_franchise_id), 403);
        $this->proposals->revokeLink($proposal, $request->user());

        return back()->with('success', 'Link revogado.');
    }

    public function linkSent(Request $request, Proposal $proposal)
    {
        $data = $request->validate(['channel' => 'required|in:whatsapp,email,sms,presencial,outro']);
        $this->proposals->registerLinkSent($proposal, $request->user(), $data['channel']);

        return back()->with('success', 'Envio registrado.');
    }

    public function revoke(Request $request, Proposal $proposal)
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($proposal->originating_franchise_id), 403);
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->proposals->revoke($proposal, $request->user(), $data['reason']);

        return back()->with('success', 'Proposta revogada.');
    }

    /** Conflito de documento sem coincidência de e-mail: revisão interna antes do aceite (seção 27.2). */
    public function resolveConflict(Request $request, Proposal $proposal)
    {
        $data = $request->validate(['customer_id' => 'required|integer|exists:customers,id', 'reason' => 'required|string|max:500']);
        abort_unless(($proposal->form_data['conflict'] ?? false) && ! $proposal->hasBeenAccepted(), 422);

        $form = $proposal->form_data;
        $form['conflict'] = false;
        unset($form['customer']['document']);
        $proposal->forceFill(['customer_id' => $data['customer_id'], 'form_data' => $form, 'status' => ProposalStatus::AwaitingAcceptance])->save();
        $this->audit->log('proposal.conflict_resolved', $proposal, ['cliente' => $data['customer_id']], $data['reason']);

        return back()->with('success', 'Proposta vinculada ao cadastro existente. O titular ainda precisa confirmar os dados e aceitar.');
    }
}
