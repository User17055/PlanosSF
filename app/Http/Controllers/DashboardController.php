<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\IntegrationStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProposalStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Contract;
use App\Models\IntegrationJob;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\WebhookInbox;
use App\Services\Access\FranchiseContext;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(FranchiseContext $ctx)
    {
        $subs = fn () => $ctx->scope(Subscription::query());
        $stats = [
            'active' => $subs()->where('status', SubscriptionStatus::Active->value)->count(),
            'awaiting' => $subs()->where('status', SubscriptionStatus::AwaitingRegularization->value)->count(),
            'suspended' => $subs()->where('status', SubscriptionStatus::Suspended->value)->count(),
            'open_proposals' => $ctx->scope(Proposal::query())->whereIn('status', [
                ProposalStatus::AwaitingFill->value, ProposalStatus::AwaitingAcceptance->value, ProposalStatus::AwaitingDocument->value, ProposalStatus::AwaitingPayment->value,
            ])->count(),
            'docs_review' => Contract::where('document_status', DocumentStatus::AwaitingReview->value)
                ->whereHas('proposal', fn ($q) => $ctx->scope($q))->count(),
            'overdue' => Invoice::overdue()->whereHas('subscription', fn ($q) => $ctx->scope($q))->count(),
            'payments_validation' => Payment::where('status', PaymentStatus::PendingValidation->value)->count(),
        ];

        // Monitoramento operacional (seções 16 e 23): filas, agendador, documentos e integrações.
        $health = null;
        if ($ctx->isCentral()) {
            $heartbeats = DB::table('scheduler_heartbeats')->pluck('last_run_at', 'name');
            $oldestJob = DB::table('jobs')->min('created_at');
            $health = [
                'scheduler_last' => $heartbeats['scheduler'] ?? null,
                'reconcile_last' => $heartbeats['reconcile-pending'] ?? null,
                'reconcile_all_last' => $heartbeats['reconcile-all'] ?? null,
                'queue_size' => DB::table('jobs')->count(),
                'oldest_job_age_min' => $oldestJob ? (int) floor((time() - (int) $oldestJob) / 60) : 0,
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'pdf_failures' => Contract::whereNotNull('pdf_failed_at')->where('document_status', DocumentStatus::Pending->value)->count(),
                'webhooks_pending' => WebhookInbox::whereIn('status', ['received', 'retrying', 'failed'])->count(),
                'integrations_attention' => IntegrationJob::whereIn('status', [IntegrationStatus::Error->value, IntegrationStatus::NeedsIntervention->value])->where('mode', IntegrationJob::MODE_AUTOMATIC)->count(),
                'manual_tasks' => IntegrationJob::where('mode', IntegrationJob::MODE_MANUAL)->where('status', IntegrationStatus::NeedsIntervention->value)->count(),
                'late_payments' => DB::table('subscription_events')->whereIn('event', ['late_payment_after_cancellation', 'payment_reversed'])->where('recorded_at', '>', now()->subDays(30))->count(),
            ];
        }

        $recent = $ctx->scope(Proposal::query())->with('customer', 'planVersion.plan')->latest()->limit(8)->get();

        return view('dashboard', compact('stats', 'health', 'recent'));
    }
}
