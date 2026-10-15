<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Access\Application\Command\RecordTokenUsage\RecordTokenUsage;
use Logistics\Access\Application\Query\AuthenticateIntegrationToken\AuthenticateIntegrationToken as AuthenticateQuery;
use Logistics\Access\Application\ReadModel\TokenAuthResultView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Autentica una petición de integración con un token de la plataforma (Authorization: Bearer <token>).
 *
 * Resuelve el token vía la query CQRS, deja sus permisos efectivos en los atributos de la request para
 * que {@see EnsurePermission} los exija por ruta, y registra el uso (last_used_at) de forma asíncrona
 * al dominio (comando → outbox). Nunca toca Infrastructure ni Domain directo: solo los buses.
 *
 * Es un carril paralelo al `service.auth` heredado (ServiceClient): no lo reemplaza. Se activa con el
 * alias `access.token` en las rutas que quieran autorizar por permisos dinámicos.
 */
final readonly class AuthenticateIntegrationToken
{
    /** Clave del atributo de request donde quedan los permisos efectivos del token. */
    public const PERMISSIONS_ATTRIBUTE = 'access.token.permissions';

    /** Clave del atributo de request donde queda el id del token autenticado. */
    public const TOKEN_ID_ATTRIBUTE = 'access.token.id';

    public function __construct(
        private QueryBus $queries,
        private CommandBus $commands,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return $this->unauthorized('Falta el token de integración.');
        }

        $result = $this->queries->ask(new AuthenticateQuery($token));

        if (!$result instanceof TokenAuthResultView) {
            return $this->unauthorized('Token de integración inválido, revocado o expirado.');
        }

        $request->attributes->set(self::PERMISSIONS_ATTRIBUTE, $result->permissionKeys);
        $request->attributes->set(self::TOKEN_ID_ATTRIBUTE, $result->tokenId);

        // Registrar el uso no debe tumbar la petición si algo falla: es telemetría, no autorización.
        try {
            $this->commands->dispatch(new RecordTokenUsage($result->tokenId));
        } catch (Throwable) {
            // Silencioso a propósito: el uso se perderá, pero la petición autenticada sigue.
        }

        return $next($request);
    }

    /**
     * Extrae el token del header Authorization (Bearer) o del header X-Integration-Token.
     */
    private function extractToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $header = $request->header('X-Integration-Token');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return null;
    }

    private function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => 'unauthenticated', 'message' => $message],
        ], 401);
    }
}
