@extends('layouts.app')
@section('title', 'Relatórios')
@section('content')
<h1 class="h4 mb-1">Relatórios</h1>
<p class="text-muted small">Gerado em @datetime($generatedAt) · período {{ \Carbon\Carbon::parse($filters['from'])->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($filters['to'])->format('d/m/Y') }}
    · {{ $filters['franchise_id'] ? 'unidade #'.$filters['franchise_id'] : 'toda a rede' }}</p>
<form class="card card-body mb-3" method="get"><div class="row g-2">
    <div class="col-md-3"><label class="form-label small">De</label><input type="date" name="from" class="form-control" value="{{ $filters['from'] }}"></div>
    <div class="col-md-3"><label class="form-label small">Até</label><input type="date" name="to" class="form-control" value="{{ $filters['to'] }}"></div>
    @if (app(\App\Services\Access\FranchiseContext::class)->isCentral())
        <div class="col-md-3"><label class="form-label small">Unidade</label><select name="franchise_id" class="form-select"><option value="">Toda a rede</option>
            @foreach (\App\Models\Franchise::orderBy('name')->get() as $f)<option value="{{ $f->id }}" @selected($filters['franchise_id'] == $f->id)>{{ $f->name }}</option>@endforeach</select></div>
    @endif
    <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100">Aplicar filtros</button></div>
</div></form>

<div class="row g-3 mb-3">
    @foreach (['prevista' => 'Soma das parcelas não canceladas com vencimento no período.', 'faturada' => 'Parcelas emitidas no provedor com vencimento no período.', 'recebida' => 'Transações confirmadas com data de pagamento no período (valor pago pelo tutor, sem descontar taxas).', 'estornada' => 'Transações estornadas ou contestadas com data de pagamento no período.'] as $k => $def)
        <div class="col-md-3"><div class="card card-body h-100"><div class="small-label">Receita {{ $k }}</div><div class="fs-4 fw-bold">@money($revenue[$k])</div><div class="small text-muted">{{ $def }}</div></div></div>
    @endforeach
</div>

@php($titles = ['propostas' => ['Propostas por canal e vendedor', 'Propostas criadas no período, agrupadas por canal, vendedor e situação atual.'],
    'assinaturas' => ['Assinaturas por situação', 'Situação atual de todas as assinaturas. Assinaturas ativas não equivalem a pets atendidos.'],
    'contratos_pendentes' => ['Contratos pendentes', 'Assinaturas cujo documento ainda não está válido.'],
    'parcelas_vencidas' => ['Parcelas vencidas', 'Saldo exigível após o fim do dia de vencimento (America/Sao_Paulo).'],
    'utilizacao' => ['Utilização por benefício e unidade', 'Soma de consumos menos estornos no período, por unidade executora; pets = pets distintos atendidos.']])
@foreach ($tables as $key => $rows)
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>{{ $titles[$key][0] }} <span class="small text-muted fw-normal">— {{ $titles[$key][1] }}</span></span>
            <a href="{{ route('reports.export', ['report' => $key] + $filters) }}" class="btn btn-sm btn-outline-secondary">CSV</a>
        </div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            @if ($rows)
                <thead><tr>@foreach (array_keys($rows[0]) as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($rows as $r)<tr>@foreach ($r as $v)<td>{{ $v }}</td>@endforeach</tr>@endforeach</tbody>
            @else
                <tbody><tr><td class="text-muted text-center py-3">Sem dados.</td></tr></tbody>
            @endif
        </table></div>
    </div>
@endforeach
@endsection
