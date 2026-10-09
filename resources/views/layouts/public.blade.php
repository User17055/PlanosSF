<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if (config('planos.is_homologation'))<meta name="robots" content="noindex, nofollow">@endif
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Planos de Saúde Pet') · São Francisco</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
@if (config('planos.is_homologation'))
    <div class="sf-homolog-banner">AMBIENTE DE TESTE — nenhuma cobrança real é realizada</div>
@endif
<nav class="navbar sf-navbar navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="{{ route('site.plans') }}"><i class="bi bi-heart-pulse"></i> São Francisco · Planos Pet</a>
        @yield('nav-right')
    </div>
</nav>
<main class="container py-4" style="max-width: 980px">
    @include('partials.flash')
    @yield('content')
</main>
<footer class="text-center text-muted small py-4">São Francisco — Planos de saúde veterinária</footer>
@stack('scripts')
</body>
</html>
