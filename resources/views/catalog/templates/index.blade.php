@extends('layouts.app')
@section('title', 'Modelos de contrato')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Modelos de contrato</h1>
    <a href="{{ route('templates.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Novo modelo</a>
</div>
<div class="alert alert-info small">O texto contratual precisa ser aprovado juridicamente pela São Francisco. Um modelo usado por versão publicada fica bloqueado; alterações geram nova versão.</div>
<div class="card"><div class="table-responsive">
    <table class="table mb-0">
        <thead><tr><th>Nome</th><th>Versão</th><th>Situação</th><th>Criado</th><th></th></tr></thead>
        <tbody>
        @forelse ($templates as $t)
            <tr>
                <td>{{ $t->name }}</td><td>v{{ $t->version }}</td>
                <td>{{ $t->isLocked() ? 'Em uso (bloqueado)' : 'Rascunho' }}</td>
                <td>@datetime($t->created_at)</td>
                <td class="text-end">
                    @if ($t->isLocked())
                        <form method="post" action="{{ route('templates.version', $t) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary">Nova versão</button></form>
                    @endif
                    <a href="{{ route('templates.edit', $t) }}" class="btn btn-sm btn-outline-secondary">{{ $t->isLocked() ? 'Ver' : 'Editar' }}</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">Nenhum modelo.</td></tr>
        @endforelse
        </tbody>
    </table>
</div></div>
@endsection
