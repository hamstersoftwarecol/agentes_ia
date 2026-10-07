<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Contracts\AIProvider;
use App\Contracts\EmbeddingProvider;
use App\Models\Agent;
use App\Services\AI\AIManager;
use App\Services\Billing\BillingGate;
use App\Services\Billing\Stripe\StripeGateway;
use App\Services\Billing\Stripe\StripeSdkGateway;
use App\Support\CurrentOrganization;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una instancia por petición (y por job); nunca se comparte entre tenants.
        $this->app->scoped(CurrentOrganization::class);

        $this->app->bind(AnthropicClient::class, fn () => new AnthropicClient(
            apiKey: (string) config('ai.providers.anthropic.key'),
            // Peor caso ≈ 2 × 20 s: por debajo del tiempo de espera del widget (60 s).
            requestOptions: ['timeout' => 20, 'maxRetries' => 1],
        ));

        $this->app->scoped(BillingGate::class);
        $this->app->bind(StripeGateway::class, StripeSdkGateway::class);

        $this->app->singleton(AIManager::class);
        $this->app->bind(AIProvider::class, fn ($app) => $app->make(AIManager::class)->provider());
        $this->app->bind(EmbeddingProvider::class, fn ($app) => $app->make(AIManager::class)->embeddings());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        $this->registerWidget();

        // Probar el agente desde el panel gasta modelo: tope razonable por persona.
        RateLimiter::for('agent-test', fn (Request $request) => Limit::perMinute(20)->by('agent-test:'.$request->user()?->id));

        // Invitar (o reenviar) manda correo: tope por empresa y hora. Ver el enlace es público: tope por IP.
        // La clave sale del usuario, no de CurrentOrganization: el throttle corre antes que el middleware de empresa.
        RateLimiter::for('team-invitations', fn (Request $request) => Limit::perHour((int) config('team.invitations_per_hour'))
            ->by('team-invitations:'.($request->user()?->resolveCurrentOrganization()?->id ?? $request->ip())));
        RateLimiter::for('invitation-lookup', fn (Request $request) => Limit::perMinute(30)->by('invitation:'.$request->ip()));

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email=".urlencode($notifiable->getEmailForPasswordReset());
        });
    }

    /** Resolución del agente por su ID público y límites de uso del widget (endpoints sin autenticación). */
    private function registerWidget(): void
    {
        Route::pattern('widget_agent', 'ag_[A-Za-z0-9]{10}');

        // Sin usuario no hay empresa: el tenant sale del propio agente. Se fija aquí, durante el
        // binding, para que los demás parámetros de la ruta (la conversación) ya se resuelvan acotados.
        Route::bind('widget_agent', function (string $publicId) {
            $agent = Agent::withoutGlobalScopes()->with('organization')->where('public_id', $publicId)->firstOrFail();
            app(CurrentOrganization::class)->set($agent->organization);

            return $agent;
        });

        RateLimiter::for('widget-config', fn (Request $request) => Limit::perMinute(60)->by('cfg:'.$request->ip()));

        // El widget sondea cada pocos segundos mientras está abierto.
        RateLimiter::for('widget-poll', fn (Request $request) => [
            Limit::perMinute(40)->by('poll:'.($request->header('X-Visitor-Id') ?: $request->ip())),
            Limit::perMinute(300)->by('pollip:'.$request->ip()),
        ]);

        RateLimiter::for('widget-messages', fn (Request $request) => [
            Limit::perMinute(30)->by('ip:'.$request->ip().'|'.$request->route('widget_agent')),
            Limit::perMinute(8)->by('visitor:'.($request->header('X-Visitor-Id') ?: $request->ip())),
            Limit::perHour(200)->by('iph:'.$request->ip()),
        ]);
    }
}
