<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\FileReviewStatus;
use App\Jobs\GenerateContractPdf;
use App\Models\Contract;
use App\Models\ContractFile;
use App\Models\Proposal;
use App\Services\Access\FranchiseContext;
use App\Services\Audit\AuditLogger;
use App\Services\Contracts\ContractService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __construct(protected FranchiseContext $ctx, protected AuditLogger $audit, protected ContractService $contracts) {}

    /** Fila de conferência de documentos em papel (seção 7.2). */
    public function index()
    {
        $files = ContractFile::where('kind', Contract::KIND_SIGNED_SCAN)
            ->where('review_status', FileReviewStatus::Pending->value)->whereNull('superseded_by_id')
            ->whereHas('contract.proposal', fn ($q) => $this->ctx->scope($q))
            ->with('contract.proposal.customer', 'contract.proposal.pet', 'contract.evidence', 'uploader')
            ->oldest()->get();

        return view('documents.index', compact('files'));
    }

    public function preparePaper(Request $request, Proposal $proposal)
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($proposal->originating_franchise_id), 403);
        $this->contracts->preparePaper($proposal, $request->user());

        return redirect()->route('proposals.show', $proposal)->with('success', 'Contrato gerado para impressão. Após a assinatura, digitalize todas as páginas e anexe aqui.');
    }

    public function upload(Request $request, Contract $contract)
    {
        $this->authorizeContract($contract);
        $data = $request->validate([
            'file' => 'required|file|max:'.config('planos.uploads.max_kb'),
            'signed_on' => 'required|date|before_or_equal:today',
            'replacement_reason' => 'nullable|string|max:500',
        ]);
        $this->contracts->uploadScan($contract, $request->file('file'), $request->user(), $data['signed_on'], $data['replacement_reason'] ?? null);

        return back()->with('success', 'Digitalização enviada para conferência. O plano não é ativado até a validação.');
    }

    public function review(Request $request, ContractFile $file)
    {
        $this->authorizeContract($file->contract);
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:500',
            'checks' => 'required_if:decision,approve|array',
        ], ['checks.required_if' => 'Confirme os itens de conferência antes de aprovar.']);

        if ($data['decision'] === 'approve' && count($data['checks'] ?? []) < 4) {
            return back()->withErrors(['checks' => 'Confirme integridade, legibilidade, identificação e assinatura antes de aprovar.']);
        }

        $this->contracts->reviewScan($file, $request->user(), $data['decision'] === 'approve', $data['reason'] ?? null);

        return back()->with('success', $data['decision'] === 'approve' ? 'Documento validado.' : 'Documento rejeitado; a pendência documental permanece.');
    }

    /** Reprocessamento controlado a partir dos mesmos dados congelados. */
    public function regenerate(Contract $contract)
    {
        abort_unless($contract->document_status === DocumentStatus::Pending && $contract->signature_method->value !== 'paper', 422);
        $this->audit->log('contract.pdf_reprocess_requested', $contract);
        GenerateContractPdf::dispatch($contract->id)->onQueue('documents');

        return back()->with('success', 'Geração do PDF reenviada para a fila.');
    }

    /** Download autorizado e registrado; nunca por endereço público permanente. */
    public function download(ContractFile $file)
    {
        $this->authorizeContract($file->contract);
        $this->audit->log('contract.file_downloaded', $file->contract, ['arquivo' => $file->id, 'tipo' => $file->kind]);

        $ext = pathinfo($file->storage_key, PATHINFO_EXTENSION);

        return Storage::disk($file->disk)->download($file->storage_key, $file->contract->number.'-'.$file->kind.'-v'.$file->version.'.'.$ext, [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    protected function authorizeContract(Contract $contract): void
    {
        abort_unless($this->ctx->canSeeFranchiseRecord($contract->proposal->originating_franchise_id), 403);
    }
}
