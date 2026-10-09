<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Entrar · PlanosSF</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="d-flex align-items-center min-vh-100">
<div class="container" style="max-width: 420px">
    @if (config('planos.is_homologation'))<div class="sf-homolog-banner rounded mb-3">HOMOLOGAÇÃO</div>@endif
    <div class="text-center mb-4">
        <h1 class="h3 text-primary"><i class="bi bi-heart-pulse"></i> Planos SF</h1>
        <p class="text-muted">Acesso da equipe e das franquias</p>
    </div>
    <div class="card"><div class="card-body p-4">
        @include('partials.flash')
        <form method="post" action="{{ url('/entrar') }}" data-once>
            @csrf
            <div class="mb-3">
                <label class="form-label">E-mail</label>
                <input type="email" name="email" value="{{ old('email') }}" class="form-control" required autofocus autocomplete="username">
            </div>
            <div class="mb-3">
                <label class="form-label">Senha</label>
                <input type="password" name="password" class="form-control" required autocomplete="current-password">
            </div>
            <button class="btn btn-primary w-100" type="submit">Entrar</button>
        </form>
    </div></div>
    <p class="text-center small mt-3"><a href="{{ route('portal.login') }}">Sou cliente — acessar o portal</a></p>
</div>
</body>
</html>
