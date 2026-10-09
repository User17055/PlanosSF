@extends('layouts.public')
@section('title', 'Meus planos')
@section('nav-right')<form method="post" action="{{ route('portal.logout') }}">@csrf<button class="btn btn-sm btn-light">Sair</button></form>@endsection
@section('content')
<h1 class="h4">Olá, {{ \Illuminate\Support\Str::of($customer->name)->before(' ') }}</h1>

@if ($financial->isDelinquent())
    <div class="alert alert-danger"><strong>Existe mensalidade vencida.</strong> Os serviços e benefícios de todos os seus planos ficam bloqueados até a regularização. Você pode pagar abaixo.</div>
@endif

@if ($openInvoices->isNotEmpty())
<div class="card mb-3"><div class="card-header">Parcelas em aberto</div>
    <ul class="list-group list-group-flush">
        @foreach ($openInvoices as $i)
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>Vencimento {{ $i->due_date->format('d/m/Y') }} — @money($i->outstandingCents()) @if ($i->isOverdue())<span class="badge text-bg-danger">vencida</span>@endif</span>
                @if ($i->payment_url || ($i->is_initial && $i->provider !== 'manual'))
                    <form method="post" action="{{ route('portal.pay', $i) }}">@csrf<button class="btn btn-sm btn-primary">Pagar</button></form>
                @else<span class="small text-muted">pagamento na unidade</span>@endif
            </li>
        @endforeach
    </ul></div>
@endif

<div class="card mb-3"><div class="card-header">Meus pets e planos</div>
    <div class="list-group list-group-flush">
        @forelse ($subscriptions as $s)
            <a href="{{ route('portal.subscription', $s) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                <span><strong>{{ $s->pet->name }}</strong> — {{ $s->planVersion->plan->name }}</span><x-status :value="$s->status" />
            </a>
        @empty
            <div class="list-group-item text-muted">Nenhum plano.</div>
        @endforelse
    </div></div>

<details class="card card-body">
    <summary>Atualizar telefone e endereço</summary>
    <form method="post" action="{{ route('portal.contact') }}" class="row g-2 mt-2">@csrf
        <div class="col-md-4"><input name="phone" class="form-control" value="{{ $customer->phone }}" placeholder="Celular" required></div>
        <div class="col-md-3"><input name="zip_code" class="form-control" value="{{ $customer->zip_code }}" placeholder="CEP" required></div>
        <div class="col-md-5"><input name="street" class="form-control" value="{{ $customer->street }}" placeholder="Rua" required></div>
        <div class="col-md-2"><input name="number" class="form-control" value="{{ $customer->number }}" placeholder="Nº" required></div>
        <div class="col-md-4"><input name="complement" class="form-control" value="{{ $customer->complement }}" placeholder="Complemento"></div>
        <div class="col-md-3"><input name="district" class="form-control" value="{{ $customer->district }}" placeholder="Bairro"></div>
        <div class="col-md-2"><input name="city" class="form-control" value="{{ $customer->city }}" placeholder="Cidade" required></div>
        <div class="col-md-1"><input name="state" class="form-control" value="{{ $customer->state }}" maxlength="2" placeholder="UF" required></div>
        <div class="col-12"><button class="btn btn-outline-primary">Salvar</button></div>
    </form>
</details>
@endsection
