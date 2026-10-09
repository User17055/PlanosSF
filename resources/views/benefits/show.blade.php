@extends('layouts.app')
@section('title', 'Autorização')
@section('content')
@php($a = $authorization)
<div class="row justify-content-center"><div class="col-xl-8">
<a href="{{ route('benefits.subscription', $a->subscription) }}" class="small">&larr; benefícios do pet</a>
<h1 class="h4">{{ $a->benefitRule->description }} × {{ $a->quantity }} <x-status :value="$a->status" /></h1>
<p class="text-muted small">Comprovante {{ $a->uuid }} — consulte sempre o estado atual; um print antigo não garante que a reserva siga válida.</p>

<div class="card mb-3"><div class="card-body small">
    <div>Pet: <strong>{{ $a->pet->name }}</strong> · tutor {{ $a->subscription->customer->name }} · assinatura {{ $a->subscription->number }}</div>
    <div>Unidade: {{ $a->servicingFranchise->name }} · reservado por {{ $a->user->name }} em @datetime($a->created_at)</div>
    <div>Validade da reserva: @datetime($a->expires_at)</div>
    @if ($a->consumed_at)<div>Executado em @datetime($a->consumed_at) @if ($a->external_procedure_id)· atendimento externo {{ $a->external_procedure_id }}@endif</div>@endif
    @if ($a->released_at)<div>Reserva cancelada em @datetime($a->released_at): {{ $a->release_reason }}</div>@endif
    @if ($a->notes)<div>Obs.: {{ $a->notes }}</div>@endif
    <div class="mt-2">Regras avaliadas:
        @foreach ($a->evaluation['checks'] ?? [] as $c)<span class="badge text-bg-light border">{{ $c['rule'] }}{{ isset($c['detail']) ? ': '.$c['detail'] : '' }}</span> @endforeach</div>
</div></div>

@if ($a->status->value === 'reserved')
    <div class="row g-3">
        @can('benefits.consume')
        <div class="col-md-7"><form method="post" action="{{ route('benefits.consume', $a) }}" class="card card-body" data-once>@csrf
            <div class="fw-semibold mb-2">Confirmar execução do serviço</div>
            <input name="external_procedure_id" class="form-control mb-2" placeholder="Nº do atendimento no SimplesVet (opcional)">
            <button class="btn btn-success">Confirmar execução</button>
            <div class="form-text">A situação financeira, a vigência e o saldo são verificados novamente agora.</div>
        </form></div>
        @endcan
        <div class="col-md-5"><form method="post" action="{{ route('benefits.release', $a) }}" class="card card-body">@csrf
            <div class="fw-semibold mb-2">Cancelar reserva</div>
            <input name="reason" class="form-control mb-2" placeholder="Motivo" required>
            <button class="btn btn-outline-danger">Cancelar reserva</button>
        </form></div>
    </div>
@endif

@if ($a->status->value === 'consumed' && $a->ledger->where('type', 'reversal')->isEmpty())
    @can('benefits.reverse')
    <form method="post" action="{{ route('benefits.reverse', $a) }}" class="card card-body mt-3" data-confirm="Registrar estorno desta utilização?">@csrf
        <div class="fw-semibold mb-2">Estornar utilização</div>
        <input name="reason" class="form-control mb-2" placeholder="Motivo do estorno" required>
        <button class="btn btn-outline-danger">Estornar</button>
        <div class="form-text">O atendimento não é apagado: é criado um lançamento inverso no mesmo período.</div>
    </form>
    @endcan
@endif

<div class="card mt-3"><div class="card-header">Lançamentos</div>
    <ul class="list-group list-group-flush small">
        @forelse ($a->ledger as $l)
            <li class="list-group-item">{{ $l->type === 'consume' ? 'Consumo' : 'Estorno' }} {{ $l->quantity }} — @datetime($l->occurred_at) por {{ $l->actor->name }} {{ $l->reason ? '· '.$l->reason : '' }}</li>
        @empty
            <li class="list-group-item text-muted">Nenhum lançamento.</li>
        @endforelse
    </ul></div>
</div></div>
@endsection
