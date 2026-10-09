<?php

use App\Http\Middleware\EnsurePortalCustomer;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['portal' => EnsurePortalCustomer::class]);
        $middleware->append(SecurityHeaders::class);
        // Webhook tem autenticação própria (segredo na URL); demais rotas usam proteção CSRF.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
        $middleware->trustProxies(at: ['127.0.0.1']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Violação de regra de imutabilidade/integridade: mensagem clara, sem página de erro genérica.
        $exceptions->render(function (LogicException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['code' => 'CONFLICT', 'message' => $e->getMessage()], 409);
            }

            return back()->withInput()->withErrors(['regra' => $e->getMessage()]);
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if (in_array($e->getStatusCode(), [409, 503], true) && ! $request->expectsJson() && $request->isMethod('post')) {
                return back()->withInput()->withErrors(['operacao' => $e->getMessage()]);
            }

            return null;
        });

        // Nunca devolver à sessão senhas, códigos ou assinaturas.
        $exceptions->dontFlash(['password', 'password_confirmation', 'code', 'signature']);
    })->create();
