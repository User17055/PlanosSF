@extends('layouts.public')
@section('title', 'Planos de saúde pet')
@section('nav-right')<a href="{{ route('portal.login') }}" class="btn btn-sm btn-light">Área do cliente</a>@endsection
@section('content')
<div class="public-hero rounded-3 p-4 p-md-5 mb-4">
    <h1 class="h2">Planos de saúde para o seu pet</h1>
    <p class="mb-0 opacity-75">Veja coberturas, limites e carências antes de contratar. A contratação é feita online, com contrato e pagamento seguros.</p>
</div>
<div class="row g-3">
    @forelse ($versions as $v)
        <div class="col-md-6"><div class="card h-100">
            <div class="card-body">
                <h2 class="h5">{{ $v->display_name }}</h2>
                <div class="fs-3 fw-bold text-primary">@money($v->effectivePriceCents())<span class="fs-6 text-muted fw-normal"> / {{ $v->billing_interval_months == 1 ? 'mês' : $v->billing_interval_months.' meses' }}</span></div>
                @if ($v->enrollment_fee_cents)<div class="small text-muted">Adesão @money($v->enrollment_fee_cents)</div>@endif
                <p class="small mt-2">{{ \Illuminate\Support\Str::limit($v->description, 180) }}</p>
                <ul class="small mb-0">@foreach ($v->benefitRules->take(5) as $b)<li>{{ $b->description }}</li>@endforeach</ul>
            </div>
            <div class="card-footer bg-white border-0 pb-3"><a href="{{ route('site.plan', $v) }}" class="btn btn-primary w-100">Ver detalhes e contratar</a></div>
        </div></div>
    @empty
        <div class="col-12"><div class="alert alert-secondary">Nenhum plano disponível no momento.</div></div>
    @endforelse
</div>
@endsection
