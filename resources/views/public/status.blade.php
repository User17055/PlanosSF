@extends('layouts.public')
@section('title', 'Situação da contratação')
@section('content')
@php($sub = $subscription)
<div class="small text-muted">Proposta {{ $proposal->number }}</div>
@if ($sub && $sub->isActive())
    <div class="text-center py-4">
        <div class="display-5 text-success"><i class="bi bi-shield-check"></i></div>
        <h1 class="h3">Plano ativo!</h1>
        <p>Carteirinha <strong>{{ $sub->card_number }}</strong> · vigência desde @datetime($sub->effective_start_at)</p>
        <p class="text-muted small">Os benefícios ficam disponíveis conforme as carências do contrato. Acompanhe tudo pela área do cliente.</p>
        <a href="{{ route('portal.login') }}" class="btn btn-primary">Acessar área do cliente</a>
    </div>
@elseif ($sub && $sub->status->value === 'awaiting_regularization')
    <h1 class="h4">Etapa 3 de 3 — pagamento</h1>
    @if ($invoice && $invoice->status->value === 'paid')
        <div class="alert alert-info" data-poll-status="{{ route('public.status', $token) }}"><span class="spinner-border spinner-border-sm"></span>
            Pagamento confirmado. Estamos concluindo a ativação (<span data-poll-label>{{ $sub->status->label() }}</span>)...</div>
    @elseif ($proposal->channel->value === 'in_person')
        <div class="alert alert-info">Contrato registrado. O pagamento é feito na unidade e confirmado pelo financeiro.</div>
    @else
        <div class="card card-body mb-3">
            <div>Valor da primeira cobrança: <strong>@money($invoice?->amount_cents ?? ($proposal->price_cents + $proposal->enrollment_fee_cents))</strong></div>
            <form method="post" action="{{ route('public.checkout', $token) }}" class="mt-3" data-once>@csrf
                <button class="btn btn-primary btn-lg"><i class="bi bi-credit-card"></i> Ir para o pagamento seguro</button></form>
            <div class="form-text">O pagamento é feito na página do provedor; não armazenamos dados de cartão.</div>
        </div>
        <div class="alert alert-light border small" data-poll-status="{{ route('public.status', $token) }}">
            Já pagou? A confirmação pode levar alguns instantes: <strong data-poll-label>pagamento em confirmação</strong>. Você pode fechar esta página e voltar depois pelo mesmo link.
        </div>
    @endif
@else
    <h1 class="h4">Contratação registrada</h1>
    <p>Situação: <strong>{{ $sub?->status->label() ?? $proposal->status->label() }}</strong></p>
    @if ($proposal->signature_method->value === 'paper')<p class="text-muted">O contrato em papel será conferido pela unidade.</p>@endif
@endif

@if ($finalFile)
    <a href="{{ route('public.contract', $token) }}" class="btn btn-outline-secondary mt-2"><i class="bi bi-file-earmark-pdf"></i> Baixar cópia do contrato aceito</a>
@elseif ($proposal->contract && $proposal->signature_method->value !== 'paper')
    <p class="small text-muted mt-2">Seu contrato está sendo gerado e ficará disponível aqui e na área do cliente.</p>
@endif
@endsection
