@extends('layouts.public')
@section('title', $version->display_name)
@section('content')
<a href="{{ route('site.plans') }}" class="small">&larr; todos os planos</a>
<h1 class="h3 mt-2">{{ $version->display_name }}</h1>
<p>{{ $version->description }}</p>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card card-body"><div class="small-label">Mensalidade</div><div class="fs-4 fw-bold">@money($version->effectivePriceCents())</div>
        @if ($version->promo_price_cents)<div class="small text-muted">Preço promocional ({{ $version->promo_origin }}), por {{ $version->promo_duration_months }} mês(es). Preço-base @money($version->price_cents).</div>@endif</div></div>
    <div class="col-md-4"><div class="card card-body"><div class="small-label">Adesão</div><div class="fs-4 fw-bold">@money($version->enrollment_fee_cents)</div></div></div>
    <div class="col-md-4"><div class="card card-body"><div class="small-label">Contrato</div><div>{{ $version->contract_duration_months }} meses · cobrança {{ $version->billing_interval_months == 1 ? 'mensal' : 'a cada '.$version->billing_interval_months.' meses' }}</div></div></div>
</div>
<div class="card mb-3"><div class="card-header">Benefícios, limites e carências</div>
    <div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>Benefício</th><th>Cobertura</th><th>Limite</th><th>Carência</th></tr></thead>
        <tbody>@foreach ($version->benefitRules as $b)
            <tr><td>{{ $b->description }}@if ($b->exclusions)<div class="small text-muted">Exclusões: {{ $b->exclusions }}</div>@endif</td>
                <td>{{ $b->coverage_type->label() }}{{ $b->discount_percent ? ' '.$b->discount_percent.'%' : '' }}@if ($b->copay_cents)<div class="small">coparticipação @money($b->copay_cents)</div>@endif</td>
                <td>{{ $b->isUnlimited() ? 'Sem limite de quantidade' : $b->quantity_limit.' '.$b->unit.' por '.mb_strtolower($b->period_type->label()) }}</td>
                <td>{{ $b->waiting_days }} dias</td></tr>
        @endforeach</tbody>
    </table></div>
</div>
<div class="card card-body mb-3 small">
    <div><strong>Quem pode contratar:</strong> {{ collect($version->eligible_species)->map(fn($s) => \App\Enums\Species::from($s)->label())->implode(', ') }}
        @if ($version->min_age_months !== null) · a partir de {{ $version->min_age_months }} meses @endif
        @if ($version->max_age_months !== null) · até {{ $version->max_age_months }} meses @endif</div>
    <div><strong>Renovação:</strong> {{ $version->renewal_policy }}</div>
    <div><strong>Início da cobertura:</strong> após contrato assinado e primeiro pagamento confirmado. As carências contam a partir desse início.</div>
</div>
<form method="post" action="{{ route('site.start', $version) }}" data-once>@csrf
    <button class="btn btn-primary btn-lg">Contratar este plano</button>
    <span class="small text-muted ms-2">Cada contratação cobre um pet.</span>
</form>
@endsection
