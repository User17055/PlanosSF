@extends('layouts.app')
@section('title', 'Auditoria')
@section('content')
<h1 class="h4 mb-3">Auditoria</h1>
<form class="card card-body mb-3" method="get"><div class="row g-2">
    <div class="col-md-4"><input name="action" class="form-control" value="{{ request('action') }}" placeholder="Ação (ex.: contract., benefit.)"></div>
    <div class="col-md-3"><input name="object" class="form-control" value="{{ request('object') }}" placeholder="Objeto (ex.: Subscription#12)"></div>
    <div class="col-md-3"><input name="correlation" class="form-control" value="{{ request('correlation') }}" placeholder="Correlation ID"></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100">Filtrar</button></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Quando</th><th>Ator</th><th>Unidade</th><th>Ação</th><th>Objeto</th><th>Alterações</th><th>Motivo</th><th>IP</th></tr></thead>
    <tbody>
    @foreach ($logs as $l)
        <tr>
            <td class="small text-nowrap">@datetime($l->created_at)</td>
            <td class="small">{{ $l->user?->name ?? $l->actor_type }}</td>
            <td class="small">{{ $l->franchise?->name }}</td>
            <td><code>{{ $l->action }}</code></td>
            <td class="small">{{ $l->auditable_type }}{{ $l->auditable_id ? '#'.$l->auditable_id : '' }}</td>
            <td class="small" style="max-width: 360px"><code class="text-wrap">{{ $l->changes ? json_encode($l->changes, JSON_UNESCAPED_UNICODE) : '' }}</code></td>
            <td class="small">{{ $l->reason }}</td>
            <td class="small text-muted">{{ $l->ip_address }}<br><a href="{{ route('audit.index', ['correlation' => $l->correlation_id]) }}">{{ substr((string) $l->correlation_id, 0, 8) }}</a></td>
        </tr>
    @endforeach
    </tbody>
</table></div></div>
<div class="mt-3">{{ $logs->links() }}</div>
@endsection
