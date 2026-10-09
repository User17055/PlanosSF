@extends('layouts.app')
@section('title', 'Nova contratação')
@section('content')
<div class="row justify-content-center"><div class="col-xl-8">
<h1 class="h4 mb-3">Nova contratação</h1>

<div class="card card-body mb-3">
    <div class="small-label mb-1">Tutor</div>
    @if ($customer)
        <div class="d-flex justify-content-between align-items-center">
            <div><strong>{{ $customer->name }}</strong> <span class="text-muted">CPF {{ $customer->maskedDocument() }}</span>
                @unless ($customer->isComplete())<div class="small text-warning">Cadastro incompleto: {{ implode(', ', $customer->missingFields()) }} — o cliente poderá completar no link.</div>@endunless</div>
            <a href="{{ route('proposals.create') }}" class="btn btn-sm btn-link">trocar</a>
        </div>
    @else
        <form method="get" action="{{ route('customers.index') }}" class="input-group">
            <input name="q" class="form-control" placeholder="Busque o tutor por nome ou CPF">
            <button class="btn btn-outline-primary">Buscar</button>
        </form>
        <div class="form-text">Abra o perfil do tutor e clique em "Nova contratação", ou <a href="{{ route('customers.create') }}">crie um pré-cadastro</a>. Para enviar um link em que o cliente preenche tudo, você pode seguir sem tutor.</div>
    @endif
</div>

<form method="post" action="{{ route('proposals.store') }}" class="card card-body" data-once>
    @csrf
    @if ($customer)<input type="hidden" name="customer_id" value="{{ $customer->id }}">@endif
    @if (request('supersedes_id'))<input type="hidden" name="supersedes_id" value="{{ request('supersedes_id') }}">@endif

    <div class="mb-3">
        <label class="form-label">Plano *</label>
        <select name="plan_version_id" class="form-select" required>
            <option value="">Selecione</option>
            @foreach ($versions as $v)
                <option value="{{ $v->id }}" @selected(old('plan_version_id') == $v->id)>{{ $v->display_name }} (v{{ $v->version }}) — @money($v->effectivePriceCents()) / {{ $v->billing_interval_months == 1 ? 'mês' : $v->billing_interval_months.' meses' }}</option>
            @endforeach
        </select>
        @if ($versions->isEmpty())<div class="form-text text-danger">Nenhuma versão publicada disponível para esta unidade.</div>@endif
    </div>

    @if ($customer)
    <div class="mb-3">
        <label class="form-label">Pet coberto *</label>
        <div class="d-flex gap-2">
            <select name="pet_id" class="form-select">
                <option value="">Selecione</option>
                @foreach ($customer->pets as $pet)<option value="{{ $pet->id }}" @selected(old('pet_id') == $pet->id)>{{ $pet->name }} ({{ $pet->species->label() }})</option>@endforeach
            </select>
            <a href="{{ route('pets.create', ['customer' => $customer, 'return_to' => route('proposals.create', ['customer_id' => $customer->id])]) }}" class="btn btn-outline-secondary text-nowrap">+ Pet</a>
        </div>
        <div class="form-text">Cada assinatura cobre um pet.</div>
    </div>
    @endif

    <div class="mb-3">
        <label class="form-label">Canal *</label>
        <div class="d-flex gap-3">
            <div class="form-check"><input class="form-check-input" type="radio" name="channel" value="link" id="ch-link" @checked(old('channel', 'link') === 'link')><label class="form-check-label" for="ch-link">Link individual</label></div>
            <div class="form-check"><input class="form-check-input" type="radio" name="channel" value="in_person" id="ch-ip" @checked(old('channel') === 'in_person') @disabled(! $customer)><label class="form-check-label" for="ch-ip">Presencial</label></div>
        </div>
    </div>

    <div class="mb-3" data-toggle-for="channel" data-toggle-values="link">
        <label class="form-label">Quem preenche os dados?</label>
        <div class="form-check"><input class="form-check-input" type="radio" name="fill_mode" value="seller" id="fm-s" @checked(old('fill_mode', $customer ? 'seller' : 'customer') === 'seller') @disabled(! $customer)>
            <label class="form-check-label" for="fm-s">Eu preencho — o cliente confere, corrige e aceita</label></div>
        <div class="form-check"><input class="form-check-input" type="radio" name="fill_mode" value="customer" id="fm-c" @checked(old('fill_mode', $customer ? 'seller' : 'customer') === 'customer')>
            <label class="form-check-label" for="fm-c">O cliente preenche os dados</label></div>
        <div class="form-text">O aceite sempre pertence ao cliente, mesmo quando o vendedor preenche.</div>
    </div>

    <div class="mb-3 d-none" data-toggle-for="channel" data-toggle-values="in_person">
        <label class="form-label">Forma de assinatura *</label>
        <div class="form-check"><input class="form-check-input" type="radio" name="signature_method" value="tablet" id="sm-t" @checked(old('signature_method', 'tablet') === 'tablet')><label class="form-check-label" for="sm-t">Assinatura no tablet</label></div>
        <div class="form-check"><input class="form-check-input" type="radio" name="signature_method" value="paper" id="sm-p" @checked(old('signature_method') === 'paper')><label class="form-check-label" for="sm-p">Papel assinado e digitalizado</label></div>
        <div class="alert alert-warning small mt-2 mb-0">O presencial dispensa link de pagamento, mas não o pagamento. O plano só é ativado após confirmação financeira.</div>
    </div>

    <div class="mb-3">
        <label class="form-label">Início desejado da vigência (opcional)</label>
        <input type="date" name="desired_start_date" class="form-control" value="{{ old('desired_start_date') }}" style="max-width: 220px">
        <div class="form-text">Sem data, a vigência começa quando contrato válido e primeiro pagamento estiverem confirmados.</div>
    </div>

    @if (request('supersedes_id'))
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="revoke_superseded" value="1" id="rv" checked>
            <label class="form-check-label" for="rv">Revogar a proposta anterior</label></div>
    @endif

    <button class="btn btn-primary">Criar proposta</button>
</form>
</div></div>
@endsection
