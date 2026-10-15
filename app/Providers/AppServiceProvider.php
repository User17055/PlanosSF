<?php

namespace App\Providers;

use App\Services\Access\FranchiseContext;
use App\Services\Access\PermissionCatalog;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\FakeBillingGateway;
use App\Services\Billing\VindiBillingGateway;
use App\Services\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(FranchiseContext::class);
        $this->app->scoped(AuditLogger::class);
        $this->app->scoped(Settings::class);

        // Fora de homologação, simulador ou sandbox deixariam o cliente "pagar" sem cobrança real.
        $this->app->bind(BillingGateway::class, function ($app) {
            $driver = config('planos.billing.driver');
            $homologation = (bool) config('planos.is_homologation');

            if ($driver === 'fake' && ! $homologation) {
                throw new \RuntimeException('PLANOS_BILLING_DRIVER=fake só é aceito com PLANOS_HOMOLOGATION=true.');
            }
            if ($driver === 'vindi' && ! $homologation && str_contains((string) config('planos.billing.vindi.base_url'), 'sandbox')) {
                throw new \RuntimeException('Fora de homologação a Vindi não pode apontar para o sandbox: configure VINDI_BASE_URL.');
            }

            return match ($driver) {
                'fake' => $app->make(FakeBillingGateway::class),
                'vindi' => $app->make(VindiBillingGateway::class),
                default => throw new \RuntimeException("PLANOS_BILLING_DRIVER desconhecido: {$driver}."),
            };
        });
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Paginator::useBootstrapFive();

        Password::defaults(fn () => Password::min(10)->letters()->numbers()->uncompromised(0));

        // Permissões verificadas no servidor contra a unidade de atuação (RF-12 / seção 27.1).
        $permissions = array_keys(PermissionCatalog::permissions());
        Gate::before(function ($user, string $ability) use ($permissions) {
            if (in_array($ability, $permissions, true)) {
                return $this->app->make(FranchiseContext::class)->allows($ability);
            }

            return null;
        });

        Blade::directive('money', fn ($e) => "<?php echo e(\\App\\Support\\Money::format($e)); ?>");
        Blade::directive('date', fn ($e) => "<?php echo e(\\App\\Support\\BusinessTime::formatDate($e)); ?>");
        Blade::directive('datetime', fn ($e) => "<?php echo e(\\App\\Support\\BusinessTime::formatDateTime($e)); ?>");

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(20)->by($r->ip()),
        ]);
        RateLimiter::for('otp', fn (Request $r) => Limit::perHour(config('planos.otp.max_per_hour'))->by($r->ip()));
        RateLimiter::for('public-forms', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('document-lookup', fn (Request $r) => Limit::perMinute(20)->by(($r->user()?->id ?? 'guest').'|'.$r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));
    }
}
