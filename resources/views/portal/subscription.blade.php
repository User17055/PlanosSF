@extends('layouts.public')
@section('title', $subscription->pet->name)
@section('nav-right')<a href="{{ route('portal.home') }}" class="btn btn-sm btn-light">Meus planos</a>@endsection
@section('content')
@php($s = $subscription)
<h1 class="h4">{{ $s->pet->name }} — {{ $s->planVersion->plan->name }} <x-status :value="$s->status" /></h1>
<p class="text-muted small">@if ($s->card_number)Carteirinha {{ $s->card_number }} · @endif vigência desde @datetime($s->effective_start_at)
    @if ($s->end_at) · encerramento @datetime($s->end_at) @endif</p>

@if ($s->contract->currentFinalFile()?->review_status->value === 'approved')
    <a href="{{ route('portal.contract', $s) }}" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-file-earmark-pdf"></i> Baixar contrato</a>
@endif

<div class="card mb-3"><div class="card-header">Carências e saldo</div>
    <ul class="list-group list-group-flush">
        @foreach ($benefits as $b)
            <li class="list-group-item">
                <strong>{{ $b['rule']->description }}</strong>
                <div class="small {{ $b['decision']->allowed ? 'text-success' : 'text-muted' }}">
                    @if ($b['decision']->allowed) Disponível{{ $b['decision']->available !== null ? ' — saldo '.$b['decision']->available.' de '.$b['decision']->limit : '' }}
                    @else {{ $b['decision']->message }} @endif
                </div>
            </li>
        @endforeach
    </ul></div>

<div class="card mb-3"><div class="card-header">Histórico de uso</div>
    <ul class="list-group list-group-flush small">
        @forelse ($s->authorizations->where('status.value', 'consumed') as $a)
            <li class="list-group-item">@datetime($a->consumed_at) — {{ $a->benefitRule->description }} × {{ $a->quantity }}</li>
        @empty
            <li class="list-group-item text-muted">Nenhuma utilização registrada.</li>
        @endforelse
    </ul></div>

<div class="card mb-3"><div class="card-header">Parcelas</div>
    <ul class="list-group list-group-flush small">
        @foreach ($s->invoices as $i)
            <li class="list-group-item d-flex justify-content-between">
                <span>{{ $i->due_date->format('d/m/Y') }} — @money($i->amount_cents)</span>
                <span><x-status :value="$i->status" /> @if ($i->isOverdue())<span class="badge text-bg-danger">vencida</span>@endif</span>
            </li>
        @endforeach
    </ul></div>

@if (! $s->isTerminal() && $s->cancellationRequests->whereIn('status', ['requested', 'scheduled'])->isEmpty())
<details class="card card-body">
    <summary>Solicitar cancelamento</summary>
    <form method="post" action="{{ route('portal.cancel', $s) }}" class="mt-2" data-confirm="Enviar a solicitação de cancelamento?">@csrf
        <select name="type" class="form-select mb-2"><option value="end_of_period">Ao fim do período já pago</option><option value="immediate">Imediato</option></select>
        <textarea name="reason" class="form-control mb-2" placeholder="Motivo" required></textarea>
        <button class="btn btn-outline-danger">Enviar solicitação</button>
    </form>
</details>
@else
    @foreach ($s->cancellationRequests as $cr)<div class="alert alert-secondary small">Cancelamento solicitado em @datetime($cr->created_at) — {{ $cr->status }}</div>@endforeach
@endif
@endsection
