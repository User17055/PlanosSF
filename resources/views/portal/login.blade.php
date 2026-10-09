@extends('layouts.public')
@section('title', 'Área do cliente')
@section('content')
<div class="row justify-content-center"><div class="col-md-6">
<h1 class="h4 mb-3">Área do cliente</h1>
<div class="card card-body">
    @if (! session('code_sent') && ! $errors->has('code'))
        <form method="post" action="{{ route('portal.login.send') }}" data-once>@csrf
            <label class="form-label">CPF do titular</label>
            <input name="document" class="form-control mb-3" inputmode="numeric" placeholder="000.000.000-00" required autofocus>
            <button class="btn btn-primary w-100">Receber código por e-mail</button>
        </form>
    @else
        <form method="post" action="{{ route('portal.login.verify') }}" data-once>@csrf
            <label class="form-label">Código recebido por e-mail</label>
            <input name="code" class="form-control mb-3" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required autofocus>
            <button class="btn btn-primary w-100">Entrar</button>
        </form>
        <a href="{{ route('portal.login') }}" class="small mt-2">Usar outro CPF</a>
    @endif
</div>
</div></div>
@endsection
