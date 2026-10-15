<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Service\IntegrationTokenIssuer;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Access\Presentation\Http\ApiController;

/**
 * Rota el secreto de un token de integración activo vía el servicio IntegrationTokenIssuer y devuelve el
 * secreto nuevo en claro UNA sola vez (201). El id llega por la ruta.
 */
final class RotateIntegrationTokenController extends ApiController
{
    public function __construct(
        private readonly IntegrationTokenIssuer $issuer,
    ) {}

    public function __invoke(string $tokenId): JsonResponse
    {
        return $this->guard(function () use ($tokenId): JsonResponse {
            $issued = $this->issuer->rotate(IntegrationTokenId::fromString($tokenId));

            // 201 con el token en claro nuevo: única oportunidad de leerlo.
            return $this->data($issued->toArray(), 201);
        });
    }
}
