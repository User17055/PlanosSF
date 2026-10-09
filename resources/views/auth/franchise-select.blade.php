@extends('layouts.app')
@section('title', 'Unidade de atuação')
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
    <h1 class="h4 mb-3">Selecione a unidade de atuação</h1>
    <p class="text-muted">Suas permissões dependem do vínculo com a unidade escolhida. O servidor confere essa escolha em toda operação.</p>
    <div class="list-group">
        @forelse ($memberships as $m)
            <form method="post" action="{{ route('franchise.switch') }}">
                @csrf
                <input type="hidden" name="franchise_id" value="{{ $m->franchise_id }}">
                <button class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-shop"></i> <strong>{{ $m->franchise->name }}</strong>
                        @if ($m->franchise->is_central)<span class="badge text-bg-primary ms-1">Central</span>@endif</span>
                    <span class="text-muted small">{{ $m->role->name }}</span>
                </button>
            </form>
        @empty
            <div class="alert alert-warning">Nenhum vínculo ativo.</div>
        @endforelse
    </div>
</div></div>
@endsection
