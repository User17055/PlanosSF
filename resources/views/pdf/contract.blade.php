<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 2.2cm 1.8cm 2cm 1.8cm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #111; line-height: 1.45; }
    h1 { font-size: 14pt; margin: 0 0 4pt; }
    h2 { font-size: 11pt; margin: 14pt 0 6pt; border-bottom: 1px solid #999; padding-bottom: 2pt; }
    .meta { font-size: 8.5pt; color: #444; }
    table { width: 100%; border-collapse: collapse; margin-top: 4pt; }
    th, td { border: 1px solid #bbb; padding: 3pt 5pt; text-align: left; vertical-align: top; font-size: 9pt; }
    th { background: #eee; }
    .body { white-space: pre-wrap; text-align: justify; }
    .box { border: 1px solid #999; padding: 6pt; margin-top: 8pt; page-break-inside: avoid; }
    .sig { height: 80pt; }
    .sig img { max-height: 80pt; max-width: 260pt; }
    .line { border-top: 1px solid #000; width: 260pt; margin-top: 50pt; padding-top: 3pt; font-size: 9pt; }
    .footer { position: fixed; bottom: -1.3cm; left: 0; right: 0; font-size: 7.5pt; color: #666; text-align: center; }
    .warn { background: #fff3cd; border: 1px solid #e0b100; padding: 4pt; font-size: 8.5pt; margin-bottom: 8pt; }
</style>
</head>
<body>
@php($c = $data['conditions'])
<div class="footer">Contrato {{ $data['contract_number'] }} · Proposta {{ $data['proposal_number'] }} · Plano {{ $c['plan']['code'] }} v{{ $c['version']['number'] }} · Tutor {{ $data['customer']['name'] }} · Pet {{ $data['pet']['name'] }}</div>

@if (config('planos.is_homologation'))<div class="warn">DOCUMENTO DE HOMOLOGAÇÃO — sem validade comercial.</div>@endif
@unless ($final)<div class="warn">VIA PARA IMPRESSÃO E ASSINATURA. Após assinada, digitalize todas as páginas e anexe ao cadastro.</div>@endunless

<h1>Contrato de Plano de Saúde Veterinária — São Francisco</h1>
<div class="meta">
    Contrato nº {{ $data['contract_number'] }} · Proposta nº {{ $data['proposal_number'] }} ·
    Plano {{ $c['version']['display_name'] }} ({{ $c['plan']['code'] }}, versão {{ $c['version']['number'] }}) ·
    Modelo {{ $c['contract_template']['name'] ?? '' }} v{{ $c['contract_template']['version'] ?? '' }} ·
    Unidade de origem {{ $data['franchise']['name'] }} · Emitido em {{ \Carbon\Carbon::parse($data['issued_at'])->setTimezone(config('planos.business_timezone'))->format('d/m/Y H:i') }}
</div>

<h2>Partes e pet coberto</h2>
<table>
    <tr><th style="width: 25%">Titular</th><td>{{ $data['customer']['name'] }} — CPF {{ $data['customer']['document'] }}</td></tr>
    <tr><th>Contato</th><td>{{ $data['customer']['email'] }} · {{ $data['customer']['phone'] }}</td></tr>
    <tr><th>Endereço</th><td>{{ $data['customer']['address'] }}</td></tr>
    <tr><th>Pet coberto</th><td>{{ $data['pet']['name'] }} — {{ $data['pet']['species'] }}{{ $data['pet']['breed'] ? ', '.$data['pet']['breed'] : '' }}{{ $data['pet']['birth'] ? ' — '.$data['pet']['birth'] : '' }}</td></tr>
</table>

<h2>Condições comerciais</h2>
<table>
    <tr><th style="width: 25%">Mensalidade</th><td>{{ \App\Support\Money::format($data['price_cents']) }} — cobrança a cada {{ $c['billing_interval_months'] }} mês(es)</td></tr>
    <tr><th>Adesão</th><td>{{ \App\Support\Money::format($data['enrollment_fee_cents']) }}</td></tr>
    @if ($c['promo'])<tr><th>Promoção</th><td>{{ \App\Support\Money::format($c['promo']['price_cents']) }} por {{ $c['promo']['duration_months'] ?? '—' }} mês(es) — origem: {{ $c['promo']['origin'] }}. Preço-base: {{ \App\Support\Money::format($c['base_price_cents']) }}</td></tr>@endif
    <tr><th>Duração contratual</th><td>{{ $c['contract_duration_months'] }} meses</td></tr>
    <tr><th>Renovação</th><td>{{ $c['renewal_policy'] }}</td></tr>
    <tr><th>Início da vigência</th><td>Na data em que contrato válido e primeiro pagamento integral estiverem confirmados{{ $data['desired_start_date'] ? ', não antes de '.\Carbon\Carbon::parse($data['desired_start_date'])->format('d/m/Y') : '' }}. A carência de cada benefício conta a partir desse início.</td></tr>
</table>

<h2>Benefícios, limites e carências</h2>
<table>
    <tr><th>Benefício</th><th>Cobertura</th><th>Limite</th><th>Carência</th><th>Observações</th></tr>
    @foreach ($c['benefits'] as $b)
        <tr>
            <td>{{ $b['description'] }} ({{ $b['service_code'] }})</td>
            <td>{{ $b['coverage_label'] }}{{ $b['discount_percent'] ? ' — '.\App\Support\BenefitText::percent($b['discount_percent']) : '' }}</td>
            <td>{{ $b['quantity_limit'] ? \App\Support\BenefitText::quantity($b['quantity_limit'], $b['unit']).' por '.mb_strtolower($b['period_label'] ?? '') : 'Sem limite de quantidade' }}{{ $b['rolling_window_days'] ? ' ('.$b['rolling_window_days'].' dias)' : '' }}</td>
            <td>{{ $b['waiting_days'] }} dias</td>
            <td>
                @if ($b['copay_cents'])Coparticipação {{ \App\Support\Money::format($b['copay_cents']) }}. @endif
                @if ($b['min_interval_days'])Intervalo mínimo {{ $b['min_interval_days'] }} dias. @endif
                @if ($b['coverage_group'])Limite compartilhado "{{ $b['coverage_group'] }}": {{ $b['group_limit'] }}. @endif
                @if ($b['exclusions'])Exclusões: {{ $b['exclusions'] }}@endif
            </td>
        </tr>
    @endforeach
</table>

<h2>Cláusulas</h2>
<div class="body">{{ $contract->body_text }}</div>

<h2>Formalização</h2>
@if ($final && $evidence)
    <div class="box">
        <strong>Forma de assinatura:</strong> {{ ['digital' => 'Aceite digital pelo titular', 'tablet' => 'Assinatura manuscrita em tablet, presencial'][$evidence->method] ?? $evidence->method }}<br>
        <strong>Declaração:</strong> {{ $evidence->affirmative_action }}<br>
        <strong>Data/hora:</strong> {{ $evidence->accepted_at->setTimezone(config('planos.business_timezone'))->format('d/m/Y H:i:s') }} (horário de Brasília)<br>
        @if ($evidence->ip_address)<strong>IP:</strong> {{ $evidence->ip_address }}<br>@endif
        @if ($evidence->verification_result === 'verified')<strong>Confirmação de contato:</strong> código de uso único verificado (registro nº {{ $evidence->contact_verification_id }})<br>@endif
        <strong>Termos:</strong> {{ $evidence->terms_version }}
        @if ($signature)
            <div class="sig"><img src="{{ $signature }}" alt="assinatura"></div>
            <div style="font-size: 9pt">{{ $data['customer']['name'] }} — titular</div>
        @endif
    </div>
@else
    <div class="line">{{ $data['customer']['name'] }} — titular · CPF {{ $data['customer']['document'] }}</div>
    <div style="margin-top: 14pt">Local e data: ______________________________, ____/____/________</div>
    <div class="line">{{ $data['seller']['name'] ?? 'Representante' }} — {{ $data['franchise']['name'] }}</div>
@endif
</body>
</html>
