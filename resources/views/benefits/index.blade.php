@extends('layouts.app')
@section('title', 'Autorização de atendimento')
@section('content')
<h1 class="h4 mb-3">Autorização de atendimento</h1>
<form class="card card-body mb-3" method="get">
    <div class="input-group input-group-lg">
        <input type="search" name="q" value="{{ $q }}" class="form-control" placeholder="CPF do tutor ou nome" autofocus>
        <button class="btn btn-primary">Consultar</button>
    </div>
</form>
@foreach ($results as $r)
    @php($c = $r['customer'])
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between">
            <span>{{ $c->name }} <span class="text-muted small">CPF {{ $c->maskedDocument() }}</span></span>
            @if ($r['financial']->isDelinquent())<span class="badge text-bg-danger">Inadimplente — serviços e benefícios bloqueados</span>
            @elseif (! $r['financial']->isRegular())<span class="badge text-bg-warning">Verificação financeira pendente</span>
            @else<span class="badge text-bg-success">Regular</span>@endif
        </div>
        @if (! $r['financial']->isRegular())
            <div class="alert alert-danger rounded-0 mb-0 small">{{ $r['financial']->blockMessage() }} Oriente a regularização com a área financeira ou a unidade responsável.</div>
        @endif
        <div class="list-group list-group-flush">
            @forelse ($c->subscriptions as $s)
                <a href="{{ route('benefits.subscription', $s) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span><strong>{{ $s->pet->name }}</strong> — {{ $s->planVersion->plan->name }}</span>
                    <x-status :value="$s->status" />
                </a>
            @empty
                <div class="list-group-item text-muted">Nenhum plano para este tutor.</div>
            @endforelse
        </div>
    </div>
@endforeach
@if ($q !== '' && $results->isEmpty())<div class="alert alert-secondary">Nenhum tutor encontrado.</div>@endif
@endsection
