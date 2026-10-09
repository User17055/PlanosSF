@extends('layouts.app')
@section('title', 'Financeiro')
@section('content')
<h1 class="h4 mb-3">Financeiro</h1>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card card-body"><div class="small-label">Clientes inadimplentes</div><div class="fs-3 fw-bold text-danger">{{ $totals['customers'] }}</div></div></div>
    <div class="col-md-4"><div class="card card-body"><div class="small-label">Parcelas vencidas</div><div class="fs-3 fw-bold">{{ $totals['invoices'] }}</div></div></div>
    <div class="col-md-4"><div class="card card-body"><div class="small-label">Saldo vencido</div><div class="fs-3 fw-bold">@money($totals['amount'])</div></div></div>
</div>

@if ($pendingValidation->isNotEmpty())
<div class="card mb-3 border-warning">
    <div class="card-header">Recebimentos presenciais aguardando validação</div>
    <div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>Cliente</th><th>Valor</th><th>Meio</th><th>Referência</th><th>Pago em</th><th>Registrado por</th><th></th></tr></thead>
        <tbody>
        @foreach ($pendingValidation as $p)
            <tr>
                <td>{{ $p->invoice->subscription->customer->name }}</td><td>@money($p->amount_cents)</td><td>{{ $p->method }}</td><td>{{ $p->reference }}</td>
                <td>@datetime($p->paid_at)</td><td>{{ $p->registeredBy?->name }}</td>
                <td><a href="{{ route('financial.invoice', $p->invoice) }}" class="btn btn-sm btn-primary">Conferir</a></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
@endif

@if ($alerts->isNotEmpty())
<div class="card mb-3">
    <div class="card-header">Casos para análise financeira</div>
    <ul class="list-group list-group-flush small">
        @foreach ($alerts as $e)
            <li class="list-group-item"><strong>{{ ['late_payment_after_cancellation' => 'Pagamento após cancelamento', 'payment_reversed' => 'Estorno/contestação', 'external_cancellation' => 'Cancelamento feito no provedor', 'billing_error' => 'Erro na criação da cobrança'][$e->event] ?? $e->event }}</strong>
                — <a href="{{ route('subscriptions.show', $e->subscription) }}">{{ $e->subscription->number }}</a> ({{ $e->subscription->customer->name }}) · @datetime($e->recorded_at) · {{ $e->reason }}</li>
        @endforeach
    </ul>
</div>
@endif

<ul class="nav nav-tabs mb-0">
    @foreach (['overdue' => 'Vencidas', 'open' => 'Em aberto', 'paid' => 'Pagas', 'all' => 'Todas'] as $k => $l)
        <li class="nav-item"><a class="nav-link @if ($filter === $k) active @endif" href="{{ route('financial.index', ['filter' => $k]) }}">{{ $l }}</a></li>
    @endforeach
</ul>
<div class="card rounded-top-0"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr><th>Cliente</th><th>Assinatura</th><th>Competência</th><th>Vencimento</th><th>Valor</th><th>Pago</th><th>Situação</th><th>Origem</th><th>Atualizado</th></tr></thead>
        <tbody>
        @forelse ($invoices as $i)
            <tr>
                <td><a href="{{ route('customers.show', $i->subscription->customer) }}">{{ $i->subscription->customer->name }}</a></td>
                <td class="small"><a href="{{ route('subscriptions.show', $i->subscription) }}">{{ $i->subscription->number }}</a><br>{{ $i->subscription->pet->name }}</td>
                <td class="small"><a href="{{ route('financial.invoice', $i) }}">{{ $i->period_start?->format('d/m/Y') ?? 'inicial' }}{{ $i->period_end ? ' – '.$i->period_end->format('d/m/Y') : '' }}</a></td>
                <td>{{ $i->due_date->format('d/m/Y') }}</td>
                <td>@money($i->amount_cents)</td><td>@money($i->paid_cents)</td>
                <td><x-status :value="$i->status" /> @if ($i->isOverdue())<span class="badge text-bg-danger">vencida</span>@endif</td>
                <td class="small">{{ $i->subscription->originatingFranchise->name }}<br>{{ $i->provider }}</td>
                <td class="small text-muted">@datetime($i->last_synced_at)</td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center text-muted py-4">Nenhuma parcela neste filtro.</td></tr>
        @endforelse
        </tbody>
    </table>
</div></div>
<div class="mt-3">{{ $invoices->links() }}</div>
@endsection
