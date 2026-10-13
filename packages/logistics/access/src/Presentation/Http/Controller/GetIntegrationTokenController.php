<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Query\GetIntegrationToken\GetIntegrationToken;
use Logistics\Access\Application\ReadModel\IntegrationTokenView;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve un token de integración por su id (consulta GetIntegrationToken). Responde 404 si no existe.
 */
final class GetIntegrationTokenController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $tokenId): JsonResponse
    {
        return $this->guard(function () use ($tokenId): JsonResponse {
            /** @var IntegrationTokenView|null $token */
            $token = $this->queries->ask(new GetIntegrationToken(tokenId: $tokenId));

            if ($token === null) {
                return $this->error('integration_token_not_found', 'No integration token found for the given identifier.', 404);
            }

            return $this->data($token->toArray());
        });
    }
}
