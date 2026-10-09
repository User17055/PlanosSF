@extends('layouts.app')
@section('title', 'Proposta '.$proposal->number)
@section('content')
@php($s = $proposal->conditions_snapshot)
@php($contract = $proposal->contract)
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Proposta {{ $proposal->number }} <x-status :value="$proposal->status" /></h1>
        <div class="text-muted small">{{ $proposal->channel->label() }} · {{ $proposal->signature_method->label() }} · {{ $proposal->fill_mode->label() }}
            · {{ $proposal->originatingFranchise->name }} · vendedor {{ $proposal->seller?->name ?? '—' }} · validade @datetime($proposal->expires_at)</div>
    </div>
    <div class="d-flex gap-2">
        @if (! $proposal->hasBeenAccepted() && ! $proposal->isFinal() && ! $contract)
            @can('proposals.create')
                <a href="{{ route('proposals.create', ['customer_id' => $proposal->customer_id, 'supersedes_id' => $proposal->id]) }}" class="btn btn-outline-secondary">Nova versão da proposta</a>
                <button class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#revoke">Revogar</button>
            @endcan
        @endif
    </div>
</div>

<div class="collapse mb-3" id="revoke">
    <form method="post" action="{{ route('proposals.revoke', $proposal) }}" class="card card-body">@csrf
        <label class="form-label">Motivo da revogação</label><input name="reason" class="form-control mb-2" required>
        <button class="btn btn-danger">Revogar proposta</button></form>
</div>

@if ($warnings)<div class="alert alert-warning">@foreach ($warnings as $w)<div><i class="bi bi-exclamation-triangle"></i> {{ $w }}</div>@endforeach</div>@endif

@if (($proposal->form_data['conflict'] ?? false))
    <div class="alert alert-danger">
        <strong>Revisão cadastral necessária.</strong> O CPF informado pelo cliente já existe com outro e-mail. Coincidência de dados não basta para vincular pessoas.
        @can('customers.merge')
            <form method="post" action="{{ route('proposals.resolve', $proposal) }}" class="row g-2 mt-2">@csrf
                <div class="col-md-3"><input name="customer_id" class="form-control" placeholder="ID do cadastro confirmado" required></div>
                <div class="col-md-6"><input name="reason" class="form-control" placeholder="Como a titularidade foi verificada" required></div>
                <div class="col-md-3"><button class="btn btn-danger w-100">Vincular</button></div>
            </form>
        @endcan
    </div>
@endif

