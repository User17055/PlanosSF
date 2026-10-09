@extends('layouts.app')
@section('title', 'Franquia')
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<h1 class="h4 mb-3">{{ $franchise->exists ? $franchise->name : 'Nova franquia' }}</h1>
<form method="post" action="{{ $franchise->exists ? route('franchises.update', $franchise) : route('franchises.store') }}" class="card card-body">
    @csrf
    @if ($franchise->exists) @method('put') @endif
    <div class="row g-2">
        <div class="col-md-4"><label class="form-label">Código *</label><input name="code" class="form-control" value="{{ old('code', $franchise->code) }}" required></div>
        <div class="col-md-8"><label class="form-label">Nome *</label><input name="name" class="form-control" value="{{ old('name', $franchise->name) }}" required></div>
        <div class="col-md-4"><label class="form-label">CNPJ</label><input name="document" class="form-control" value="{{ old('document', $franchise->document) }}"></div>
        <div class="col-md-4"><label class="form-label">Telefone</label><input name="phone" class="form-control" value="{{ old('phone', $franchise->phone) }}"></div>
        <div class="col-md-4"><label class="form-label">E-mail</label><input name="email" class="form-control" value="{{ old('email', $franchise->email) }}"></div>
        <div class="col-md-6"><label class="form-label">Endereço</label><input name="address_line" class="form-control" value="{{ old('address_line', $franchise->address_line) }}"></div>
        <div class="col-md-4"><label class="form-label">Cidade</label><input name="city" class="form-control" value="{{ old('city', $franchise->city) }}"></div>
        <div class="col-md-2"><label class="form-label">UF</label><input name="state" class="form-control" maxlength="2" value="{{ old('state', $franchise->state) }}"></div>
        <div class="col-md-4"><label class="form-label">Situação</label><select name="status" class="form-select"><option value="active" @selected($franchise->status === 'active')>Ativa</option><option value="inactive" @selected($franchise->status === 'inactive')>Inativa</option></select></div>
    </div>
    <button class="btn btn-primary mt-3">Salvar</button>
</form>
</div></div>
@endsection
