@extends('layouts.app')
@section('title', 'Assinaturas')
@section('content')
<h1 class="h4 mb-3">Assinaturas</h1>
<form class="card card-body mb-3" method="get">
    <div class="row g-2"><div class="col-md-4"><select name="status" class="form-select"><option value="">Todas as situações</option>
        @foreach (\App\Enums\SubscriptionStatus::options() as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-md-2"><button class="btn btn-outline-primary w-100">Filtrar</button></div></div>
</form>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr><th>Número</th><th>Tutor</th><th>Pet</th><th>Plano</th><th>Unidade</th><th>Situação</th><th>Início</th></tr></thead>
        <tbody>
        @forelse ($subscriptions as $s)
            <tr>
                <td><a href="{{ route('subscriptions.show', $s) }}">{{ $s->number }}</a></td>
                <td>{{ $s->customer->name }}</td>
                <td>{{ $s->pet->name }}</td>
                <td>{{ $s->planVersion->plan->name }} <span class="small text-muted">v{{ $s->planVersion->version }}</span></td>
                <td class="small">{{ $s->originatingFranchise->name }}</td>
                <td><x-status :value="$s->status" /></td>
                <td class="small">@date($s->effective_start_at)</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma assinatura.</td></tr>
        @endforelse
        </tbody>
    </table>
</div></div>
<div class="mt-3">{{ $subscriptions->links() }}</div>
@endsection
