@extends('layouts.app')
@section('title', 'Assinatura '.$subscription->number)
@section('content')
@php($sub = $subscription)
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">{{ $sub->planVersion->plan->name }} <span class="text-muted">v{{ $sub->planVersion->version }}</span> — {{ $sub->pet->name }} <x-status :value="$sub->status" /></h1>
        <div class="text-muted small">Assinatura {{ $sub->number }} · tutor <a href="{{ route('customers.show', $sub->customer) }}">{{ $sub->customer->name }}</a>
            · proposta <a href="{{ route('proposals.show', $sub->proposal) }}">{{ $sub->proposal->number }}</a> · contrato {{ $sub->contract->number }}
            · {{ $sub->originatingFranchise->name }} @if ($sub->card_number)· carteirinha <strong>{{ $sub->card_number }}</strong>@endif</div>
    </div>
    @can('benefits.check')<a href="{{ route('benefits.subscription', $sub) }}" class="btn btn-primary"><i class="bi bi-clipboard2-check"></i> Autorizar atendimento</a>@endcan
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card card-body h-100"><div class="small-label">Início da vigência</div>@datetime($sub->effective_start_at)
        <div class="small text-muted">ativada em @datetime($sub->activated_at)</div></div></div>
    <div class="col-md-3"><div class="card card-body h-100"><div class="small-label">Encerramento</div>{{ $sub->end_at ? \App\Support\BusinessTime::formatDateTime($sub->end_at) : '—' }}</div></div>
    <div class="col-md-3"><div class="card card-body h-100"><div class="small-label">Documento</div><x-status :value="$sub->contract->document_status" /></div></div>
    <div class="col-md-3"><div class="card card-body h-100"><div class="small-label">Cobrança</div>{{ $sub->billing_provider ?? '—' }} · {{ $sub->billing_status }}</div></div>
</div>

@if ($impediments)
    <div class="alert alert-warning"><strong>Impedimentos:</strong> @foreach ($impediments as $imp)<span class="badge text-bg-light border text-danger me-1">{{ $imp->label() }}</span>@endforeach</div>
@endif

