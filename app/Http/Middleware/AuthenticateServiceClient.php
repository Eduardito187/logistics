<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ServiceClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda de la puerta `/api/v1/integrations/*`: autentica por credenciales de servicio
 * (P1-AUTH-05).
 *
 * El `client_id` viaja en `X-Client-Id` y el `client_secret` en `X-Client-Secret`. Si
 * la ruta exige un scope (`->middleware('service:shipments')`) se verifica contra los
 * scopes del cliente. El cliente resuelto queda en los atributos del request.
 */
final class AuthenticateServiceClient
{
    public const ATTRIBUTE = 'service_client';

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $clientId = $request->header('X-Client-Id');
        $clientSecret = $request->header('X-Client-Secret');

        if (!is_string($clientId) || !is_string($clientSecret) || $clientId === '' || $clientSecret === '') {
            return $this->unauthorized('Service credentials are missing.');
        }

        $client = ServiceClient::query()
            ->where('client_id', $clientId)
            ->where('active', true)
            ->first();

        if ($client === null || !$client->verifySecret($clientSecret)) {
            return $this->unauthorized('Invalid service credentials.');
        }

        if ($scope !== null && !$client->hasScope($scope)) {
            return new JsonResponse(
                ['message' => 'Service client lacks the required scope: '.$scope],
                403,
            );
        }

        $client->markUsed();
        $request->attributes->set(self::ATTRIBUTE, $client);

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], 401);
    }
}
