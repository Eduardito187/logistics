<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Middleware\AuthenticateIntegrationToken;

/**
 * Permite a un integrador validar su propia credencial: devuelve el id de su token y los permisos que
 * concede. La autenticación la hace el middleware `access.token`, que deja esos datos en los atributos
 * de la request; este controller solo los proyecta. Vive en la puerta `integrations`.
 */
final class VerifyIntegrationTokenController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tokenId = $request->attributes->get(AuthenticateIntegrationToken::TOKEN_ID_ATTRIBUTE);
        $permissions = $request->attributes->get(AuthenticateIntegrationToken::PERMISSIONS_ATTRIBUTE);

        // Si faltan los atributos, el middleware no autenticó: no debería ocurrir bajo `access.token`.
        if (!is_string($tokenId) || !is_array($permissions)) {
            return $this->error('unauthenticated', 'No authenticated integration token on the request.', 401);
        }

        /** @var list<string> $permissions */
        return $this->data([
            'token_id' => $tokenId,
            'permission_keys' => array_values($permissions),
        ]);
    }
}
