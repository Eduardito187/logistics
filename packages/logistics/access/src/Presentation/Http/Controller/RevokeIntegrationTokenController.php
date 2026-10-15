<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\RevokeIntegrationToken\RevokeIntegrationToken;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Revoca un token de integración para que deje de autenticar (comando RevokeIntegrationToken).
 * El id llega por la ruta.
 */
final class RevokeIntegrationTokenController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $tokenId): JsonResponse
    {
        return $this->guard(function () use ($tokenId): JsonResponse {
            $this->commands->dispatch(new RevokeIntegrationToken(tokenId: $tokenId));

            return $this->accepted(['token_id' => $tokenId]);
        });
    }
}
