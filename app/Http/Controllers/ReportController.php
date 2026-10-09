<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\BenefitLedgerEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Services\Access\FranchiseContext;
use App\Services\Audit\AuditLogger;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Relatórios mínimos (seção 15) com filtros visíveis e data de geração. Cada indicador
 * tem a definição de cálculo exibida na tela.
 */
class ReportController extends Controller
{
    public function __construct(protected FranchiseContext $ctx) {}

    public function index(Request $request)
    {
        return view('reports.index', $this->build($request) + ['generatedAt' => now()]);
    }

    /** Exportação controlada, registrada em auditoria e restrita ao escopo da unidade. */
    public function export(Request $request, string $report, AuditLogger $audit)
    {
        $data = $this->build($request);
        abort_unless(array_key_exists($report, $data['tables']), 404);
        $audit->log('report.exported', null, ['relatorio' => $report, 'filtros' => $data['filters']]);

        $rows = $data['tables'][$report];
        $filename = 'relatorio-'.$report.'-'.BusinessTime::now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Gerado em', BusinessTime::now()->format('d/m/Y H:i'), 'Período', $data['filters']['from'].' a '.$data['filters']['to']], ';');
            if ($rows !== []) {
                fputcsv($out, array_keys($rows[0]), ';');
                foreach ($rows as $row) {
                    fputcsv($out, $row, ';');
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function build(Request $request): array
    {
        $tz = BusinessTime::tz();
        $from = CarbonImmutable::parse($request->input('from', BusinessTime::now()->startOfMonth()->format('Y-m-d')), $tz)->startOfDay();
        $to = CarbonImmutable::parse($request->input('to', BusinessTime::now()->format('Y-m-d')), $tz)->endOfDay();
        $franchise = $this->ctx->isCentral() ? $request->integer('franchise_id') ?: null : $this->ctx->id();
        $scope = fn ($q, $col = 'originating_franchise_id') => $franchise ? $q->where($col, $franchise) : $q;

        $proposals = $scope(Proposal::query())->whereBetween('proposals.created_at', [$from->utc(), $to->utc()])
            ->leftJoin('users', 'users.id', '=', 'proposals.seller_id')
            ->select('proposals.channel', DB::raw("COALESCE(users.name, '—') as seller"), 'proposals.status', DB::raw('count(*) as total'))
            ->groupBy('proposals.channel', 'seller', 'proposals.status')->get()
            ->map(fn ($r) => ['canal' => $r->channel->label(), 'vendedor' => $r->seller, 'situacao' => $r->status->label(), 'total' => $r->total])->all();

        $subs = $scope(Subscription::query())->select('status', DB::raw('count(*) as total'))->groupBy('status')->get()
            ->map(fn ($r) => ['situacao' => $r->status->label(), 'total' => $r->total])->all();

        $pendingDocs = $scope(Subscription::query())->whereHas('contract', fn ($q) => $q->where('document_status', '!=', 'valid'))
            ->with('customer', 'contract')->get()
            ->map(fn ($s) => ['assinatura' => $s->number, 'cliente' => $s->customer->name, 'documento' => $s->contract->document_status->label()])->all();

        $invoiceScope = fn ($q) => $q->whereHas('subscription', fn ($s) => $scope($s));
        $overdue = $invoiceScope(Invoice::overdue())->with('subscription.customer')->get()
            ->map(fn ($i) => ['cliente' => $i->subscription->customer->name, 'assinatura' => $i->subscription->number, 'vencimento' => $i->due_date->format('d/m/Y'), 'saldo' => $i->outstandingCents() / 100])->all();

        $dueInPeriod = $invoiceScope(Invoice::query())->whereBetween('due_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]);
        $paymentsInPeriod = Payment::whereHas('invoice', $invoiceScope)->whereBetween('paid_at', [$from->utc(), $to->utc()]);
        $revenue = [
            'prevista' => (clone $dueInPeriod)->where('status', '!=', InvoiceStatus::Canceled->value)->sum('amount_cents'),
            'faturada' => (clone $dueInPeriod)->whereNotNull('external_id')->where('status', '!=', InvoiceStatus::Canceled->value)->sum('amount_cents'),
            'recebida' => (clone $paymentsInPeriod)->where('status', PaymentStatus::Confirmed->value)->sum('amount_cents'),
            'estornada' => (clone $paymentsInPeriod)->whereIn('status', [PaymentStatus::Refunded->value, PaymentStatus::Disputed->value])->sum('amount_cents'),
        ];

        $usage = BenefitLedgerEntry::query()
            ->join('authorizations', 'authorizations.id', '=', 'benefit_ledger.authorization_id')
            ->join('benefit_rules', 'benefit_rules.id', '=', 'authorizations.benefit_rule_id')
            ->join('franchises', 'franchises.id', '=', 'benefit_ledger.servicing_franchise_id')
            ->whereBetween('benefit_ledger.occurred_at', [$from->utc(), $to->utc()])
            ->when($franchise, fn ($q) => $q->where('benefit_ledger.servicing_franchise_id', $franchise))
            ->select('benefit_rules.description', 'franchises.name as unidade', DB::raw('sum(benefit_ledger.quantity) as quantidade'), DB::raw('count(distinct authorizations.pet_id) as pets'))
            ->groupBy('benefit_rules.description', 'franchises.name')->get()
            ->map(fn ($r) => ['beneficio' => $r->description, 'unidade' => $r->unidade, 'quantidade' => (int) $r->quantidade, 'pets' => (int) $r->pets])->all();

        return [
            'filters' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'franchise_id' => $franchise],
            'revenue' => $revenue,
            'tables' => [
                'propostas' => $proposals,
                'assinaturas' => $subs,
                'contratos_pendentes' => $pendingDocs,
                'parcelas_vencidas' => $overdue,
                'utilizacao' => $usage,
            ],
        ];
    }
}
