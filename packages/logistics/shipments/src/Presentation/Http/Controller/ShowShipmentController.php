<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\CheckShipmentScope\CheckShipmentScope;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentScopeDecision;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;

/**
 * Puerta app: detalle de un pedido recibido por su id.
 *
 * Lee vía QueryBus (FindShipment → ?ShipmentView). Devuelve 404 si no existe.
 *
 * Alcance por rol (server-side): es la ficha de OPERACIÓN del envío, así que un envío fuera del alcance del
 * usuario responde 403 con su `code` (`out_of_scope_city` / `out_of_scope_node` / `out_of_scope_assignment`).
 * Para solo consultar cualquier pedido está GET /orders/lookup, que es abierto.
 */
final class ShowShipmentController
{
    use JsonEnvelope;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request, string $shipment): JsonResponse
    {
        /** @var ShipmentView|null $view */
        $view = $this->queryBus->ask(new FindShipment($shipment, withOrigin: true));

        if ($view === null) {
            return $this->error("Shipment {$shipment} not found", 404);
        }

        /** @var ShipmentScopeDecision $decision */
        $decision = $this->queryBus->ask(new CheckShipmentScope(ScopeActorResolver::fromUser($request->user()), shipmentIds: [$view->id]));
        if (!$decision->allowed) {
            return $this->error((string) $decision->message, 403, $decision->code);
        }

        return $this->data($view->toArray());
    }
}
