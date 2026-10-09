@extends('layouts.app')
@section('title', 'Clientes')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Clientes</h1>
    @can('customers.manage')<a href="{{ route('customers.create') }}" class="btn btn-primary"><i class="bi bi-person-plus"></i> Novo tutor</a>@endcan
</div>
<form class="card card-body mb-3" method="get">
    <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="search" name="q" value="{{ $q }}" class="form-control" placeholder="Nome, CPF ou e-mail do tutor" autofocus>
        <button class="btn btn-primary">Buscar</button>
    </div>
    <div class="form-text">O cadastro do tutor é central na rede: pesquise antes de criar para evitar duplicidade.</div>
</form>
@if ($q !== '')
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Nome</th><th>CPF</th><th>E-mail</th><th>Pets</th><th></th></tr></thead>
            <tbody>
            @forelse ($customers as $c)
                <tr>
                    <td>{{ $c->name }}</td>
                    <td>{{ $c->maskedDocument() }}</td>
                    <td>{{ \App\Support\Mask::email($c->email) }}</td>
                    <td>{{ $c->pets_count }}</td>
                    <td class="text-end"><a href="{{ route('customers.show', $c) }}" class="btn btn-sm btn-outline-primary">Abrir perfil</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">Nenhum tutor encontrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
