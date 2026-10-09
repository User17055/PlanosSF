@extends('layouts.app')
@section('title', 'Novo plano')
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<h1 class="h4 mb-3">Novo plano</h1>
<form method="post" action="{{ route('plans.store') }}" class="card card-body" data-once>
    @csrf
    <div class="mb-3"><label class="form-label">Código comercial *</label>
        <input name="code" class="form-control text-uppercase" value="{{ old('code') }}" required maxlength="40" placeholder="ex.: SF-ESSENCIAL">
        <div class="form-text">Identificador legível e permanente. O sistema também mantém um identificador interno.</div></div>
    <div class="mb-3"><label class="form-label">Nome *</label>
        <input name="name" class="form-control" value="{{ old('name') }}" required></div>
    <button class="btn btn-primary">Criar com versão 1 em rascunho</button>
</form>
</div></div>
@endsection
