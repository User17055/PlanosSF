@extends('layouts.app')
@section('title', 'Benefícios de '.$subscription->pet->name)
@section('content')
<div class="mb-3">
    <a href="{{ route('benefits.index', ['q' => $subscription->customer->document_normalized]) }}" class="small">&larr; voltar à consulta</a>
    <h1 class="h4 mb-0">{{ $subscription->pet->name }} — {{ $subscription->planVersion->plan->name }} <x-status :value="$subscription->status" /></h1>
    <div class="small text-muted">Tutor {{ $subscription->customer->name }} · assinatura {{ $subscription->number }} · vigência desde @datetime($subscription->effective_start_at)</div>
</div>

@if (! $financial->isRegular())
    <div class="alert alert-danger block-alert"><strong>{{ $financial->blockMessage() }}</strong><div class="small">Não existe liberação manual para inadimplência. Atualização financeira: @datetime($financial->lastSyncedAt)</div></div>
@endif

@if ($reservations->isNotEmpty())
    <div class="card mb-3"><div class="card-header">Reservas em aberto</div>
        <ul class="list-group list-group-flush">
            @foreach ($reservations as $r)
                <li class="list-group-item d-flex justify-content-between"><span>{{ $r->benefitRule->description }} × {{ $r->quantity }} — válida até @datetime($r->expires_at)</span>
                    <a href="{{ route('benefits.show', $r) }}" class="btn btn-sm btn-primary">Abrir</a></li>
            @endforeach
        </ul></div>
@endif

<div class="card">
    <div class="card-header">Benefícios do plano</div>
    <div class="list-group list-group-flush">
        @foreach ($evaluations as $e)
            @php($d = $e['decision'])
            <div class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <strong>{{ $e['rule']->description }}</strong>
                        <div class="small {{ $d->allowed ? 'text-success' : 'text-danger' }}">
                            {{ $d->allowed ? 'Autorizado' : 'Negado' }}: {{ $d->message }}
                            @if ($d->available !== null) · saldo {{ $d->available }} de {{ $d->limit }} @endif
                            @if (! $d->allowed && $d->availableFrom) · liberação/renovação em @datetime($d->availableFrom) @endif
                        </div>
                        <div class="small text-muted"><code>{{ $d->code }}</code></div>
                    </div>
                    @if ($d->allowed)
                        @can('benefits.reserve')
                        <form method="post" action="{{ route('benefits.reserve', $subscription) }}" class="d-flex gap-2 align-items-center" data-once>
                            @csrf
                            <input type="hidden" name="benefit_rule_id" value="{{ $e['rule']->id }}">
                            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}-{{ $e['rule']->id }}" data-key>
                            <input type="number" name="quantity" value="1" min="1" max="{{ $d->available ?? 100 }}" class="form-control form-control-sm" style="width: 80px">
                            <input name="notes" class="form-control form-control-sm" placeholder="Observação">
                            <button class="btn btn-sm btn-primary text-nowrap">Reservar</button>
                        </form>
                        @endcan
                    @endif
                </div>
            </div>
        @endforeach
    </div>
    <div class="card-footer small text-muted">Agendamento não é consumo: reserve, execute o serviço e confirme a execução. A elegibilidade é revalidada na confirmação.</div>
</div>
@endsection
