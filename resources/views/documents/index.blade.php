@extends('layouts.app')
@section('title', 'Conferência de documentos')
@section('content')
<h1 class="h4 mb-3">Conferência de documentos em papel</h1>
<p class="text-muted">Confira integridade, legibilidade, identificação e assinatura. Quem anexou e quem validou ficam registrados.</p>
@forelse ($files as $f)
    @php($p = $f->contract->proposal)
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between">
            <span>Contrato {{ $f->contract->number }} · proposta <a href="{{ route('proposals.show', $p) }}">{{ $p->number }}</a> · digitalização v{{ $f->version }}</span>
            <span class="small text-muted">enviado por {{ $f->uploader?->name }} em @datetime($f->created_at)</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5 small">
                    <div><strong>{{ $p->customer?->name }}</strong> · pet {{ $p->pet?->name }}</div>
                    <div>Plano: {{ $f->contract->frozen_data['conditions']['version']['display_name'] }} v{{ $f->contract->frozen_data['conditions']['version']['number'] }}</div>
                    <div>{{ $f->contract->evidence->last()?->affirmative_action }}</div>
                    <div class="mt-2"><a href="{{ route('documents.download', $f) }}" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-eye"></i> Abrir digitalização</a>
                        @if ($print = $f->contract->files()->where('kind', 'print_pdf')->first())
                            <a href="{{ route('documents.download', $print) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Via original</a>
                        @endif</div>
                    @if ($f->replacement_reason)<div class="text-muted mt-2">Substitui versão anterior: {{ $f->replacement_reason }}</div>@endif
                </div>
                <div class="col-md-7">
                    <form method="post" action="{{ route('documents.review', $f) }}" data-once>@csrf
                        @foreach (['integridade' => 'Todas as páginas presentes e na ordem', 'legibilidade' => 'Texto legível', 'identificacao' => 'Proposta, versão e titular corretos', 'assinatura' => 'Assinatura do titular presente'] as $k => $l)
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="checks[]" value="{{ $k }}" id="{{ $k }}{{ $f->id }}"><label class="form-check-label" for="{{ $k }}{{ $f->id }}">{{ $l }}</label></div>
                        @endforeach
                        <input name="reason" class="form-control form-control-sm my-2" placeholder="Observação (obrigatória na rejeição)">
                        <button name="decision" value="approve" class="btn btn-success btn-sm">Aprovar</button>
                        <button name="decision" value="reject" class="btn btn-outline-danger btn-sm">Rejeitar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@empty
    <div class="alert alert-success">Nenhum documento aguardando conferência.</div>
@endforelse
@endsection
