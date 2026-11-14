<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\ApiChannel;
use App\Providers\AuthServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Guarda de la puerta `/api/v1/channels/*`: autentica por credenciales de canal
 * (P1-AUTH-04).
 *
 * La `key` viaja en el header `X-Channel-Key` y el `secret` en `X-Channel-Secret`. El
 * canal resuelto se guarda en los atributos del request (`api_channel`) para que el
 * rate limit por canal lo lea (ver {@see AuthServiceProvider}).
 */
final class AuthenticateChannel
{
    public const ATTRIBUTE = 'api_channel';

    /** Segundos que se recuerda una verificación correcta del secreto (ver {@see secretMatches()}). */
    private const VERIFIED_TTL_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Channel-Key');
        $secret = $request->header('X-Channel-Secret');

        if (!is_string($key) || !is_string($secret) || $key === '' || $secret === '') {
            return ProblemDetails::fromCode(
                ErrorCode::MissingCredentials,
                'Channel credentials are missing.',
            )->toResponse($request);
        }

        $channel = ApiChannel::query()->where('key', $key)->where('active', true)->first();

        // Comparación en tiempo constante aunque el canal no exista (evita filtrar
        // qué keys son válidas por diferencia de tiempos).
        if ($channel === null || !$this->secretMatches($channel, $secret)) {
            return ProblemDetails::fromCode(
                ErrorCode::InvalidCredentials,
                'Invalid channel credentials.',
            )->toResponse($request);
        }

        // `last_used_at` es informativo: se escribe como mucho una vez por minuto y por canal. Escribirlo en
        // cada request ponía un UPDATE en el camino caliente del tracking público y, con réplicas de lectura
        // (`sticky`), mandaba al primario el resto de las lecturas del request.
        if (Cache::add('channel:last-used:'.$channel->getKey(), true, 60)) {
            $channel->markUsed();
        }
        $request->attributes->set(self::ATTRIBUTE, $channel);

        return $next($request);
    }

    /**
     * Verifica el secreto del canal SIN pagar bcrypt en cada request.
     *
     * `Hash::check` (bcrypt) cuesta decenas de milisegundos de CPU a propósito; en la puerta de canales
     * (rastreo público, cotizaciones) eso era el grueso del costo del request. Una verificación correcta
     * se recuerda por {@see VERIFIED_TTL_SECONDS} bajo una huella HMAC de (key, secreto presentado, hash
     * guardado) firmada con la clave de la app.
     *
     * No hay atraso de revocación:
     *  - desactivar el canal corta en el acto, porque `active` se sigue leyendo de la base en cada request;
     *  - rotar el secreto cambia el hash guardado, así que la huella vieja deja de coincidir en el acto.
     * Solo se recuerdan los aciertos: un secreto incorrecto paga bcrypt cada vez (no abarata la fuerza
     * bruta). En el caché queda un booleano bajo una huella; nunca el secreto ni su hash.
     */
    private function secretMatches(ApiChannel $channel, string $secret): bool
    {
        $fingerprint = hash_hmac(
            'sha256',
            $channel->key."\0".$secret."\0".$channel->secret,
            (string) config('app.key'),
        );
        $cacheKey = 'channel:verified:'.$fingerprint;

        try {
            if (Cache::get($cacheKey) === true) {
                return true;
            }
        } catch (Throwable) {
            // Caché caído: se verifica con bcrypt como siempre.
        }

        if (!$channel->verifySecret($secret)) {
            return false;
        }

        try {
            Cache::put($cacheKey, true, self::VERIFIED_TTL_SECONDS);
        } catch (Throwable) {
            // Sin caché el próximo request vuelve a pagar bcrypt; no es un error del request.
        }

        return true;
    }
}
