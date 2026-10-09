@extends('layouts.app')
@section('title', $version->plan->name.' v'.$version->version)
@section('content')
@php($editable = $version->isDraft() && auth()->user()->can('plans.manage'))
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <a href="{{ route('plans.show', $version->plan) }}" class="small">&larr; {{ $version->plan->name }}</a>
        <h1 class="h4 mb-0">{{ $version->display_name }} <span class="text-muted">v{{ $version->version }}</span> <x-status :value="$version->status" /></h1>
        @if ($version->published_at)<div class="small text-muted">Publicada em @datetime($version->published_at) — imutável para contratos assinados.</div>@endif
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @can('plans.manage')
            @unless ($version->isDraft())
                <form method="post" action="{{ route('versions.draft', $version) }}">@csrf<button class="btn btn-outline-primary"><i class="bi bi-copy"></i> Criar nova versão a partir desta</button></form>
            @endunless
            @if ($version->isDraft() && $version->version > 1)
                <form method="post" action="{{ route('versions.destroy', $version) }}" data-confirm="Descartar este rascunho?">@csrf @method('delete')<button class="btn btn-outline-danger">Descartar rascunho</button></form>
            @endif
        @endcan
        @can('plans.publish')
            @if ($version->isDraft())
                <form method="post" action="{{ route('versions.publish', $version) }}" data-confirm="Publicar? Depois de publicada, a versão não poderá mais ser alterada.">@csrf
                    <button class="btn btn-success" @disabled($errors_publish)><i class="bi bi-megaphone"></i> Publicar</button></form>
            @elseif ($version->status->value === 'published')
                <button class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#unpublish">Despublicar</button>
            @endif
        @endcan
    </div>
</div>

<div class="collapse mb-3" id="unpublish">
    <form method="post" action="{{ route('versions.unpublish', $version) }}" class="card card-body">@csrf
        <label class="form-label">Motivo</label>
        <input name="reason" class="form-control mb-2" required>
        <div class="small text-muted mb-2">Despublicar impede novas vendas, mas não invalida contratos vigentes.</div>
        <button class="btn btn-danger">Confirmar despublicação</button>
    </form>
</div>

