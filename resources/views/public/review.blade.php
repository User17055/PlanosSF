@extends('layouts.public')
@section('title', 'Revisão e aceite')
@section('content')
@php($s = $proposal->conditions_snapshot)
@php($c = $merged['customer'])
<div class="small text-muted">Proposta {{ $proposal->number }}</div>
<h1 class="h4">Etapa 2 de 3 — revise e aceite</h1>

<div class="row g-3 mb-3">
    <div class="col-md-6"><div class="card card-body h-100">
        <div class="small-label">Titular</div>
        <div class="fw-semibold">{{ $c['name'] ?? '' }}</div>
        <div class="small">CPF {{ \App\Support\Mask::document($c['document'] ?? null) }} · {{ \App\Support\Mask::email($c['email'] ?? null) }}</div>
        <div class="small-label mt-2">Pet coberto</div>
        <div class="fw-semibold">{{ $merged['pet']['name'] ?? '' }} <span class="small fw-normal">({{ \App\Enums\Species::tryFrom($merged['pet']['species'] ?? '')?->label() }})</span></div>
        <a href="{{ route('public.proposal', ['token' => $token, 'editar' => 1]) }}" class="small mt-2">Corrigir dados</a>
    </div></div>
    <div class="col-md-6"><div class="card card-body h-100">
        <div class="small-label">Plano</div>
        <div class="fw-semibold">{{ $s['version']['display_name'] }}</div>
        <div>Mensalidade <strong>@money($proposal->price_cents)</strong> · cobrança {{ $s['billing_interval_months'] == 1 ? 'mensal' : 'a cada '.$s['billing_interval_months'].' meses' }}</div>
        <div class="small">Adesão @money($proposal->enrollment_fee_cents) · contrato de {{ $s['contract_duration_months'] }} meses</div>
        <div class="small text-muted mt-1">Proposta válida até @datetime($proposal->expires_at)</div>
    </div></div>
</div>

@if ($missing)<div class="alert alert-warning">Faltam dados: {{ implode(', ', $missing) }}. <a href="{{ route('public.proposal', ['token' => $token, 'editar' => 1]) }}">Completar</a></div>@endif

<div class="card mb-3"><div class="card-header">Benefícios, limites e carências</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Benefício</th><th>Limite</th><th>Carência</th></tr></thead>
        <tbody>@foreach ($s['benefits'] as $b)
            <tr><td>{{ $b['description'] }}</td><td>{{ $b['quantity_limit'] ? $b['quantity_limit'].' '.$b['unit'].' / '.mb_strtolower($b['period_label'] ?? '') : 'sem limite' }}</td><td>{{ $b['waiting_days'] }} dias</td></tr>
        @endforeach</tbody>
    </table></div>
</div>

<div class="card mb-3"><div class="card-header">Contrato integral</div>
    <div class="card-body"><div class="contract-text" tabindex="0">{{ $contractText }}</div></div></div>

<div class="card mb-3"><div class="card-body">
    @if ($otpRequired)
        <div class="mb-3">
            <div class="fw-semibold">Confirmação do seu contato</div>
            @if ($verification && $verification->isVerified())
                <div class="text-success"><i class="bi bi-check-circle"></i> E-mail {{ $verification->destination_masked }} confirmado.</div>
            @else
                <form method="post" action="{{ route('public.code.send', $token) }}" class="d-inline">@csrf
                    <button class="btn btn-outline-primary btn-sm">{{ $verification ? 'Reenviar código' : 'Enviar código para meu e-mail' }}</button></form>
                @if ($verification)
                    <form method="post" action="{{ route('public.code.verify', $token) }}" class="d-flex gap-2 mt-2" style="max-width: 320px">@csrf
                        <input name="code" class="form-control" inputmode="numeric" maxlength="6" placeholder="Código de 6 dígitos" autocomplete="one-time-code" required>
                        <button class="btn btn-primary">Confirmar</button></form>
                @endif
            @endif
        </div>
    @endif

    <form method="post" action="{{ route('public.accept', $token) }}" data-once>@csrf
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="terms" value="1" id="terms">
            <label class="form-check-label" for="terms">Li o contrato integral, conferi meus dados e concordo com as condições.</label>
        </div>
        <button class="btn btn-primary btn-lg" @disabled($missing || ($otpRequired && ! ($verification && $verification->isVerified())))>Aceitar e continuar para o pagamento</button>
        <div class="form-text">O plano é ativado somente após a confirmação do primeiro pagamento.</div>
    </form>
</div></div>
@endsection
