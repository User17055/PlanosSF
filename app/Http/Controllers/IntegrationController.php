<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\IntegrationJob;
use App\Models\WebhookInbox;
use App\Services\Access\FranchiseContext;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\IntegrationRunner;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function __construct(protected FranchiseContext $ctx) {}

    public function index(Request $request)
    {
        $jobs = IntegrationJob::query()
            ->when(! $this->ctx->isCentral(), fn ($q) => $q->where('franchise_id', $this->ctx->id()))
            ->when($request->destination, fn ($q, $d) => $q->where('destination', $d))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', IntegrationStatus::Synced->value))
            ->with('completedBy', 'franchise')
            ->latest()->paginate(40)->withQueryString();

        $webhooks = $this->ctx->isCentral()
            ? WebhookInbox::whereIn('status', ['received', 'retrying', 'failed', 'processing'])->latest('received_at')->limit(30)->get()
            : collect();

        return view('integrations.index', compact('jobs', 'webhooks'));
    }

    public function retry(Request $request, IntegrationJob $job, IntegrationRunner $runner)
    {
        abort_unless($this->ctx->isCentral() || $job->franchise_id === $this->ctx->id(), 403);
        $runner->retry($job, $request->user());

        return back()->with('success', 'Reprocessamento executado: '.$job->fresh()->status->label().'.');
    }

    /** Integração manual auditada (seções 12.2 e 12.3): confirmação registrada com responsável. */
    public function complete(Request $request, IntegrationJob $job, IntegrationRunner $runner)
    {
        abort_unless($this->ctx->isCentral() || $job->franchise_id === $this->ctx->id(), 403);
        $d = $request->validate([
            'note' => 'required|string|max:1000',
            'external_reference' => 'nullable|string|max:120',
        ]);
        $runner->completeManual($job, $request->user(), $d['note'], $d['external_reference'] ?? null);

        return back()->with('success', 'Tarefa concluída e registrada.');
    }

    public function retryWebhook(WebhookInbox $inbox, AuditLogger $audit)
    {
        // Eventos de cobrança não têm unidade: só a central os vê (index) e reprocessa.
        abort_unless($this->ctx->isCentral(), 403);
        $inbox->forceFill(['status' => 'received'])->save();
        $audit->log('webhook.reprocess_requested', $inbox);
        ProcessWebhookEvent::dispatch($inbox->id)->onQueue('payments');

        return back()->with('success', 'Evento reenviado para processamento.');
    }
}