@if ($version->isDraft() && $errors_publish)
    <div class="alert alert-warning"><strong>Pendências para publicar:</strong>
        <ul class="mb-0">@foreach ($errors_publish as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="row g-3">
<div class="col-xl-5">
<form method="post" action="{{ route('versions.update', $version) }}" class="card">
    @csrf @method('put')
    <div class="card-header">Condições da versão</div>
    <fieldset class="card-body" @disabled(! $editable)>
        <div class="row g-2">
            <div class="col-12"><label class="form-label">Nome de exibição *</label>
                <input name="display_name" class="form-control" value="{{ old('display_name', $version->display_name) }}" required></div>
            <div class="col-12"><label class="form-label">Descrição para o cliente</label>
                <textarea name="description" class="form-control" rows="3">{{ old('description', $version->description) }}</textarea></div>
            <div class="col-6"><label class="form-label">Mensalidade (R$) *</label>
                <input name="price" class="form-control" value="{{ old('price', \App\Support\Money::input($version->price_cents)) }}" placeholder="0,00"></div>
            <div class="col-6"><label class="form-label">Adesão (R$)</label>
                <input name="enrollment_fee" class="form-control" value="{{ old('enrollment_fee', \App\Support\Money::input($version->enrollment_fee_cents)) }}" placeholder="0,00"></div>
            <div class="col-6"><label class="form-label">Periodicidade de cobrança *</label>
                <select name="billing_interval_months" class="form-select">
                    @foreach ([1 => 'Mensal', 3 => 'Trimestral', 6 => 'Semestral', 12 => 'Anual'] as $m => $l)
                        <option value="{{ $m }}" @selected(old('billing_interval_months', $version->billing_interval_months) == $m)>{{ $l }}</option>
                    @endforeach
                </select></div>
            <div class="col-6"><label class="form-label">Duração contratual (meses) *</label>
                <input type="number" name="contract_duration_months" class="form-control" value="{{ old('contract_duration_months', $version->contract_duration_months) }}"></div>
            <div class="col-12"><label class="form-label">Política de renovação *</label>
                <textarea name="renewal_policy" class="form-control" rows="2">{{ old('renewal_policy', $version->renewal_policy) }}</textarea></div>
            <div class="col-12"><label class="form-label">Espécies elegíveis *</label><br>
                @foreach (\App\Enums\Species::options() as $v => $l)
                    <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="eligible_species[]" value="{{ $v }}" id="sp{{ $v }}" @checked(in_array($v, old('eligible_species', $version->eligible_species ?? [])))>
                        <label class="form-check-label" for="sp{{ $v }}">{{ $l }}</label></div>
                @endforeach</div>
            <div class="col-6"><label class="form-label">Idade mínima (meses)</label>
                <input type="number" name="min_age_months" class="form-control" value="{{ old('min_age_months', $version->min_age_months) }}"></div>
            <div class="col-6"><label class="form-label">Idade máxima (meses)</label>
                <input type="number" name="max_age_months" class="form-control" value="{{ old('max_age_months', $version->max_age_months) }}"></div>
            <div class="col-12"><label class="form-label">Unidades onde pode ser vendido</label>
                <select name="eligible_franchise_ids[]" class="form-select" multiple size="4">
                    @foreach ($franchises as $f)<option value="{{ $f->id }}" @selected(in_array($f->id, $version->eligible_franchise_ids ?? []))>{{ $f->name }}</option>@endforeach
                </select><div class="form-text">Nenhuma selecionada = todas as unidades.</div></div>
            <div class="col-12"><label class="form-label">Modelo de contrato *</label>
                <select name="contract_template_id" class="form-select">
                    <option value="">—</option>
                    @foreach ($templates as $t)<option value="{{ $t->id }}" @selected(old('contract_template_id', $version->contract_template_id) == $t->id)>{{ $t->name }} v{{ $t->version }}{{ $t->isLocked() ? ' (em uso)' : '' }}</option>@endforeach
                </select></div>
            <div class="col-12"><hr><div class="small-label mb-2">Promoção (opcional — não altera o preço-base)</div></div>
            <div class="col-6"><label class="form-label">Preço promocional (R$)</label>
                <input name="promo_price" class="form-control" value="{{ old('promo_price', \App\Support\Money::input($version->promo_price_cents)) }}"></div>
            <div class="col-6"><label class="form-label">Duração (meses)</label>
                <input type="number" name="promo_duration_months" class="form-control" value="{{ old('promo_duration_months', $version->promo_duration_months) }}"></div>
            <div class="col-6"><label class="form-label">Origem</label>
                <input name="promo_origin" class="form-control" value="{{ old('promo_origin', $version->promo_origin) }}"></div>
            <div class="col-6"><label class="form-label">Válida até</label>
                <input type="datetime-local" name="promo_valid_until" class="form-control" value="{{ old('promo_valid_until', $version->promo_valid_until?->format('Y-m-d\TH:i')) }}"></div>
            <div class="col-12"><hr><div class="small-label mb-2">Período de oferta a novos clientes</div></div>
            <div class="col-6"><label class="form-label">Início</label>
                <input type="datetime-local" name="offer_starts_at" class="form-control" value="{{ old('offer_starts_at', $version->offer_starts_at?->format('Y-m-d\TH:i')) }}"></div>
            <div class="col-6"><label class="form-label">Fim</label>
                <input type="datetime-local" name="offer_ends_at" class="form-control" value="{{ old('offer_ends_at', $version->offer_ends_at?->format('Y-m-d\TH:i')) }}"></div>
        </div>
        @if ($editable)<button class="btn btn-primary mt-3">Salvar rascunho</button>@endif
    </fieldset>
</form>
</div>

<div class="col-xl-7">
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">Benefícios
        @if ($editable)<button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#rule-new">+ Benefício</button>@endif</div>
    @if ($editable)
        <div class="collapse border-bottom" id="rule-new">
            @include('catalog.versions.rule-form', ['rule' => new \App\Models\BenefitRule(['waiting_days' => 0, 'unit' => 'unidade', 'requires_authorization' => true]), 'action' => route('rules.store', $version), 'method' => 'post'])
        </div>
    @endif
    <div class="list-group list-group-flush">
        @forelse ($version->benefitRules as $rule)
            <div class="list-group-item">
                <div class="d-flex justify-content-between">
                    <div>
                        <strong>{{ $rule->description }}</strong> <code>{{ $rule->service_code }}</code><br>
                        <span class="small">
                            {{ $rule->coverage_type->label() }}@if ($rule->discount_percent) ({{ $rule->discount_percent }}%)@endif ·
                            {{ $rule->isUnlimited() ? 'sem limite de quantidade' : $rule->quantity_limit.' '.$rule->unit.' por '.mb_strtolower($rule->period_type?->label() ?? '—') }}
                            @if ($rule->rolling_window_days) ({{ $rule->rolling_window_days }} dias)@endif ·
                            carência {{ $rule->waiting_days }} dias
                            @if ($rule->min_interval_days) · intervalo mínimo {{ $rule->min_interval_days }} dias @endif
                            @if ($rule->copay_cents) · coparticipação @money($rule->copay_cents) @endif
                            @if ($rule->coverage_group) · grupo {{ $rule->coverage_group }} (limite {{ $rule->group_limit ?? '—' }}) @endif
                        </span>
                        @if ($rule->exclusions)<div class="small text-muted">Exclusões: {{ $rule->exclusions }}</div>@endif
                    </div>
                    @if ($editable)
                        <div class="text-nowrap">
                            <button class="btn btn-sm btn-link" data-bs-toggle="collapse" data-bs-target="#rule-{{ $rule->id }}">editar</button>
                            <form method="post" action="{{ route('rules.destroy', $rule) }}" class="d-inline" data-confirm="Remover este benefício?">@csrf @method('delete')<button class="btn btn-sm btn-link text-danger">remover</button></form>
                        </div>
                    @endif
                </div>
                @if ($editable)
                    <div class="collapse mt-2" id="rule-{{ $rule->id }}">
                        @include('catalog.versions.rule-form', ['rule' => $rule, 'action' => route('rules.update', $rule), 'method' => 'put'])
                    </div>
                @endif
            </div>
        @empty
            <div class="list-group-item text-muted">Nenhum benefício cadastrado.</div>
        @endforelse
    </div>
</div>
</div>
</div>
@endsection
