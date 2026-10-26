<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Query\GetPublicUnits\GetPublicUnits;
use Logistics\Tracking\Application\ReadModel\PublicUnitView;
use Logistics\Tracking\Presentation\Http\ApiResponder;

/**
 * Detalle PÚBLICO de productos por token (puerta /api/v1/channels/*).
 *
 * La consume la app de clientes/web: muestra el desglose de productos del envío ("ropero 1 de 2:
 * cargado") sin PII. 404 genérico si el token no corresponde a ningún seguimiento (no revela si el
 * token existe), igual que la página pública del envío.
 *
 * Presentation habla con Application vía el QueryBus; nunca toca Infrastructure ni el dominio.
 */
final class PublicUnitsController
{
    use ApiResponder;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(string $token): JsonResponse
    {
        $units = $this->queryBus->ask(new GetPublicUnits($token));

        if ($units === null) {
            return $this->error('tracking_not_found', 'No tracking matches this token.', 404);
        }

        return $this->data([
            'units' => array_map(static fn (PublicUnitView $unit): array => $unit->toArray(), $units),
        ]);
    }
}
