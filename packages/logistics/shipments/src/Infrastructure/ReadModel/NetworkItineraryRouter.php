<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Logistics\Network\Application\Contract\ItineraryPlanner;
use Logistics\Shipments\Application\Contract\ItineraryRouter;

/**
 * Adaptador del {@see ItineraryRouter} de shipments que DELEGA en el {@see ItineraryPlanner} del contexto
 * `network` (quien conoce la red de nodos, los hubs por departamento y los almacenes de transportadora).
 *
 * El acople queda confinado a esta clase de Infrastructure: la aplicación de shipments habla solo con su
 * propio contrato. El planificador de la red ya devuelve el shape de legs con `haulage_mode`
 * 'own_fleet'|'third_party', así que acá no hace falta traducir nada.
 */
final readonly class NetworkItineraryRouter implements ItineraryRouter
{
    public function __construct(
        private ItineraryPlanner $planner,
    ) {}

    public function planLegs(
        string $originNodeId,
        ?string $destinationNodeId,
        string $destinationDepartment,
        ?array $customerGeo,
        string $haulageMode,
        ?string $carrierId,
        ?string $carrierHubNodeId,
    ): array {
        return $this->planner->planLegs(
            $originNodeId,
            $destinationNodeId,
            $destinationDepartment,
            $customerGeo,
            $haulageMode,
            $carrierId,
            $carrierHubNodeId,
        );
    }
}
