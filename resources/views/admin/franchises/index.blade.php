@extends('layouts.app')
@section('title', 'Franquias')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Franquias</h1>
    <a href="{{ route('franchises.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nova franquia</a>
</div>
<div class="card"><div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Código</th><th>Nome</th><th>Cidade</th><th>Usuários</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @foreach ($franchises as $f)
        <tr>
            <td><code>{{ $f->code }}</code></td>
            <td>{{ $f->name }} @if ($f->is_central)<span class="badge text-bg-primary">Central</span>@endif</td>
            <td>{{ $f->city }}{{ $f->state ? '/'.$f->state : '' }}</td>
            <td>{{ $f->users_count }}</td>
            <td>{{ $f->status === 'active' ? 'Ativa' : 'Inativa' }}</td>
            <td class="text-end"><a href="{{ route('franchises.edit', $f) }}" class="btn btn-sm btn-outline-primary">Editar</a></td>
        </tr>
    @endforeach
    </tbody>
</table></div></div>
@endsection
