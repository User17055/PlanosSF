@extends('layouts.app')
@section('title', 'Painel geral')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Painel geral</h1>
    <div class="d-flex gap-2">
        @can('proposals.create')<a href="{{ route('proposals.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nova contratação</a>@endcan
        @can('benefits.check')<a href="{{ route('benefits.index') }}" class="btn btn-outline-primary"><i class="bi bi-clipboard2-check"></i> Autorizar atendimento</a>@endcan
    </div>
</div>

<div class="row g-3 mb-4">
    @foreach ([
        ['Assinaturas ativas', $stats['active'], 'success', route('subscriptions.index', ['status' => 'active'])],
        ['Aguardando regularização', $stats['awaiting'], 'warning', route('subscriptions.index', ['status' => 'awaiting_regularization'])],
        ['Suspensas', $stats['suspended'], 'danger', route('subscriptions.index', ['status' => 'suspended'])],
        ['Propostas em andamento', $stats['open_proposals'], 'primary', route('proposals.index')],
        ['Documentos para conferir', $stats['docs_review'], 'warning', auth()->user()->can('documents.validate') ? route('documents.index') : null],
        ['Parcelas vencidas', $stats['overdue'], 'danger', auth()->user()->can('financial.view') ? route('financial.index', ['filter' => 'overdue']) : null],
        ['Recebimentos a validar', $stats['payments_validation'], 'warning', auth()->user()->can('financial.view') ? route('financial.index', ['filter' => 'validation']) : null],
    ] as [$label, $value, $color, $link])
        <div class="col-6 col-md-4 col-xl">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="small-label">{{ $label }}</div>
                <div class="value text-{{ $color }}">{{ $value }}</div>
                @if ($link)<a href="{{ $link }}" class="stretched-link"></a>@endif
            </div></div>
        </div>
    @endforeach
</div>

@if ($health)
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-activity"></i> Saúde da operação</div>
    <div class="card-body">
        @php($schedOk = $health['scheduler_last'] && \Carbon\Carbon::parse($health['scheduler_last'])->gt(now()->subMinutes(5)))
        <div class="row g-3 small">
            <div class="col-md-3"><div class="small-label">Agendador</div>
                @if ($schedOk)<span class="text-success"><i class="bi bi-check-circle"></i> ativo</span>@else<span class="text-danger"><i class="bi bi-x-circle"></i> parado</span>@endif
                <div class="text-muted">última execução: @datetime($health['scheduler_last'] ? \Carbon\Carbon::parse($health['scheduler_last']) : null)</div></div>
            <div class="col-md-3"><div class="small-label">Fila</div>
                <span class="{{ $health['oldest_job_age_min'] > 15 ? 'text-danger' : '' }}">{{ $health['queue_size'] }} trabalhos · mais antigo há {{ $health['oldest_job_age_min'] }} min</span>
                <div class="text-muted">{{ $health['failed_jobs'] }} falhas definitivas</div></div>
            <div class="col-md-3"><div class="small-label">Conciliação financeira</div>
                pendentes: @datetime($health['reconcile_last'] ? \Carbon\Carbon::parse($health['reconcile_last']) : null)<br>
                completa: @datetime($health['reconcile_all_last'] ? \Carbon\Carbon::parse($health['reconcile_all_last']) : null)</div>
            <div class="col-md-3"><div class="small-label">Pendências</div>
                {{ $health['pdf_failures'] }} falhas de PDF · {{ $health['webhooks_pending'] }} webhooks não processados<br>
                {{ $health['integrations_attention'] }} integrações com erro · {{ $health['manual_tasks'] }} tarefas manuais<br>
                {{ $health['late_payments'] }} pagamentos/estornos para análise (30 dias)</div>
        </div>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">Propostas recentes</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Proposta</th><th>Cliente</th><th>Plano</th><th>Canal</th><th>Situação</th><th>Criada</th></tr></thead>
            <tbody>
            @forelse ($recent as $p)
                <tr>
                    <td><a href="{{ route('proposals.show', $p) }}">{{ $p->number }}</a></td>
                    <td>{{ $p->customer?->name ?? '— aguardando preenchimento —' }}</td>
                    <td>{{ $p->planVersion->plan->name }} v{{ $p->planVersion->version }}</td>
                    <td>{{ $p->channel->label() }}</td>
                    <td><x-status :value="$p->status" /></td>
                    <td>@datetime($p->created_at)</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted text-center py-4">Nenhuma proposta ainda.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
