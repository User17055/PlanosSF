@extends('layouts.app')
@section('title', $customer->name)
@section('content')
@php($canFinancial = auth()->user()->can('financial.view'))
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">{{ $customer->name }}</h1>
        <div class="text-muted small">CPF {{ $customer->maskedDocument() }} · cadastro desde @date($customer->created_at)
            @if ($customer->createdFranchise) · origem {{ $customer->createdFranchise->name }}@endif</div>
    </div>
    <div class="d-flex gap-2">
        @can('proposals.create')<a href="{{ route('proposals.create', ['customer_id' => $customer->id]) }}" class="btn btn-primary"><i class="bi bi-file-earmark-plus"></i> Nova contratação</a>@endcan
        @can('customers.manage')<a href="{{ route('customers.edit', $customer) }}" class="btn btn-outline-secondary">Editar</a>@endcan
    </div>
</div>

{{-- Situação financeira global (seção 27.7) --}}
@if ($financial->isDelinquent())
    <div class="alert alert-danger block-alert">
        <strong><i class="bi bi-slash-circle"></i> Inadimplente — serviços e benefícios bloqueados.</strong>
        Cliente com mensalidade vencida. Não é possível autorizar ou executar serviços e benefícios até a regularização.
        @if ($canFinancial)<div class="small mt-1">{{ count($financial->overdueInvoiceIds) }} parcela(s) vencida(s), total @money($financial->overdueCents), mais antiga em {{ \Carbon\Carbon::parse($financial->oldestDueDate)->format('d/m/Y') }}.</div>@endif
    </div>
@elseif (! $financial->isRegular())
    <div class="alert alert-warning"><strong>Verificação financeira pendente.</strong> {{ $financial->detail }}</div>
@else
    <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> Situação financeira: <strong>Regular</strong></div>
