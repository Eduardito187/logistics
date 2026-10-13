<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\ListLowConfidenceAddresses\ListLowConfidenceAddresses;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;

/**
 * Puerta app: pedidos cuya dirección quedó con confianza baja, candidatos a confirmar con el cliente
 * antes de salir a ruta. Lee vía QueryBus (ListLowConfidenceAddresses → list<ShipmentView>).
 */
final class ListLowConfidenceAddressesController
{
    use JsonEnvelope;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(): JsonResponse
    {
        /** @var list<ShipmentView> $views */
        $views = $this->queryBus->ask(new ListLowConfidenceAddresses);

        return $this->data(array_map(static fn (ShipmentView $view): array => $view->toArray(), $views));
    }
}
