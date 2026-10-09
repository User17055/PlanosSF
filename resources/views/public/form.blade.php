@extends('layouts.public')
@section('title', 'Seus dados')
@section('content')
@php($c = $merged['customer'])
@php($p = $merged['pet'])
@php($prefilled = (bool) $proposal->customer_id)
<div class="mb-3">
    <div class="small text-muted">Proposta {{ $proposal->number }} · {{ $proposal->conditions_snapshot['version']['display_name'] }}</div>
    <h1 class="h4">Etapa 1 de 3 — dados do tutor e do pet</h1>
    @if ($prefilled)<div class="alert alert-info small">A unidade já preencheu parte dos dados. Por segurança, mostramos apenas parte das informações; preencha somente o que deseja corrigir ou completar. Campos em branco mantêm o cadastro atual.</div>@endif
</div>
<form method="post" action="{{ route('public.form', $token) }}" data-once>
    @csrf
    <div class="card mb-3"><div class="card-header">Tutor (titular do contrato)</div><div class="card-body"><div class="row g-3">
        <div class="col-md-8"><label class="form-label">Nome completo</label>
            <input name="customer[name]" class="form-control" value="{{ old('customer.name', $c['name'] ?? '') }}" @required(! $prefilled) autocomplete="name"></div>
        <div class="col-md-4"><label class="form-label">CPF</label>
            <input name="customer[document]" class="form-control" value="{{ old('customer.document', $prefilled ? '' : ($c['document'] ?? '')) }}" placeholder="{{ $prefilled ? \App\Support\Mask::document($c['document'] ?? null) : '000.000.000-00' }}" inputmode="numeric" @required(! $prefilled)></div>
        <div class="col-md-6"><label class="form-label">E-mail</label>
            <input type="email" name="customer[email]" class="form-control" value="{{ old('customer.email', $prefilled ? '' : ($c['email'] ?? '')) }}" placeholder="{{ $prefilled ? \App\Support\Mask::email($c['email'] ?? null) : '' }}" @required(! $prefilled) autocomplete="email">
            <div class="form-text">Você receberá um código neste e-mail para confirmar o aceite.</div></div>
        <div class="col-md-6"><label class="form-label">Celular</label>
            <input name="customer[phone]" class="form-control" value="{{ old('customer.phone', $prefilled ? '' : ($c['phone'] ?? '')) }}" placeholder="{{ $prefilled ? \App\Support\Mask::phone($c['phone'] ?? null) : '(00) 00000-0000' }}" inputmode="tel" @required(! $prefilled)></div>
        <div class="col-md-3"><label class="form-label">CEP</label>
            <input name="customer[zip_code]" class="form-control" value="{{ old('customer.zip_code', $prefilled ? '' : ($c['zip_code'] ?? '')) }}" inputmode="numeric" @required(! $prefilled)></div>
        <div class="col-md-7"><label class="form-label">Rua</label>
            <input name="customer[street]" class="form-control" value="{{ old('customer.street', $prefilled ? '' : ($c['street'] ?? '')) }}" @required(! $prefilled)></div>
        <div class="col-md-2"><label class="form-label">Número</label>
            <input name="customer[number]" class="form-control" value="{{ old('customer.number', $prefilled ? '' : ($c['number'] ?? '')) }}" @required(! $prefilled)></div>
        <div class="col-md-4"><label class="form-label">Complemento</label>
            <input name="customer[complement]" class="form-control" value="{{ old('customer.complement', $prefilled ? '' : ($c['complement'] ?? '')) }}"></div>
        <div class="col-md-3"><label class="form-label">Bairro</label>
            <input name="customer[district]" class="form-control" value="{{ old('customer.district', $prefilled ? '' : ($c['district'] ?? '')) }}"></div>
        <div class="col-md-3"><label class="form-label">Cidade</label>
            <input name="customer[city]" class="form-control" value="{{ old('customer.city', $prefilled ? '' : ($c['city'] ?? '')) }}" @required(! $prefilled)></div>
        <div class="col-md-2"><label class="form-label">UF</label>
            <input name="customer[state]" class="form-control text-uppercase" maxlength="2" value="{{ old('customer.state', $prefilled ? '' : ($c['state'] ?? '')) }}" @required(! $prefilled)></div>
    </div></div></div>

    <div class="card mb-3"><div class="card-header">Pet que será coberto</div><div class="card-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label">Nome</label>
            <input name="pet[name]" class="form-control" value="{{ old('pet.name', $p['name'] ?? '') }}" @required(! $proposal->pet_id)></div>
        <div class="col-md-6"><label class="form-label">Espécie</label>
            <select name="pet[species]" class="form-select" @required(! $proposal->pet_id)>
                <option value="">Selecione</option>
                @foreach (\App\Enums\Species::options() as $v => $l)<option value="{{ $v }}" @selected(old('pet.species', $p['species'] ?? '') === $v)>{{ $l }}</option>@endforeach
            </select></div>
        <div class="col-md-6"><label class="form-label">Raça (se souber)</label>
            <input name="pet[breed]" class="form-control" value="{{ old('pet.breed', $p['breed'] ?? '') }}"></div>
        <div class="col-md-6"><label class="form-label">Sexo</label>
            <select name="pet[sex]" class="form-select"><option value="">—</option>
                <option value="macho" @selected(old('pet.sex', $p['sex'] ?? '') === 'macho')>Macho</option>
                <option value="femea" @selected(old('pet.sex', $p['sex'] ?? '') === 'femea')>Fêmea</option></select></div>
        <div class="col-md-6"><label class="form-label">Data de nascimento</label>
            <input type="date" name="pet[birth_date]" class="form-control" value="{{ old('pet.birth_date', $p['birth_date'] ?? '') }}"></div>
        <div class="col-md-6"><label class="form-label">ou idade aproximada (meses)</label>
            <input type="number" min="0" name="pet[estimated_age_months]" class="form-control" value="{{ old('pet.estimated_age_months', $p['estimated_age_months'] ?? '') }}"></div>
    </div></div></div>

    <button class="btn btn-primary btn-lg">Salvar e revisar condições</button>
</form>
@endsection
