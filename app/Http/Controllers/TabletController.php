<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Proposal;
use App\Services\Access\FranchiseContext;
use App\Services\Contracts\ContractPreview;
use App\Services\Contracts\ContractService;
use App\Services\Proposals\CustomerFormService;
use Illuminate\Http\Request;

/**
 * Fluxo 7.1: o cliente confere os dados e o contrato na tela e assina com caneta ou dedo.
 */
class TabletController extends Controller
{
    public function __construct(protected FranchiseContext $ctx) {}

    public function show(Proposal $proposal, CustomerFormService $forms)
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($proposal->originating_franchise_id), 403);
        if ($proposal->contract) {
            return redirect()->route('proposals.show', $proposal)->with('warning', 'Esta proposta já foi assinada.');
        }
        abort_unless($proposal->signature_method->value === 'tablet', 404);

        return view('tablet.sign', [
            'proposal' => $proposal->load('planVersion'),
            'merged' => $forms->merged($proposal),
            'missing' => $forms->missing($proposal),
            'preview' => $this->previewBody($proposal, $forms),
        ]);
    }

    public function sign(Request $request, Proposal $proposal, ContractService $contracts)
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($proposal->originating_franchise_id), 403);
        $request->validate([
            'terms' => 'accepted',
            'signature' => 'required|string|max:3000000',
        ], ['terms.accepted' => 'O titular precisa confirmar que leu e concorda com o contrato.']);

        $contract = $contracts->signTablet($proposal, $request->input('signature'), $request->user(), [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'session_id' => $request->session()->getId(),
        ]);

        // Sucesso só é indicado depois de o servidor confirmar o registro (seção 7).
        return redirect()->route('tablet.done', $contract);
    }

    /** Tela neutra após a assinatura: não exibe dados do cliente para o próximo atendimento. */
    public function done(Contract $contract)
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($contract->proposal->originating_franchise_id), 403);

        return view('tablet.done', ['contract' => $contract]);
    }

    protected function previewBody(Proposal $proposal, CustomerFormService $forms): string
    {
        return app(ContractPreview::class)->render($proposal, $forms->merged($proposal));
    }
}
