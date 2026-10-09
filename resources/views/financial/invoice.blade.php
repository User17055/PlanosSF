@extends('layouts.app')
@section('title', 'Parcela')
@section('content')
@php($sub = $invoice->subscription)
<div class="row justify-content-center"><div class="col-xl-9">
<a href="{{ route('subscriptions.show', $sub) }}" class="small">&larr; assinatura {{ $sub->number }}</a>
<h1 class="h4">Parcela {{ $invoice->is_initial ? '(primeira)' : '' }} — @money($invoice->amount_cents) <x-status :value="$invoice->status" /> @if ($invoice->isOverdue())<span class="badge text-bg-danger">vencida</span>@endif</h1>
<p class="text-muted small">{{ $sub->customer->name }} · {{ $sub->pet->name }} · competência {{ $invoice->period_start?->format('d/m/Y') ?? '—' }} a {{ $invoice->period_end?->format('d/m/Y') ?? '—' }} · vencimento {{ $invoice->due_date->format('d/m/Y') }}
    · provedor {{ $invoice->provider }} ({{ $invoice->provider_status ?? '—' }}) · ref. {{ $invoice->external_id }} · atualizado @datetime($invoice->last_synced_at)</p>

<div class="card mb-3"><div class="card-header">Transações e recebimentos</div>
    <div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>Origem</th><th>Valor</th><th>Líquido</th><th>Pago em</th><th>Situação</th><th>Referência</th><th>Registro / validação</th><th></th></tr></thead>
        <tbody>
        @forelse ($invoice->payments as $p)
            <tr>
                <td>{{ $p->source }} {{ $p->method ? '· '.$p->method : '' }}</td><td>@money($p->amount_cents)</td><td>@money($p->net_amount_cents)</td>
                <td>@datetime($p->paid_at)</td><td><x-status :value="$p->status" /></td>
                <td class="small">{{ $p->external_transaction_id }}</td>
                <td class="small">{{ $p->registeredBy?->name ?? 'provedor' }} @if ($p->validatedBy)/ {{ $p->validatedBy->name }} @datetime($p->validated_at)@endif
                    @if ($p->rejection_reason)<div class="text-danger">{{ $p->rejection_reason }}</div>@endif</td>
                <td>
                    @if ($p->status->value === 'pending_validation')
                        @can('financial.confirm_in_person')
                        <form method="post" action="{{ route('financial.validate', $p) }}" class="d-flex flex-column gap-1">@csrf
                            <input name="reason" class="form-control form-control-sm" placeholder="Motivo (se rejeitar)">
                            <div class="d-flex gap-1"><button name="decision" value="approve" class="btn btn-sm btn-success">Validar</button>
                                <button name="decision" value="reject" class="btn btn-sm btn-outline-danger">Rejeitar</button></div>
                        </form>
                        @endcan
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-3">Nenhuma transação.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

@if ($invoice->provider === 'manual' && $invoice->status->value !== 'paid')
    <div class="card mb-3"><div class="card-header">Registrar recebimento presencial</div><div class="card-body">
        @if (! $inPersonEnabled)
            <div class="alert alert-warning mb-0">Decisão pendente: forma de recebimento presencial e reflexo da primeira parcela na Vindi (seção 7.3). Habilite em Configurações somente após a aprovação.</div>
        @else
            <form method="post" action="{{ route('financial.register', $invoice) }}" class="row g-2" data-once>@csrf
                <div class="col-md-3"><select name="method" class="form-select"><option value="pix">PIX</option><option value="cartao_maquininha">Cartão (maquininha)</option><option value="dinheiro">Dinheiro</option><option value="outro">Outro</option></select></div>
                <div class="col-md-2"><input name="amount" class="form-control" value="{{ \App\Support\Money::input($invoice->outstandingCents()) }}" required></div>
                <div class="col-md-3"><input type="datetime-local" name="paid_at" class="form-control" required></div>
                <div class="col-md-4"><input name="reference" class="form-control" placeholder="Referência da transação (NSU, ID PIX...)" required></div>
                <div class="col-12"><input name="notes" class="form-control" placeholder="Observações"></div>
                <div class="col-12"><button class="btn btn-primary">Registrar para validação</button>
                    <span class="form-text ms-2">Foto de comprovante enviada pelo cliente não libera o plano automaticamente.</span></div>
            </form>
        @endif
    </div></div>
@endif

@can('financial.adjust')
<div class="card mb-3"><div class="card-header">Ajustes financeiros</div>
    <ul class="list-group list-group-flush small">
        @foreach ($invoice->adjustments as $a)
            <li class="list-group-item">{{ $a->type }} @money($a->amount_cents) · {{ $a->status }} · {{ $a->requester->name }} @datetime($a->created_at) — {{ $a->reason }} (evidência: {{ $a->evidence_reference }})</li>
        @endforeach
    </ul>
    <form method="post" action="{{ route('financial.adjust', $invoice) }}" class="card-body row g-2 border-top">@csrf
        <div class="col-md-3"><select name="type" class="form-select form-select-sm"><option value="correcao">Correção</option><option value="isencao">Isenção</option><option value="abatimento">Abatimento</option><option value="renegociacao">Renegociação</option></select></div>
        <div class="col-md-2"><input name="amount" class="form-control form-control-sm" placeholder="Valor" required></div>
        <div class="col-md-7"><input name="evidence_reference" class="form-control form-control-sm" placeholder="Comprovação / referência" required></div>
        <div class="col-12"><textarea name="reason" class="form-control form-control-sm" placeholder="Motivo" required></textarea></div>
        <div class="col-12"><button class="btn btn-sm btn-outline-primary">Solicitar ajuste</button>
            <span class="form-text ms-2">Ajustes não podem ser usados como atalho para liberar cliente que permanece devendo.</span></div>
    </form>
</div>
@endcan
</div></div>
@endsection
