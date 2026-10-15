<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function __invoke(Request $request): JsonResponse
    {
        /** @var list<ShipmentView> $views */
        $views = $this->queryBus->ask(new ListLowConfidenceAddresses);

        $items = array_map(static fn (ShipmentView $view): array => $view->toArray(), $views);

        // Paginación opcional y aditiva (E-02): solo si el cliente la pide; si no, colección completa.
        if (ApiPagination::requested($request)) {
            return new JsonResponse(ApiPagination::paginate($items, $request));
        }

        return $this->data($items);
    }
}
