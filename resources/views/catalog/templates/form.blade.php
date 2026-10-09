@extends('layouts.app')
@section('title', 'Modelo de contrato')
@section('content')
<h1 class="h4 mb-3">{{ $template->exists ? $template->name.' v'.$template->version : 'Novo modelo de contrato' }}</h1>
<div class="row g-3">
    <div class="col-lg-8">
        <form method="post" action="{{ $template->exists ? route('templates.update', $template) : route('templates.store') }}" class="card card-body">
            @csrf
            @if ($template->exists) @method('put') @endif
            <fieldset @disabled($template->isLocked())>
                @unless ($template->exists)
                    <div class="mb-3"><label class="form-label">Nome *</label><input name="name" class="form-control" value="{{ old('name') }}" required></div>
                @endunless
                <label class="form-label">Texto integral *</label>
                <textarea name="body" class="form-control font-monospace" rows="24" required>{{ old('body', $template->body) }}</textarea>
                @unless ($template->isLocked())<button class="btn btn-primary mt-3">Salvar</button>@endunless
            </fieldset>
        </form>
    </div>
    <div class="col-lg-4">
        <div class="card"><div class="card-header">Campos disponíveis</div>
            <ul class="list-group list-group-flush small">
                @foreach ($placeholders as $k => $l)<li class="list-group-item"><code>{{ '{'.'{ '.$k.' }'.'}' }}</code> — {{ $l }}</li>@endforeach
            </ul>
            <div class="card-body small text-muted">Os valores são preenchidos no aceite e congelados no documento final. A tabela de benefícios e as evidências de assinatura são anexadas automaticamente ao PDF.</div>
        </div>
    </div>
</div>
@endsection