<div class="row g-3">
<div class="col-lg-7">
    {{-- Próxima ação conforme canal e forma de assinatura --}}
    <div class="card mb-3">
        <div class="card-header">Próximos passos</div>
        <div class="card-body">
            @if ($proposal->channel->value === 'link' || $proposal->channel->value === 'site')
                @if ($newLink)
                    <label class="form-label">Link para o cliente (exibido somente agora)</label>
                    <div class="input-group mb-2">
                        <input id="new-link" class="form-control" value="{{ $newLink }}" readonly>
                        <button class="btn btn-outline-primary" type="button" data-copy="#new-link"><i class="bi bi-clipboard"></i> Copiar</button>
                        <button class="btn btn-outline-primary" type="button" data-share="{{ $newLink }}" data-share-title="Proposta de plano São Francisco"><i class="bi bi-share"></i></button>
                    </div>
                    <form method="post" action="{{ route('proposals.link.sent', $proposal) }}" class="d-flex gap-2 align-items-center mb-2">@csrf
                        <span class="small">Registrar envio por:</span>
                        <select name="channel" class="form-select form-select-sm w-auto"><option value="whatsapp">WhatsApp</option><option value="email">E-mail</option><option value="sms">SMS</option><option value="presencial">Presencial</option><option value="outro">Outro</option></select>
                        <button class="btn btn-sm btn-outline-secondary">Registrar</button>
                    </form>
                    <div class="small text-muted">O envio automático por WhatsApp/SMS/e-mail depende de canal contratado. Abertura do link não comprova leitura nem aceite.</div>
                @endif
                @if (! $proposal->hasBeenAccepted() && ! $proposal->isFinal())
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        @can('proposals.manage_links')
                            <form method="post" action="{{ route('proposals.link', $proposal) }}">@csrf<button class="btn btn-primary"><i class="bi bi-link-45deg"></i> {{ $proposal->token_hash ? 'Gerar novo link (revoga o anterior)' : 'Gerar link' }}</button></form>
                            @if ($proposal->linkIsUsable())
                                <form method="post" action="{{ route('proposals.link.revoke', $proposal) }}" data-confirm="Revogar o link atual?">@csrf<button class="btn btn-outline-danger">Revogar link</button></form>
                            @endif
                        @endcan
                        <span class="small text-muted">
                            @if ($proposal->linkIsUsable()) Link válido até @datetime($proposal->token_expires_at) @elseif ($proposal->token_revoked_at) Link revogado @elseif ($proposal->token_hash) Link expirado @endif
                            @if ($proposal->link_sent_at) · enviado por {{ $proposal->link_sent_channel }} em @datetime($proposal->link_sent_at) @endif
                            @if ($proposal->link_first_opened_at) · aberto em @datetime($proposal->link_first_opened_at) @endif
                        </span>
                    </div>
                @endif
            @endif

            @if ($proposal->signature_method->value === 'tablet' && ! $contract && $proposal->isOpen())
                @can('documents.upload')
                    @if ($missing)<div class="alert alert-warning small">Complete o cadastro antes da assinatura: {{ implode(', ', $missing) }}.</div>
                    @else<a href="{{ route('tablet.show', $proposal) }}" class="btn btn-primary"><i class="bi bi-tablet"></i> Abrir tela de assinatura no tablet</a>@endif
                @endcan
            @endif

            @if ($proposal->signature_method->value === 'paper')
                @if (! $contract && $proposal->isOpen())
                    @can('documents.upload')
                        @if ($missing)<div class="alert alert-warning small">Complete o cadastro antes de gerar o contrato: {{ implode(', ', $missing) }}.</div>
                        @else
                        <form method="post" action="{{ route('paper.prepare', $proposal) }}" data-once data-confirm="Gerar o contrato para impressão? Os dados atuais serão congelados no documento.">@csrf
                            <button class="btn btn-primary"><i class="bi bi-printer"></i> Gerar contrato para impressão</button></form>
                        @endif
                    @endcan
                @elseif ($contract)
                    @php($print = $contract->files->firstWhere('kind', 'print_pdf'))
                    @if ($print)<a href="{{ route('documents.download', $print) }}" class="btn btn-outline-primary mb-3"><i class="bi bi-printer"></i> Baixar via para impressão</a>@endif
                    @if (in_array($contract->document_status->value, ['awaiting_signature', 'rejected', 'awaiting_review']))
                        @can('documents.upload')
                        <form method="post" action="{{ route('documents.upload', $contract) }}" enctype="multipart/form-data" class="border rounded p-3" data-once>@csrf
                            <div class="fw-semibold mb-2">Anexar contrato assinado e digitalizado (todas as páginas)</div>
                            <div class="row g-2">
                                <div class="col-md-7"><input type="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required></div>
                                <div class="col-md-5"><input type="date" name="signed_on" class="form-control" required max="{{ now(config('planos.business_timezone'))->toDateString() }}" title="Data da assinatura"></div>
                                @if ($contract->document_status->value !== 'awaiting_signature')
                                    <div class="col-12"><input name="replacement_reason" class="form-control" placeholder="Motivo da substituição (obrigatório se houver arquivo pendente)"></div>
                                @endif
                                <div class="col-12"><button class="btn btn-primary">Enviar para conferência</button></div>
                            </div>
                            <div class="form-text">Arquivo ausente, ilegível, incompleto ou sem assinatura mantém a pendência documental.</div>
                        </form>
                        @endcan
                    @endif
                @endif
            @endif

            @if ($subscription)
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <div>Assinatura <a href="{{ route('subscriptions.show', $subscription) }}">{{ $subscription->number }}</a> <x-status :value="$subscription->status" /></div>
                </div>
                @if ($impediments)
                    <div class="mt-2">@foreach ($impediments as $imp)<span class="badge text-bg-light border text-danger me-1">{{ $imp->label() }}</span>@endforeach</div>
                @endif

                @if ($proposal->channel->value === 'in_person' && $subscription->status->value === 'awaiting_regularization')
                    <div class="mt-3">
                        @if (! $inPersonEnabled)
                            <div class="alert alert-warning small mb-0"><strong>Pagamento presencial — decisão pendente (seção 7.3).</strong> A contratação foi registrada e permanece aguardando confirmação financeira. Assinatura no tablet ou papel não ativa o plano.</div>
                        @elseif ($initialInvoice && $initialInvoice->status->value !== 'paid')
                            <a href="{{ route('financial.invoice', $initialInvoice) }}" class="btn btn-outline-primary">Registrar recebimento da 1ª parcela (@money($initialInvoice->amount_cents))</a>
                        @elseif (! $initialInvoice)
                            <form method="post" action="{{ route('subscriptions.checkout', $subscription) }}">@csrf<button class="btn btn-outline-primary">Registrar 1ª parcela presencial</button></form>
                        @endif
                    </div>
                @elseif ($subscription->status->value === 'awaiting_regularization' && $initialInvoice?->payment_url && $initialInvoice->status->value !== 'paid')
                    <div class="input-group mt-3">
                        <span class="input-group-text">Pagamento</span>
                        <input id="pay-link" class="form-control" value="{{ $initialInvoice->payment_url }}" readonly>
                        <button class="btn btn-outline-primary" type="button" data-copy="#pay-link">Copiar</button>
                    </div>
                    <div class="form-text">O cliente também acessa o pagamento pelo link da proposta. Voltar da página de pagamento não confirma pagamento.</div>
                @endif
            @endif
        </div>
    </div>

    @if ($contract)
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between">Contrato {{ $contract->number }} <x-status :value="$contract->document_status" /></div>
        <div class="card-body small">
            <div>Assinado em: @datetime($contract->signed_at) · validado em: @datetime($contract->validated_at)</div>
            @if ($contract->rejection_reason)<div class="text-danger">Rejeição: {{ $contract->rejection_reason }}</div>@endif
            @if ($contract->pdf_failed_at)<div class="text-danger">Falha ao gerar PDF em @datetime($contract->pdf_failed_at): {{ $contract->pdf_error }}
                @can('integrations.reprocess')<form method="post" action="{{ route('documents.regenerate', $contract) }}" class="d-inline">@csrf<button class="btn btn-sm btn-link">reprocessar</button></form>@endcan</div>@endif
        </div>
        <ul class="list-group list-group-flush small">
            @foreach ($contract->files as $f)
                <li class="list-group-item d-flex justify-content-between">
                    <span>{{ ['final_pdf' => 'PDF final', 'print_pdf' => 'Via para impressão', 'signed_scan' => 'Digitalização'][$f->kind] ?? $f->kind }} v{{ $f->version }}
                        · enviado @datetime($f->created_at) {{ $f->uploader ? 'por '.$f->uploader->name : '' }}
                        @if ($f->reviewed_at)· conferido por {{ $f->reviewer?->name ?? 'sistema' }} @endif
                        @if ($f->review_reason)<br><span class="text-muted">{{ $f->review_reason }}</span>@endif
                        @if ($f->replacement_reason)<br><span class="text-muted">Substituição: {{ $f->replacement_reason }}</span>@endif
                        <br><code class="text-muted">sha256 {{ substr($f->sha256, 0, 16) }}…</code></span>
                    <span><x-status :value="$f->review_status" /> @can('contracts.view')<a href="{{ route('documents.download', $f) }}">baixar</a>@endcan</span>
                </li>
            @endforeach
            @foreach ($contract->evidence as $e)
                <li class="list-group-item text-muted">Evidência: {{ $e->method }} · {{ $e->affirmative_action }} · @datetime($e->accepted_at) {{ $e->ip_address ? '· IP '.$e->ip_address : '' }} · {{ $e->verification_result }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="card">
        <div class="card-header">Histórico</div>
        <ul class="list-group list-group-flush small">
            @foreach ($history as $h)
                <li class="list-group-item">{{ $h->action }} — @datetime($h->created_at) · {{ $h->user?->name ?? ($h->actor_type === 'customer' ? 'titular' : 'sistema') }} @if ($h->reason)<span class="text-muted">({{ $h->reason }})</span>@endif</li>
            @endforeach
        </ul>
    </div>
</div>

<div class="col-lg-5">
    <div class="card mb-3">
        <div class="card-header">Dados para o contrato</div>
        <div class="card-body small">
            <div class="small-label">Tutor</div>
            <div>{{ $merged['customer']['name'] ?? '—' }} · CPF {{ \App\Support\Mask::document($merged['customer']['document'] ?? null) }}</div>
            <div>{{ \App\Support\Mask::email($merged['customer']['email'] ?? null) }} · {{ \App\Support\Mask::phone($merged['customer']['phone'] ?? null) }}</div>
            <div class="small-label mt-2">Pet</div>
            <div>{{ $merged['pet']['name'] ?? '—' }} · {{ \App\Enums\Species::tryFrom($merged['pet']['species'] ?? '')?->label() }}</div>
            @if ($missing)<div class="alert alert-warning small mt-2 mb-0">Pendente: {{ implode(', ', $missing) }}</div>@endif
            @if ($proposal->customer)<div class="mt-2"><a href="{{ route('customers.show', $proposal->customer) }}">Abrir perfil do cliente</a></div>@endif
        </div>
    </div>
    <div class="card">
        <div class="card-header">Condições fixadas na proposta</div>
        <div class="card-body small">
            <div><strong>{{ $s['version']['display_name'] }}</strong> ({{ $s['plan']['code'] }} v{{ $s['version']['number'] }})</div>
            <div>Mensalidade: <strong>@money($proposal->price_cents)</strong> · adesão @money($proposal->enrollment_fee_cents) · cobrança a cada {{ $s['billing_interval_months'] }} mês(es)</div>
            <div>Duração: {{ $s['contract_duration_months'] }} meses · modelo {{ $s['contract_template']['name'] ?? '' }} v{{ $s['contract_template']['version'] ?? '' }}</div>
            @if ($proposal->desired_start_date)<div>Início desejado: {{ $proposal->desired_start_date->format('d/m/Y') }}</div>@endif
            <table class="table table-sm mt-2 mb-0">
                <thead><tr><th>Benefício</th><th>Limite</th><th>Carência</th></tr></thead>
                <tbody>@foreach ($s['benefits'] as $b)
                    <tr><td>{{ $b['description'] }}</td><td>{{ $b['quantity_limit'] ? $b['quantity_limit'].' / '.mb_strtolower($b['period_label'] ?? '') : 'sem limite' }}</td><td>{{ $b['waiting_days'] }} dias</td></tr>
                @endforeach</tbody>
            </table>
        </div>
    </div>
</div>
</div>
@endsection
