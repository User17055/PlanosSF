@extends('layouts.app')
@section('title', 'Integrações')
@section('content')
<h1 class="h4 mb-3">Integrações</h1>
<div class="alert alert-info small">SimplesVet e TudoPraPet: sem API confirmada para este projeto, as operações aparecem como <strong>tarefas manuais auditadas</strong>.
    Execute no sistema parceiro e registre aqui a confirmação. A automação só será considerada concluída quando houver documentação, credenciais e homologação do fornecedor.</div>

<form class="card card-body mb-3" method="get"><div class="row g-2">
    <div class="col-md-3"><select name="destination" class="form-select"><option value="">Todos os destinos</option>
        @foreach (['vindi' => 'Vindi', 'simplesvet' => 'SimplesVet', 'tudoprapet' => 'TudoPraPet'] as $k => $l)<option value="{{ $k }}" @selected(request('destination') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="col-md-3"><select name="status" class="form-select"><option value="">Pendentes (padrão)</option>
        @foreach (\App\Enums\IntegrationStatus::options() as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100">Filtrar</button></div>
</div></form>

<div class="card mb-4"><div class="table-responsive">
    <table class="table mb-0">
        <thead><tr><th>Destino</th><th>Operação</th><th>Objeto</th><th>Modo</th><th>Situação</th><th>Tentativas</th><th>Erro / conclusão</th><th></th></tr></thead>
        <tbody>
        @forelse ($jobs as $j)
            <tr>
                <td>{{ $j->destinationLabel() }}</td>
                <td>{{ $j->operationLabel() }}<div class="small text-muted">@datetime($j->created_at)</div></td>
                <td class="small">{{ $j->subject_type }} #{{ $j->subject_id }}
                    @if ($j->subject_type === 'Subscription')<a href="{{ route('subscriptions.show', $j->subject_id) }}">abrir</a>@elseif ($j->subject_type === 'Customer')<a href="{{ route('customers.show', $j->subject_id) }}">abrir</a>@endif
                    @if ($j->payload)<div class="text-muted">{{ collect($j->payload)->map(fn($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))->implode(' · ') }}</div>@endif</td>
                <td>{{ $j->mode === 'manual' ? 'Manual' : 'Automático' }}</td>
                <td><x-status :value="$j->status" /></td>
                <td>{{ $j->attempts }}/{{ $j->max_attempts }}</td>
                <td class="small">@if ($j->last_error)<span class="text-danger">{{ $j->last_error }}</span>@endif
                    @if ($j->completed_at)Concluído por {{ $j->completedBy?->name ?? 'sistema' }} @datetime($j->completed_at) {{ $j->completion_note }}@endif</td>
                <td style="min-width: 260px">
                    @if ($j->status->value !== 'synced')
                        @if ($j->mode === 'manual' || $j->status->value === 'needs_intervention')
                            @can('integrations.complete_manual')
                            <form method="post" action="{{ route('integrations.complete', $j) }}" class="d-flex flex-column gap-1">@csrf
                                <input name="external_reference" class="form-control form-control-sm" placeholder="ID no sistema parceiro (opcional)">
                                <input name="note" class="form-control form-control-sm" placeholder="O que foi feito" required>
                                <button class="btn btn-sm btn-outline-success">Registrar conclusão</button>
                            </form>
                            @endcan
                        @endif
                        @if ($j->mode === 'automatic')
                            @can('integrations.reprocess')<form method="post" action="{{ route('integrations.retry', $j) }}" class="mt-1">@csrf<button class="btn btn-sm btn-outline-primary">Reprocessar</button></form>@endcan
                        @endif
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-4">Nada pendente.</td></tr>
        @endforelse
        </tbody>
    </table>
</div></div>
<div class="mb-4">{{ $jobs->links() }}</div>

@if ($webhooks->isNotEmpty())
<h2 class="h5">Webhooks não processados</h2>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Provedor</th><th>Tipo</th><th>Recebido</th><th>Situação</th><th>Tentativas</th><th>Resultado</th><th></th></tr></thead>
    <tbody>@foreach ($webhooks as $w)
        <tr><td>{{ $w->provider }}</td><td>{{ $w->event_type }}</td><td>@datetime($w->received_at)</td><td>{{ $w->status }}</td><td>{{ $w->attempts }}</td><td class="small">{{ $w->result }}</td>
            <td>@can('integrations.reprocess')<form method="post" action="{{ route('integrations.webhook.retry', $w) }}">@csrf<button class="btn btn-sm btn-outline-primary">Reprocessar</button></form>@endcan</td></tr>
    @endforeach</tbody>
</table></div></div>
@endif
@endsection
