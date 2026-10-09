@extends('layouts.app')
@section('title', 'Configurações e decisões')
@section('content')
<h1 class="h4 mb-3">Configurações e decisões</h1>
<div class="alert alert-info small">Enquanto uma decisão estiver pendente, a funcionalidade afetada fica desabilitada ou sinalizada como incompleta. A aprovação de uma regra deve atualizar a configuração, o contrato e o critério de teste correspondente.</div>

<div class="row g-3 mb-4">
    <div class="col-md-6"><div class="card card-body small">
        <div class="small-label">Cobrança</div>
        Provedor ativo: <strong>{{ $billingDriver === 'fake' ? 'Simulador de homologação' : 'Vindi' }}</strong>
        <div class="text-muted">Definido no servidor (PLANOS_BILLING_DRIVER). Credenciais nunca são exibidas nem enviadas ao navegador.</div>
    </div></div>
    <div class="col-md-6"><div class="card card-body small">
        <div class="small-label">Integrações</div>
        SimplesVet: <strong>{{ $integrationModes['simplesvet_mode'] }}</strong> · TudoPraPet: <strong>{{ $integrationModes['tudoprapet_mode'] }}</strong>
        <div class="text-muted">Modo manual até confirmação de API, credenciais e homologação com os fornecedores.</div>
    </div></div>
</div>

@foreach ($items as $item)
    <form method="post" action="{{ route('settings.update') }}" class="card mb-3">
        @csrf @method('put')
        <input type="hidden" name="key" value="{{ $item['key'] }}">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <code>{{ $item['key'] }}</code>
                @if ($item['pending'])<span class="badge text-bg-warning">decisão pendente</span>@else<span class="badge text-bg-success">decidido</span>@endif
            </div>
            <p class="small mt-2">{{ $item['description'] }}</p>
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    @if (is_bool(\App\Services\Settings::DEFAULTS[$item['key']]['value']))
                        <select name="value" class="form-select form-select-sm"><option value="1" @selected($item['value'])>Habilitado</option><option value="0" @selected(! $item['value'])>Desabilitado</option></select>
                    @else
                        <input name="value" class="form-control form-control-sm" value="{{ $item['value'] }}">
                    @endif
                </div>
                <div class="col-md-5"><input name="reason" class="form-control form-control-sm" placeholder="Motivo / referência da aprovação" required></div>
                <div class="col-md-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="decided" value="1" @checked(! $item['pending'])><label class="form-check-label small">Decisão aprovada</label></div></div>
                <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Salvar</button></div>
            </div>
        </div>
    </form>
@endforeach

<h2 class="h5 mt-4">Registro formal de decisões</h2>
<form method="post" action="{{ route('settings.decisions.store') }}" class="card card-body mb-3">@csrf
    <div class="row g-2">
        <div class="col-md-6"><input name="subject" class="form-control" placeholder="Assunto" required></div>
        <div class="col-md-3"><input name="owner" class="form-control" placeholder="Responsável" required></div>
        <div class="col-md-3"><input type="date" name="decided_on" class="form-control"></div>
        <div class="col-md-6"><textarea name="approved_option" class="form-control" placeholder="Opção aprovada"></textarea></div>
        <div class="col-md-6"><textarea name="expected_effect" class="form-control" placeholder="Efeito esperado no software"></textarea></div>
        <div class="col-md-3"><select name="status" class="form-select"><option value="pending">Pendente</option><option value="approved">Aprovada</option></select></div>
        <div class="col-md-3"><button class="btn btn-primary w-100">Registrar</button></div>
    </div>
</form>
<div class="card"><ul class="list-group list-group-flush small">
    @forelse ($decisions as $d)
        <li class="list-group-item"><strong>{{ $d->subject }}</strong> — {{ $d->status === 'approved' ? 'aprovada' : 'pendente' }} · {{ $d->owner }} {{ $d->decided_on ? '· '.$d->decided_on->format('d/m/Y') : '' }}
            @if ($d->approved_option)<div>{{ $d->approved_option }}</div>@endif
            @if ($d->expected_effect)<div class="text-muted">{{ $d->expected_effect }}</div>@endif</li>
    @empty
        <li class="list-group-item text-muted">Nenhuma decisão registrada.</li>
    @endforelse
</ul></div>
@endsection