@endif
<div class="text-muted small mb-3">Informação financeira atualizada em: @datetime($financial->lastSyncedAt)</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">Contato e endereço</div>
            <div class="card-body small">
                <div><i class="bi bi-envelope"></i> {{ $customer->email ?? '—' }} @if ($customer->email_verified_at)<span class="badge text-bg-success">verificado</span>@endif</div>
                <div><i class="bi bi-telephone"></i> {{ $customer->phone ?? '—' }}</div>
                <div class="mt-2"><i class="bi bi-geo-alt"></i> {{ collect([$customer->street, $customer->number, $customer->complement, $customer->district, $customer->city ? $customer->city.'/'.$customer->state : null, $customer->zip_code])->filter()->implode(', ') ?: '—' }}</div>
                @if (! $customer->isComplete())
                    <div class="alert alert-warning small mt-2 mb-0">Cadastro incompleto para contratação: {{ implode(', ', $customer->missingFields()) }}.</div>
                @endif
                <div class="mt-2 text-muted">Marketing: {{ $customer->marketing_opt_in ? 'aceito em '.\App\Support\BusinessTime::formatDate($customer->marketing_opt_in_at) : 'não aceito' }}</div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">Pets
                @can('customers.manage')<a href="{{ route('pets.create', $customer) }}" class="small">+ adicionar</a>@endcan</div>
            <ul class="list-group list-group-flush">
                @forelse ($customer->pets as $pet)
                    @php($covered = $subscriptions->where('pet_id', $pet->id)->filter->isActive())
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>{{ $pet->name }}</strong> <span class="text-muted small">{{ $pet->species->label() }}{{ $pet->breed ? ' · '.$pet->breed : '' }}</span>
                            <div class="small">
                                @if ($covered->isNotEmpty())<span class="text-success"><i class="bi bi-shield-check"></i> coberto: {{ $covered->map(fn($s) => $s->planVersion->plan->name)->implode(', ') }}</span>
                                @else<span class="text-muted">sem plano ativo</span>@endif
                            </div>
                        </div>
                        @can('customers.manage')<a href="{{ route('pets.edit', $pet) }}" class="btn btn-sm btn-link">editar</a>@endcan
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nenhum pet cadastrado.</li>
                @endforelse
            </ul>
        </div>

        <div class="card mb-3">
            <div class="card-header">Anexos (não substituem o contrato)</div>
            <ul class="list-group list-group-flush small">
                @forelse ($customer->attachments as $a)
                    <li class="list-group-item">{{ \App\Models\CustomerAttachment::KINDS[$a->kind] ?? $a->kind }} — @datetime($a->created_at) por {{ $a->uploader?->name }}
                        @can('contracts.view')<a href="{{ route('attachments.download', $a) }}">baixar</a>@endcan
                        @if ($a->description)<div class="text-muted">{{ $a->description }}</div>@endif</li>
                @empty
                    <li class="list-group-item text-muted">Nenhum anexo.</li>
                @endforelse
            </ul>
            @can('documents.upload')
            <form method="post" action="{{ route('customers.attach', $customer) }}" enctype="multipart/form-data" class="card-body border-top" data-once>
                @csrf
                <div class="row g-2">
                    <div class="col-12"><select name="kind" class="form-select form-select-sm">
                        @foreach (\App\Models\CustomerAttachment::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select></div>
                    <div class="col-12"><input type="file" name="file" class="form-control form-control-sm" required accept=".pdf,.jpg,.jpeg,.png"></div>
                    <div class="col-12"><input name="description" class="form-control form-control-sm" placeholder="Descrição"></div>
                    <div class="col-12"><button class="btn btn-sm btn-outline-primary">Anexar</button></div>
                </div>
            </form>
            @endcan
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">Assinaturas</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Pet</th><th>Plano</th><th>Situação</th><th>Vigência</th><th>Impedimentos</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($subscriptions as $s)
                        @php($visible = $ctx->canSeeFranchiseRecord($s->originating_franchise_id))
                        <tr>
                            <td>{{ $s->pet->name }}</td>
                            <td>{{ $s->planVersion->plan->name }} <span class="text-muted small">v{{ $s->planVersion->version }}</span></td>
                            <td><x-status :value="$s->status" /></td>
                            <td class="small">{{ $s->effective_start_at ? 'desde '.\App\Support\BusinessTime::formatDate($s->effective_start_at) : '—' }}</td>
                            <td class="small">
                                @foreach ($impediments[$s->id] as $imp)<span class="badge text-bg-light border text-danger">{{ $imp->label() }}</span> @endforeach
                            </td>
                            <td class="text-end">
                                @if ($visible)<a href="{{ route('subscriptions.show', $s) }}" class="btn btn-sm btn-outline-primary">Detalhes</a>
                                @else<span class="small text-muted">outra unidade</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted text-center py-3">Nenhuma assinatura.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($canFinancial)
        <div class="card mb-3">
            <div class="card-header">Parcelas</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Assinatura</th><th>Competência</th><th>Vencimento</th><th>Valor</th><th>Pago</th><th>Situação</th><th>Atualizado</th></tr></thead>
                    <tbody>
                    @php($invoices = $subscriptions->filter(fn($s) => $ctx->canSeeFranchiseRecord($s->originating_franchise_id))->flatMap->invoices->sortByDesc('due_date'))
                    @forelse ($invoices as $i)
                        <tr @class(['table-danger' => $i->isOverdue()])>
                            <td class="small">{{ $subscriptions->firstWhere('id', $i->subscription_id)->number }}</td>
                            <td class="small">{{ $i->period_start?->format('d/m/Y') }} – {{ $i->period_end?->format('d/m/Y') }}</td>
                            <td>{{ $i->due_date->format('d/m/Y') }}</td>
                            <td>@money($i->amount_cents)</td>
                            <td>@money($i->paid_cents)</td>
                            <td><x-status :value="$i->status" /> @if ($i->isOverdue())<span class="badge text-bg-danger">vencida</span>@endif</td>
                            <td class="small text-muted">@datetime($i->last_synced_at)</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted text-center py-3">Nenhuma parcela.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        <div class="card mb-3">
            <div class="card-header">Propostas</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Número</th><th>Pet</th><th>Plano</th><th>Canal</th><th>Unidade/vendedor</th><th>Situação</th><th>Validade</th></tr></thead>
                    <tbody>
                    @forelse ($proposals as $p)
                        <tr>
                            <td>@if ($ctx->canSeeFranchiseRecord($p->originating_franchise_id))<a href="{{ route('proposals.show', $p) }}">{{ $p->number }}</a>@else{{ $p->number }}@endif</td>
                            <td>{{ $p->pet?->name ?? '—' }}</td>
                            <td>{{ $p->planVersion->plan->name }}</td>
                            <td>{{ $p->channel->label() }}</td>
                            <td class="small">{{ $p->originatingFranchise->name }} · {{ $p->seller?->name ?? '—' }}</td>
                            <td><x-status :value="$p->status" /></td>
                            <td class="small">@datetime($p->expires_at)</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted text-center py-3">Nenhuma proposta.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Integrações</div>
            <ul class="list-group list-group-flush small">
                @forelse ($integrations as $j)
                    <li class="list-group-item d-flex justify-content-between">
                        <span>{{ $j->destinationLabel() }} — {{ $j->operationLabel() }} @if ($j->mode === 'manual')<span class="badge text-bg-light border">manual</span>@endif</span>
                        <span><x-status :value="$j->status" /> <span class="text-muted">@datetime($j->updated_at)</span></span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nenhuma integração registrada.</li>
                @endforelse
            </ul>
        </div>

        <div class="card">
            <div class="card-header">Histórico</div>
            <ul class="list-group list-group-flush small">
                @foreach ($customer->financialEvents as $e)
                    <li class="list-group-item"><strong>{{ $e->event === 'blocked' ? 'Bloqueio financeiro' : 'Desbloqueio financeiro' }}</strong> — @datetime($e->occurred_at) · {{ $e->reason }}</li>
                @endforeach
                @foreach ($history as $h)
                    <li class="list-group-item">{{ $h->action }} — @datetime($h->created_at) · {{ $h->user?->name ?? ($h->actor_type === 'customer' ? 'titular' : 'sistema') }} @if ($h->reason)<span class="text-muted">({{ $h->reason }})</span>@endif</li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
@endsection
