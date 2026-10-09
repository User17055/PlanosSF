<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Painel') · PlanosSF</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
@if (config('planos.is_homologation'))
    <div class="sf-homolog-banner">AMBIENTE DE HOMOLOGAÇÃO — dados fictícios, cobranças simuladas</div>
@endif
@php($ctx = app(\App\Services\Access\FranchiseContext::class))
<nav class="navbar navbar-expand-lg navbar-dark sf-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="{{ route('dashboard') }}"><i class="bi bi-heart-pulse"></i> Planos SF</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                @can('customers.view')<li class="nav-item"><a class="nav-link @if(request()->routeIs('customers.*')) active @endif" href="{{ route('customers.index') }}">Clientes</a></li>@endcan
                @can('proposals.view')
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle @if(request()->routeIs('proposals.*','documents.*')) active @endif" href="#" data-bs-toggle="dropdown">Contratação</a>
                        <ul class="dropdown-menu">
                            @can('proposals.create')<li><a class="dropdown-item" href="{{ route('proposals.create') }}">Nova contratação</a></li>@endcan
                            <li><a class="dropdown-item" href="{{ route('proposals.index') }}">Propostas e links</a></li>
                            @can('documents.validate')<li><a class="dropdown-item" href="{{ route('documents.index') }}">Conferência de documentos</a></li>@endcan
                        </ul>
                    </li>
                @endcan
                @can('subscriptions.view')<li class="nav-item"><a class="nav-link @if(request()->routeIs('subscriptions.*')) active @endif" href="{{ route('subscriptions.index') }}">Assinaturas</a></li>@endcan
                @can('benefits.check')<li class="nav-item"><a class="nav-link @if(request()->routeIs('benefits.*')) active @endif" href="{{ route('benefits.index') }}">Autorização</a></li>@endcan
                @can('financial.view')<li class="nav-item"><a class="nav-link @if(request()->routeIs('financial.*')) active @endif" href="{{ route('financial.index') }}">Financeiro</a></li>@endcan
                @can('plans.view')<li class="nav-item"><a class="nav-link @if(request()->routeIs('plans.*','templates.*')) active @endif" href="{{ route('plans.index') }}">Catálogo</a></li>@endcan
                @can('integrations.view')<li class="nav-item"><a class="nav-link @if(request()->routeIs('integrations.*')) active @endif" href="{{ route('integrations.index') }}">Integrações</a></li>@endcan
                @canany(['reports.view','audit.view','settings.manage','users.manage','franchises.manage'])
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Gestão</a>
                        <ul class="dropdown-menu">
                            @can('reports.view')<li><a class="dropdown-item" href="{{ route('reports.index') }}">Relatórios</a></li>@endcan
                            @can('audit.view')<li><a class="dropdown-item" href="{{ route('audit.index') }}">Auditoria</a></li>@endcan
                            @can('users.manage')<li><a class="dropdown-item" href="{{ route('users.index') }}">Usuários</a></li>@endcan
                            @can('franchises.manage')<li><a class="dropdown-item" href="{{ route('franchises.index') }}">Franquias</a></li>@endcan
                            @can('settings.manage')<li><a class="dropdown-item" href="{{ route('settings.index') }}">Configurações e decisões</a></li>@endcan
                        </ul>
                    </li>
                @endcanany
            </ul>
            <ul class="navbar-nav">
                @if ($ctx->has())
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-shop"></i> {{ $ctx->franchise()->name }} · {{ auth()->user()->name }}
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('franchise.select') }}">Trocar unidade</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="{{ route('logout') }}">@csrf<button class="dropdown-item">Sair</button></form>
                            </li>
                        </ul>
                    </li>
                @endif
            </ul>
        </div>
    </div>
</nav>

<main class="container-fluid py-4 px-lg-4">
    @include('partials.flash')
    @yield('content')
</main>
@stack('scripts')
</body>
</html>