<div class="row g-3">
<div class="col-xl-7">
    <div class="card mb-3">
        <div class="card-header">Benefícios — carência e saldo</div>
        <div class="table-responsive"><table class="table mb-0">
            <thead><tr><th>Benefício</th><th>Liberação</th><th>Saldo</th><th>Situação agora</th></tr></thead>
            <tbody>
            @foreach ($benefits as $b)
                <tr>
                    <td>{{ $b['rule']->description }}<div class="small text-muted">{{ $b['rule']->isUnlimited() ? 'sem limite' : $b['rule']->quantity_limit.' '.$b['rule']->unit.' / '.mb_strtolower($b['rule']->period_type?->label() ?? '') }}</div></td>
                    <td class="small">{{ $b['available_from'] ? \App\Support\BusinessTime::formatDateTime($b['available_from']) : 'após ativação' }}</td>
                    <td>{{ $b['decision']->available !== null ? $b['decision']->available.' de '.$b['decision']->limit : ($b['decision']->allowed ? '∞' : '—') }}</td>
                    <td>@if ($b['decision']->allowed)<span class="text-success"><i class="bi bi-check-circle"></i> disponível</span>@else<span class="text-danger small">{{ $b['decision']->message }}</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Utilizações</div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Data</th><th>Benefício</th><th>Qtd</th><th>Unidade</th><th>Usuário</th><th>Situação</th></tr></thead>
            <tbody>
            @forelse ($authorizations as $a)
                <tr><td class="small"><a href="{{ route('benefits.show', $a) }}">@datetime($a->created_at)</a></td><td>{{ $a->benefitRule->description }}</td><td>{{ $a->quantity }}</td>
                    <td class="small">{{ $a->servicingFranchise->name }}</td><td class="small">{{ $a->user->name }}</td>
                    <td><x-status :value="$a->status" /> @if ($a->ledger->where('type', 'reversal')->isNotEmpty())<span class="badge text-bg-secondary">estornada</span>@endif</td></tr>
            @empty
                <tr><td colspan="6" class="text-muted text-center py-3">Nenhuma utilização.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>

    @can('financial.view')
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between">Parcelas
            @can('financial.reconcile')<form method="post" action="{{ route('financial.reconcile', $sub) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Conciliar agora</button></form>@endcan</div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Competência</th><th>Vencimento</th><th>Valor</th><th>Pago</th><th>Situação</th><th>Provedor</th></tr></thead>
            <tbody>
            @forelse ($sub->invoices as $i)
                <tr @class(['table-danger' => $i->isOverdue()])>
                    <td class="small"><a href="{{ route('financial.invoice', $i) }}">{{ $i->period_start?->format('d/m/Y') ?? '1ª parcela' }}{{ $i->period_end ? ' – '.$i->period_end->format('d/m/Y') : '' }}</a></td>
                    <td>{{ $i->due_date->format('d/m/Y') }}</td><td>@money($i->amount_cents)</td><td>@money($i->paid_cents)</td>
                    <td><x-status :value="$i->status" /> @if ($i->isOverdue())<span class="badge text-bg-danger">vencida</span>@endif</td>
                    <td class="small text-muted">{{ $i->provider }} {{ $i->provider_status }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted text-center py-3">Nenhuma parcela.
                    @if ($sub->status->value === 'awaiting_regularization' && auth()->user()->can('proposals.create'))
                        <form method="post" action="{{ route('subscriptions.checkout', $sub) }}" class="d-inline">@csrf<button class="btn btn-sm btn-link">Gerar cobrança inicial</button></form>
                    @endif</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
    @endcan
</div>

<div class="col-xl-5">
    @if ($cancelSummary)
        @can('subscriptions.cancel')
        <div class="card mb-3">
            <div class="card-header">Cancelamento</div>
            <div class="card-body small">
                <div>Último período pago: {{ $cancelSummary['last_covered_period_end'] ? \Carbon\Carbon::parse($cancelSummary['last_covered_period_end'])->format('d/m/Y') : '—' }}</div>
                <div>Encerramento ao fim do período: @datetime($cancelSummary['end_of_period_at'])</div>
                <div>Débitos vencidos: @money($cancelSummary['overdue_cents']) ({{ $cancelSummary['overdue']->count() }})</div>
                <div>Cobranças futuras conhecidas: {{ $cancelSummary['future']->count() }}</div>
                <form method="post" action="{{ route('subscriptions.cancel', $sub) }}" class="mt-2" data-confirm="Confirmar o cancelamento?">@csrf
                    <select name="type" class="form-select form-select-sm mb-2">
                        <option value="end_of_period">Encerrar ao fim do período pago</option>
                        <option value="immediate">Cancelamento imediato</option>
                    </select>
                    <textarea name="reason" class="form-control form-control-sm mb-2" placeholder="Motivo" required></textarea>
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="cc"><label class="form-check-label" for="cc">Revisei débitos, período pago e cobranças futuras</label></div>
                    <button class="btn btn-sm btn-outline-danger">Registrar cancelamento</button>
                </form>
            </div>
        </div>
        @endcan
    @endif

    @foreach ($sub->cancellationRequests as $cr)
        <div class="card mb-3"><div class="card-body small">
            <strong>Cancelamento {{ $cr->type === 'immediate' ? 'imediato' : 'ao fim do período' }}</strong> · {{ $cr->status }} · provedor: {{ $cr->provider_status }}
            @if ($cr->provider_status === 'failed')<span class="badge text-bg-danger">cancelamento financeiro pendente</span>@endif
            <div>Solicitado via {{ $cr->requested_via }} {{ $cr->requester ? 'por '.$cr->requester->name : '' }} em @datetime($cr->created_at) · efetivo @datetime($cr->effective_at)</div>
            <div class="text-muted">{{ $cr->reason }}</div>
            @if ($cr->status === 'requested')
                @can('subscriptions.cancel')<form method="post" action="{{ route('cancellations.approve', $cr) }}" class="mt-1">@csrf<button class="btn btn-sm btn-outline-danger">Aprovar solicitação do cliente</button></form>@endcan
            @endif
        </div></div>
    @endforeach

    <div class="card">
        <div class="card-header">Histórico de estados</div>
        <ul class="list-group list-group-flush small">
            @foreach ($sub->events as $e)
                <li class="list-group-item">
                    <strong>{{ $e->event }}</strong> @if ($e->to_status)→ {{ \App\Enums\SubscriptionStatus::tryFrom($e->to_status)?->label() }}@endif
                    <div class="text-muted">ocorrido @datetime($e->occurred_at) · registrado @datetime($e->recorded_at) · {{ $e->user?->name ?? $e->source }}</div>
                    @if ($e->reason)<div>{{ $e->reason }}</div>@endif
                </li>
            @endforeach
        </ul>
    </div>
</div>
</div>
@endsection
