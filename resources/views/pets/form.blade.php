@extends('layouts.app')
@section('title', $pet->exists ? 'Editar pet' : 'Novo pet')
@section('content')
<div class="row justify-content-center"><div class="col-xl-7">
<h1 class="h4 mb-1">{{ $pet->exists ? 'Editar pet' : 'Novo pet' }}</h1>
<p class="text-muted">Tutor: <a href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a></p>
<form method="post" action="{{ $pet->exists ? route('pets.update', $pet) : route('pets.store', $customer) }}" class="card card-body" data-once>
    @csrf
    @if ($pet->exists) @method('put') @endif
    <input type="hidden" name="return_to" value="{{ request('return_to') }}">
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Nome *</label>
            <input name="name" class="form-control" value="{{ old('name', $pet->name) }}" required></div>
        <div class="col-md-6"><label class="form-label">Espécie *</label>
            <select name="species" class="form-select" required>
                @foreach (\App\Enums\Species::options() as $v => $l)
                    <option value="{{ $v }}" @selected(old('species', $pet->species?->value) === $v)>{{ $l }}</option>
                @endforeach
            </select></div>
        <div class="col-md-6"><label class="form-label">Raça</label>
            <input name="breed" class="form-control" value="{{ old('breed', $pet->breed) }}"></div>
        <div class="col-md-6"><label class="form-label">Sexo</label>
            <select name="sex" class="form-select">
                <option value="">—</option>
                <option value="macho" @selected(old('sex', $pet->sex) === 'macho')>Macho</option>
                <option value="femea" @selected(old('sex', $pet->sex) === 'femea')>Fêmea</option>
            </select></div>
        <div class="col-md-6"><label class="form-label">Data de nascimento</label>
            <input type="date" name="birth_date" class="form-control" value="{{ old('birth_date', $pet->birth_date?->format('Y-m-d')) }}"></div>
        <div class="col-md-6"><label class="form-label">ou idade estimada (meses)</label>
            <input type="number" min="0" name="estimated_age_months" class="form-control" value="{{ old('estimated_age_months', $pet->estimated_age_months) }}"></div>
        <div class="col-md-6"><label class="form-label">Identificador adicional (microchip etc.)</label>
            <input name="identifier" class="form-control" value="{{ old('identifier', $pet->identifier) }}"></div>
    </div>
    <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary" type="submit">Salvar</button>
        <a href="{{ route('customers.show', $customer) }}" class="btn btn-link">Cancelar</a>
    </div>
</form>
</div></div>
@endsection
