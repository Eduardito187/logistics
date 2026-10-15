<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Ability;
use App\Http\Middleware\AuthenticateChannel;
use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\EnsureAbility;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\IdempotentRequests;
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
        $this->registerApiThrottling();
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
        $router->aliasMiddleware('idempotency', IdempotentRequests::class);
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
     * Rate limit por puerta (S-12), aplicado a TODO el grupo `api` sin tocar routes/api.php.
     *
     * El limiter `api` se empuja al grupo de rutas `api` desde aquí (pushMiddlewareToGroup), así que
     * cubre las tres puertas con un tope grueso anti-abuso. La clave se calcula leyendo la credencial
     * cruda del request (bearer / X-Client-Id), que ya viaja antes de que corra la autenticación de
     * cada puerta, con reserva por IP:
     *
     *   - puerta app          → por token Sanctum (120/min),
     *   - puerta integraciones → por client_id de servicio (300/min, cargas por lote),
     *   - puerta canales       → sin tope aquí: ya la limita el limiter `channels` por canal resuelto,
     *   - resto (health/login) → por IP (60/min); el login además tiene su propio throttle:10,1.
     */
    private function registerApiThrottling(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $ip = (string) $request->ip();

            if ($request->is('api/v1/app/*')) {
                $bearer = $request->bearerToken();

                return Limit::perMinute(120)->by(
                    $bearer !== null ? 'api-app:'.sha1($bearer) : 'api-app-ip:'.$ip,
                );
            }

            if ($request->is('api/v1/integrations/*')) {
                $clientId = $request->header('X-Client-Id');

                return Limit::perMinute(300)->by(
                    is_string($clientId) && $clientId !== '' ? 'api-svc:'.$clientId : 'api-svc-ip:'.$ip,
                );
            }

            // La puerta de canales ya está limitada por canal (limiter `channels`): no la doblamos.
            if ($request->is('api/v1/channels/*')) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by('api-ip:'.$ip);
        });

        // Empuja el limiter al grupo `api` sin reescribir las rutas: el grupo lo arma el framework
        // (ver bootstrap/app.php → withRouting(api: ...)), y aquí solo le agregamos el throttle.
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('api', 'throttle:api');
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
