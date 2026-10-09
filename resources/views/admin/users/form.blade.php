@extends('layouts.app')
@section('title', $user->exists ? 'Editar usuário' : 'Novo usuário')
@section('content')
<div class="row g-3">
<div class="col-lg-6">
<h1 class="h4 mb-3">{{ $user->exists ? $user->name : 'Novo usuário' }}</h1>
<form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card card-body">
    @csrf
    @if ($user->exists) @method('put') @endif
    <div class="mb-2"><label class="form-label">Nome *</label><input name="name" class="form-control" value="{{ old('name', $user->name) }}" required></div>
    <div class="mb-2"><label class="form-label">E-mail *</label><input type="email" name="email" class="form-control" value="{{ old('email', $user->email) }}" required></div>
    <div class="mb-2"><label class="form-label">{{ $user->exists ? 'Nova senha (opcional)' : 'Senha inicial *' }}</label><input type="password" name="password" class="form-control" autocomplete="new-password" @required(! $user->exists)>
        <div class="form-text">Mínimo de 10 caracteres com letras e números.</div></div>
    <div class="mb-2"><label class="form-label">Confirmar senha</label><input type="password" name="password_confirmation" class="form-control" autocomplete="new-password"></div>
    @if ($user->exists)
        <div class="mb-2"><label class="form-label">Situação</label>
            <select name="is_active" class="form-select"><option value="1" @selected($user->is_active)>Ativo</option><option value="0" @selected(! $user->is_active)>Desativado</option></select>
            <div class="form-text">Desativar encerra as sessões; a autoria das ações anteriores é preservada.</div></div>
    @else
        <div class="mb-2"><label class="form-label">Unidade *</label><select name="franchise_id" class="form-select">@foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select></div>
        <div class="mb-2"><label class="form-label">Perfil *</label><select name="role_id" class="form-select">@foreach ($roles as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select></div>
    @endif
    <button class="btn btn-primary mt-2">Salvar</button>
</form>
</div>
@if ($user->exists)
<div class="col-lg-6">
    <h2 class="h5 mb-3">Vínculos com unidades</h2>
    <div class="card mb-3"><ul class="list-group list-group-flush">
        @foreach ($user->memberships as $m)
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>{{ $m->franchise->name }} — {{ $m->role->name }} @unless ($m->is_active)<span class="badge text-bg-secondary">inativo</span>@endunless</span>
                @if ($m->is_active)<form method="post" action="{{ route('users.memberships.destroy', $m) }}" data-confirm="Desativar este vínculo?">@csrf @method('delete')<button class="btn btn-sm btn-link text-danger">desativar</button></form>@endif
            </li>
        @endforeach
    </ul></div>
    <form method="post" action="{{ route('users.memberships.store', $user) }}" class="card card-body">@csrf
        <div class="fw-semibold mb-2">Adicionar ou alterar vínculo</div>
        <div class="row g-2">
            <div class="col-md-6"><select name="franchise_id" class="form-select">@foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select></div>
            <div class="col-md-6"><select name="role_id" class="form-select">@foreach ($roles as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select></div>
        </div>
        <button class="btn btn-outline-primary mt-2">Salvar vínculo</button>
        <div class="form-text">Atuar em mais de uma franquia depende de autorização da gestão central; o usuário escolhe a unidade ao entrar.</div>
    </form>
</div>
@endif
</div>
@endsection
