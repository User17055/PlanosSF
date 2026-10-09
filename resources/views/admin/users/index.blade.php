@extends('layouts.app')
@section('title', 'Usuários')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Usuários</h1>
    <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="bi bi-person-plus"></i> Novo usuário</a>
</div>
<p class="text-muted small">Cada colaborador deve ter acesso próprio — evite senhas compartilhadas pela equipe.</p>
<div class="card"><div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Nome</th><th>E-mail</th><th>Vínculos</th><th>Situação</th><th>Último acesso</th><th></th></tr></thead>
    <tbody>
    @foreach ($users as $u)
        <tr>
            <td>{{ $u->name }}</td><td>{{ $u->email }}</td>
            <td class="small">@foreach ($u->memberships as $m)<span class="badge {{ $m->is_active ? 'text-bg-light border' : 'text-bg-secondary' }}">{{ $m->franchise->name }} · {{ $m->role->name }}</span> @endforeach</td>
            <td>{{ $u->is_active ? 'Ativo' : 'Desativado' }}</td>
            <td class="small">@datetime($u->last_login_at)</td>
            <td class="text-end"><a href="{{ route('users.edit', $u) }}" class="btn btn-sm btn-outline-primary">Editar</a></td>
        </tr>
    @endforeach
    </tbody>
</table></div></div>
<div class="mt-3">{{ $users->links() }}</div>
@endsection
