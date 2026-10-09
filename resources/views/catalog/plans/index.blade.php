@extends('layouts.app')
@section('title', 'Catálogo de planos')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Catálogo de planos</h1>
    <div class="d-flex gap-2">
        @can('templates.manage')<a href="{{ route('templates.index') }}" class="btn btn-outline-secondary"><i class="bi bi-file-text"></i> Modelos de contrato</a>@endcan
        @can('plans.manage')<a href="{{ route('plans.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Novo plano</a>@endcan
    </div>
</div>
<div class="alert alert-info small">Valores, coberturas e textos contratuais precisam ser cadastrados e aprovados pela São Francisco antes da operação. Versões publicadas são imutáveis: alterações criam nova versão.</div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Código</th><th>Plano</th><th>Situação</th><th>Versões</th><th>Em oferta</th><th></th></tr></thead>
            <tbody>
            @forelse ($plans as $plan)
                @php($offer = $plan->versions->first(fn($v) => $v->isOfferable()))
                <tr>
                    <td><code>{{ $plan->code }}</code></td>
                    <td>{{ $plan->name }}</td>
                    <td>{{ $plan->status === 'active' ? 'Ativo' : 'Inativo' }}</td>
                    <td>@foreach ($plan->versions as $v)<x-status :value="$v->status" class="me-1" /> @endforeach</td>
                    <td>@if ($offer) v{{ $offer->version }} — @money($offer->effectivePriceCents()) @else <span class="text-muted">—</span> @endif</td>
                    <td class="text-end"><a href="{{ route('plans.show', $plan) }}" class="btn btn-sm btn-outline-primary">Abrir</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted text-center py-4">Nenhum plano cadastrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
