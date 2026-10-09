@extends('layouts.app')
@section('title', $customer->exists ? 'Editar tutor' : 'Novo tutor')
@section('content')
<div class="row justify-content-center"><div class="col-xl-8">
<h1 class="h4 mb-3">{{ $customer->exists ? 'Editar tutor' : 'Novo tutor' }}</h1>
<form method="post" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}" class="card card-body" data-once>
    @csrf
    @if ($customer->exists) @method('put') @endif
    <div class="row g-3">
        <div class="col-md-8"><label class="form-label">Nome completo *</label>
            <input name="name" class="form-control" value="{{ old('name', $customer->name) }}" required></div>
        <div class="col-md-4"><label class="form-label">CPF</label>
            <input name="document" class="form-control" value="{{ old('document', $customer->formattedDocument()) }}" inputmode="numeric" placeholder="000.000.000-00"></div>
        <div class="col-md-6"><label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control" value="{{ old('email', $customer->email) }}"></div>
        <div class="col-md-6"><label class="form-label">Telefone</label>
            <input name="phone" class="form-control" value="{{ old('phone', $customer->phone) }}" inputmode="tel"></div>
        <div class="col-md-3"><label class="form-label">CEP</label>
            <input name="zip_code" class="form-control" value="{{ old('zip_code', $customer->zip_code) }}" inputmode="numeric"></div>
        <div class="col-md-7"><label class="form-label">Logradouro</label>
            <input name="street" class="form-control" value="{{ old('street', $customer->street) }}"></div>
        <div class="col-md-2"><label class="form-label">Número</label>
            <input name="number" class="form-control" value="{{ old('number', $customer->number) }}"></div>
        <div class="col-md-4"><label class="form-label">Complemento</label>
            <input name="complement" class="form-control" value="{{ old('complement', $customer->complement) }}"></div>
        <div class="col-md-3"><label class="form-label">Bairro</label>
            <input name="district" class="form-control" value="{{ old('district', $customer->district) }}"></div>
        <div class="col-md-3"><label class="form-label">Cidade</label>
            <input name="city" class="form-control" value="{{ old('city', $customer->city) }}"></div>
        <div class="col-md-2"><label class="form-label">UF</label>
            <input name="state" class="form-control text-uppercase" maxlength="2" value="{{ old('state', $customer->state) }}"></div>
        <div class="col-12"><div class="form-check">
            <input type="hidden" name="marketing_opt_in" value="0">
            <input class="form-check-input" type="checkbox" name="marketing_opt_in" value="1" id="mkt" @checked(old('marketing_opt_in', $customer->marketing_opt_in))>
            <label class="form-check-label" for="mkt">Aceita receber comunicações de marketing (registro separado do aceite do plano)</label>
        </div></div>
        @if ($customer->exists)
            <div class="col-12"><label class="form-label">Motivo da alteração</label>
                <input name="change_reason" class="form-control" placeholder="Opcional — fica registrado na auditoria"></div>
            <div class="col-12"><div class="alert alert-info small mb-0">Alterar o cadastro não modifica contratos já assinados: eles guardam os dados vigentes no momento da assinatura.</div></div>
        @endif
    </div>
    <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary" type="submit">Salvar</button>
        <a href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}" class="btn btn-link">Cancelar</a>
    </div>
</form>
</div></div>
@endsection
