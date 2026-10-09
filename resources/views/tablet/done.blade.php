@extends('layouts.app')
@section('title', 'Assinatura registrada')
@section('content')
<div class="text-center py-5">
    <div class="display-4 text-success"><i class="bi bi-check-circle"></i></div>
    <h1 class="h3">Assinatura registrada</h1>
    <p class="text-muted">Contrato {{ $contract->number }}. O documento final será anexado automaticamente.<br>
        O plano é ativado somente após a confirmação do primeiro pagamento.</p>
    <a href="{{ route('proposals.show', $contract->proposal_id) }}" class="btn btn-primary">Voltar para a proposta (equipe)</a>
</div>
@endsection
