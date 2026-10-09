@extends('layouts.public')
@section('title', 'Pagamento (simulador)')
@section('content')
<div class="row justify-content-center"><div class="col-md-7">
<div class="card">
    <div class="card-header bg-warning-subtle">Simulador de pagamento — homologação</div>
    <div class="card-body">
        <div class="small text-muted">Fatura {{ $bill->externalId }} · ciclo {{ $bill->cycle }} · vencimento {{ \Carbon\Carbon::parse($bill->dueDate)->format('d/m/Y') }}</div>
        <div class="fs-3 fw-bold my-2">@money($bill->amountCents)</div>
        <div class="mb-3">Situação no provedor: <strong>{{ $bill->providerStatus }}</strong></div>
        @if ($bill->status === 'pending')
            <form method="post" action="{{ $payUrl }}" class="d-flex gap-2 flex-wrap" data-once>@csrf
                <button name="action" value="pay" class="btn btn-success">Simular pagamento aprovado</button>
                <button name="action" value="reject" class="btn btn-outline-danger">Simular recusa do cartão</button>
            </form>
        @endif
        <hr>
        <div class="small-label mb-2">Ferramentas de teste</div>
        <form method="post" action="{{ $payUrl }}" class="d-flex gap-2 flex-wrap">@csrf
            @if ($bill->status === 'pending')<button name="action" value="overdue" class="btn btn-sm btn-outline-secondary">Tornar vencida</button>@endif
            @if ($bill->status === 'paid')<button name="action" value="refund" class="btn btn-sm btn-outline-secondary">Simular estorno</button>@endif
            <button name="action" value="next" class="btn btn-sm btn-outline-secondary">Emitir próxima mensalidade</button>
        </form>
        <p class="small text-muted mt-3 mb-0">Assim como no provedor real, a confirmação chega ao sistema por webhook. Voltar para o site não confirma pagamento.</p>
    </div>
</div>
</div></div>
@endsection
