@extends('layouts.app')
@section('title', 'Propostas e links')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Propostas e links</h1>
    @can('proposals.create')<a href="{{ route('proposals.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nova contratação</a>@endcan
</div>
<form class="card card-body mb-3" method="get">
    <div class="row g-2">
        <div class="col-md-4"><select name="status" class="form-select"><option value="">Todas as situações</option>
            @foreach (\App\Enums\ProposalStatus::options() as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-md-3"><select name="channel" class="form-select"><option value="">Todos os canais</option>
            @foreach (\App\Enums\ProposalChannel::options() as $v => $l)<option value="{{ $v }}" @selected(request('channel') === $v)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-md-3 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="mine" value="1" id="mine" @checked(request('mine'))><label for="mine" class="form-check-label">Somente minhas</label></div></div>
        <div class="col-md-2"><button class="btn btn-outline-primary w-100">Filtrar</button></div>
    </div>
</form>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead><tr><th>Proposta</th><th>Cliente / pet</th><th>Plano</th><th>Canal</th><th>Vendedor</th><th>Situação</th><th>Link</th><th>Validade</th></tr></thead>
        <tbody>
        @forelse ($proposals as $p)
            <tr>
                <td><a href="{{ route('proposals.show', $p) }}">{{ $p->number }}</a></td>
                <td>{{ $p->customer?->name ?? '—' }}<div class="small text-muted">{{ $p->pet?->name }}</div></td>
                <td>{{ $p->planVersion->plan->name }} <span class="text-muted small">v{{ $p->planVersion->version }}</span></td>
                <td class="small">{{ $p->channel->label() }}<br><span class="text-muted">{{ $p->signature_method->label() }}</span></td>
                <td class="small">{{ $p->seller?->name ?? '—' }}<br><span class="text-muted">{{ $p->originatingFranchise->name }}</span></td>
                <td><x-status :value="$p->status" /></td>
                <td class="small">
                    @if ($p->token_hash)
                        @if ($p->linkIsUsable()) válido até @datetime($p->token_expires_at) @elseif ($p->token_revoked_at) revogado @else expirado @endif
                        @if ($p->link_first_opened_at)<br><span class="text-muted">aberto @datetime($p->link_first_opened_at)</span>@endif
                    @else — @endif
                </td>
                <td class="small">@datetime($p->expires_at)</td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma proposta.</td></tr>
        @endforelse
        </tbody>
    </table>
</div></div>
<div class="mt-3">{{ $proposals->links() }}</div>
@endsection
