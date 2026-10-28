@extends('layouts.app')
@section('title', 'Assinatura no tablet')
@push('head')@vite('resources/js/signature.js')@endpush
@section('content')
@php($s = $proposal->conditions_snapshot)
<div class="row justify-content-center"><div class="col-xl-9">
<h1 class="h4">Confira seus dados e o contrato</h1>
<p class="text-muted">Proposta {{ $proposal->number }} · {{ $s['version']['display_name'] }}</p>

@if ($missing)
    <div class="alert alert-danger">Cadastro incompleto: {{ implode(', ', $missing) }}. Corrija antes de assinar.</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-6"><div class="card h-100"><div class="card-body">
        <div class="small-label">Titular</div>
        <div class="fs-5">{{ $merged['customer']['name'] ?? '' }}</div>
        <div>CPF {{ \App\Support\Mask::document($merged['customer']['document'] ?? null) }}</div>
        <div>{{ $merged['customer']['email'] ?? '' }} · {{ $merged['customer']['phone'] ?? '' }}</div>
        <div class="small">{{ collect([$merged['customer']['street'] ?? null, $merged['customer']['number'] ?? null, $merged['customer']['city'] ?? null])->filter()->implode(', ') }}</div>
    </div></div></div>
    <div class="col-md-6"><div class="card h-100"><div class="card-body">
        <div class="small-label">Pet coberto</div>
        <div class="fs-5">{{ $merged['pet']['name'] ?? '' }}</div>
        <div>{{ \App\Enums\Species::tryFrom($merged['pet']['species'] ?? '')?->label() }} {{ $merged['pet']['breed'] ?? '' }}</div>
        <div class="small-label mt-2">Valores</div>
        <div>Mensalidade <strong>@money($proposal->price_cents)</strong> · adesão @money($proposal->enrollment_fee_cents)</div>
    </div></div></div>
</div>

<div class="card mb-3"><div class="card-header">Benefícios, limites e carências</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Benefício</th><th>Cobertura</th><th>Limite</th><th>Carência</th></tr></thead>
        <tbody>@foreach ($s['benefits'] as $b)
            <tr><td>{{ $b['description'] }}</td><td>{{ $b['coverage_label'] }}{{ $b['discount_percent'] ? ' '.\App\Support\BenefitText::percent($b['discount_percent']) : '' }}</td>
                <td>{{ $b['quantity_limit'] ? \App\Support\BenefitText::quantity($b['quantity_limit'], $b['unit']).' / '.mb_strtolower($b['period_label'] ?? '') : 'sem limite' }}</td>
                <td>{{ $b['waiting_days'] }} dias</td></tr>
        @endforeach</tbody>
    </table></div>
</div>

<div class="card mb-3"><div class="card-header">Contrato integral</div>
    <div class="card-body"><div class="contract-text">{{ $preview }}</div></div>
</div>

<form method="post" action="{{ route('tablet.sign', $proposal) }}" class="card card-body" data-once>
    @csrf
    <input type="hidden" name="signature">
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="terms" value="1" id="terms">
        <label class="form-check-label" for="terms">Li o contrato, conferi os dados e concordo com as condições.</label>
    </div>
    <label class="form-label">Assine no quadro abaixo</label>
    <canvas class="signature-pad" data-signature-pad></canvas>
    <div class="text-danger small d-none mt-1" data-signature-error>Assine no quadro antes de concluir.</div>
    <div class="d-flex justify-content-between mt-3">
        <button type="button" class="btn btn-outline-secondary" data-signature-clear>Limpar assinatura</button>
        <button type="submit" class="btn btn-primary btn-lg" @disabled($missing)>Concluir assinatura</button>
    </div>
    <div class="form-text">A assinatura só é considerada concluída quando esta tela confirmar o registro. Em caso de queda de conexão, não repita a assinatura antes de conferir a proposta.</div>
</form>
</div></div>
@endsection
