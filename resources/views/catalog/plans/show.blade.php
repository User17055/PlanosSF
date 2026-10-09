@extends('layouts.app')
@section('title', $plan->name)
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><h1 class="h4 mb-0">{{ $plan->name }}</h1><code>{{ $plan->code }}</code> · {{ $plan->status === 'active' ? 'Ativo' : 'Inativo' }}</div>
    @can('plans.manage')
    <form method="post" action="{{ route('plans.status', $plan) }}" data-confirm="Alterar a situação do plano?">@csrf
        <button class="btn btn-outline-secondary">{{ $plan->status === 'active' ? 'Inativar plano' : 'Reativar plano' }}</button></form>
    @endcan
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Versão</th><th>Nome de exibição</th><th>Mensalidade</th><th>Benefícios</th><th>Situação</th><th>Publicada</th><th>Oferta</th><th></th></tr></thead>
            <tbody>
            @foreach ($plan->versions as $v)
                <tr>
                    <td>v{{ $v->version }}</td>
                    <td>{{ $v->display_name }}</td>
                    <td>@money($v->price_cents)</td>
                    <td>{{ $v->benefit_rules_count }}</td>
                    <td><x-status :value="$v->status" /></td>
                    <td class="small">@datetime($v->published_at)</td>
                    <td class="small">@date($v->offer_starts_at) – {{ $v->offer_ends_at ? \App\Support\BusinessTime::formatDate($v->offer_ends_at) : 'sem fim' }}</td>
                    <td class="text-end"><a href="{{ route('versions.show', $v) }}" class="btn btn-sm btn-outline-primary">Abrir</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
