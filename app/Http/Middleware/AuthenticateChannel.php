<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\ApiChannel;
use App\Providers\AuthServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        if ($channel === null || !$channel->verifySecret($secret)) {
            return ProblemDetails::fromCode(
                ErrorCode::InvalidCredentials,
                'Invalid channel credentials.',
            )->toResponse($request);
        }

        $channel->markUsed();
        $request->attributes->set(self::ATTRIBUTE, $channel);

        return $next($request);
    }
}
