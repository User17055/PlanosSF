<form method="post" action="{{ $action }}" class="p-3 bg-light">
    @csrf
    @if ($method === 'put') @method('put') @endif
    <div class="row g-2 small">
        <div class="col-md-4"><label class="form-label">Código do serviço *</label>
            <input name="service_code" class="form-control form-control-sm text-uppercase" value="{{ $rule->service_code }}" required></div>
        <div class="col-md-8"><label class="form-label">Descrição *</label>
            <input name="description" class="form-control form-control-sm" value="{{ $rule->description }}" required></div>
        <div class="col-md-4"><label class="form-label">Tipo</label>
            <select name="coverage_type" class="form-select form-select-sm">
                @foreach (\App\Enums\CoverageType::options() as $v => $l)<option value="{{ $v }}" @selected($rule->coverage_type?->value === $v)>{{ $l }}</option>@endforeach
            </select></div>
        <div class="col-md-4"><label class="form-label">Desconto (%)</label>
            <input name="discount_percent" type="number" step="0.01" class="form-control form-control-sm" value="{{ $rule->discount_percent }}"></div>
        <div class="col-md-4"><label class="form-label">Carência (dias) *</label>
            <input name="waiting_days" type="number" min="0" class="form-control form-control-sm" value="{{ $rule->waiting_days ?? 0 }}" required></div>
        <div class="col-md-3"><label class="form-label">Quantidade</label>
            <input name="quantity_limit" type="number" min="1" class="form-control form-control-sm" value="{{ $rule->quantity_limit }}" placeholder="vazio = ilimitado"></div>
        <div class="col-md-3"><label class="form-label">Unidade *</label>
            <input name="unit" class="form-control form-control-sm" value="{{ $rule->unit ?? 'unidade' }}" required></div>
        <div class="col-md-4"><label class="form-label">Período de renovação</label>
            <select name="period_type" class="form-select form-select-sm">
                <option value="">— (obrigatório se houver limite)</option>
                @foreach (\App\Enums\PeriodType::options() as $v => $l)<option value="{{ $v }}" @selected($rule->period_type?->value === $v)>{{ $l }}</option>@endforeach
            </select></div>
        <div class="col-md-2"><label class="form-label">Janela (dias)</label>
            <input name="rolling_window_days" type="number" class="form-control form-control-sm" value="{{ $rule->rolling_window_days }}"></div>
        <div class="col-md-3"><label class="form-label">Intervalo mínimo (dias)</label>
            <input name="min_interval_days" type="number" class="form-control form-control-sm" value="{{ $rule->min_interval_days }}"></div>
        <div class="col-md-3"><label class="form-label">Coparticipação (R$)</label>
            <input name="copay" class="form-control form-control-sm" value="{{ \App\Support\Money::input($rule->copay_cents) }}"></div>
        <div class="col-md-3"><label class="form-label">Quem recebe</label>
            <input name="copay_receiver" class="form-control form-control-sm" value="{{ $rule->copay_receiver }}"></div>
        <div class="col-md-3"><label class="form-label">Etapa da cobrança</label>
            <select name="copay_stage" class="form-select form-select-sm">
                <option value="">—</option>
                <option value="antes_da_execucao" @selected($rule->copay_stage === 'antes_da_execucao')>Antes da execução</option>
                <option value="pendencia_externa" @selected($rule->copay_stage === 'pendencia_externa')>Pendência em outro sistema</option>
            </select></div>
        <div class="col-md-3"><label class="form-label">Limite monetário (R$)</label>
            <input name="monetary_limit" class="form-control form-control-sm" value="{{ \App\Support\Money::input($rule->monetary_limit_cents) }}"></div>
        <div class="col-md-3"><label class="form-label">Grupo de cobertura</label>
            <input name="coverage_group" class="form-control form-control-sm" value="{{ $rule->coverage_group }}"></div>
        <div class="col-md-3"><label class="form-label">Limite do grupo</label>
            <input name="group_limit" type="number" class="form-control form-control-sm" value="{{ $rule->group_limit }}"></div>
        <div class="col-md-3 d-flex align-items-end"><div class="form-check">
            <input type="hidden" name="requires_authorization" value="0">
            <input class="form-check-input" type="checkbox" name="requires_authorization" value="1" @checked($rule->requires_authorization ?? true)>
            <label class="form-check-label">Exige autorização</label></div></div>
        <div class="col-12"><label class="form-label">Exclusões</label>
            <textarea name="exclusions" class="form-control form-control-sm" rows="2">{{ $rule->exclusions }}</textarea></div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Salvar benefício</button></div>
    </div>
</form>
