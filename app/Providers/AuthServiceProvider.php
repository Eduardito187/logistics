<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Ability;
use App\Http\Middleware\AuthenticateChannel;
use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\EnsureAbility;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\EnsureRole;
use App\Models\ApiChannel;
use App\Models\PersonalAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Cableado de autenticación y autorización de las tres puertas de la API.
 *
 * - Usa el modelo de token extendido (con `device_id`).
 * - Registra los alias de middleware por puerta.
 * - Define el rate limit por canal (`/api/v1/channels/*`).
 * - Expone una gate por cada habilidad para policies/Filament.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Token Sanctum atado a dispositivo.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->registerMiddlewareAliases();
        $this->registerRateLimiters();
        $this->registerGates();
    }

    /**
     * Alias de middleware para que el integrador los componga en cada puerta.
     */
    private function registerMiddlewareAliases(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('device', EnsureDeviceToken::class);
        $router->aliasMiddleware('role', EnsureRole::class);
        $router->aliasMiddleware('require.ability', EnsureAbility::class);
        $router->aliasMiddleware('channel.auth', AuthenticateChannel::class);
        $router->aliasMiddleware('service.auth', AuthenticateServiceClient::class);
    }

    /**
     * Rate limit por canal: cada canal trae su propio tope por minuto. Si aún no se
     * resolvió el canal, se limita por IP de forma conservadora.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('channels', function (Request $request): Limit {
            $channel = $request->attributes->get(AuthenticateChannel::ATTRIBUTE);

            if ($channel instanceof ApiChannel) {
                return Limit::perMinute($channel->rate_limit_per_minute)
                    ->by('channel:'.$channel->getKey());
            }

            return Limit::perMinute(30)->by('channel-ip:'.(string) $request->ip());
        });
    }

    /**
     * Una gate por habilidad, delegando en `tokenCan()` del token vigente. Permite usar
     * `$user->can('execution:write')` en policies y en el admin.
     */
    private function registerGates(): void
    {
        foreach (Ability::all() as $ability) {
            Gate::define($ability, static function ($user) use ($ability): bool {
                return is_object($user) && method_exists($user, 'tokenCan') && $user->tokenCan($ability);
            });
        }
    }
}
