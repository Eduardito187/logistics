<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\BuildShipmentItinerary;

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shipments\Application\Contract\ItineraryRouter;
use Logistics\Shipments\Application\Contract\ShipmentRoutingFactsReader;
use Logistics\Shipments\Application\ReadModel\ShipmentRoutingFacts;
use Logistics\Shipments\Application\Service\OutsourcingDecision;
use Logistics\Shipments\Domain\Contract\ShipmentLegRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\Model\ShipmentItinerary;
use Logistics\Shipments\Domain\Model\ShipmentLeg;
use Logistics\Shipments\Domain\ValueObject\HaulageMode;
use Logistics\Shipments\Domain\ValueObject\LegStatus;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;

/**
 * (Re)construye y PERSISTE el itinerario de un envío:
 *
 *  1. lee sus extremos (origen/destino, departamentos, coordenada) por el lado de lectura;
 *  2. decide propia vs. tercerizado por el departamento destino ({@see OutsourcingDecision});
 *  3. arma la secuencia de tramos con el {@see ItineraryRouter} (que delega en la red);
 *  4. mapea cada tramo primitivo a {@see ShipmentLeg} y guarda el {@see ShipmentItinerary}.
 *
 * IDEMPOTENTE: el guardado sincroniza por id, así que reconstruir REEMPLAZA los tramos sin duplicarlos.
 *
 * TOLERANTE: si el envío no existe no hace nada; si le falta el nodo de origen o el departamento destino
 * (no se puede rutear la red), cae a un ÚNICO tramo por defecto (origen → destino, flota propia), el mismo
 * cimiento que deja el backfill. Así un dato incompleto nunca deja al envío sin itinerario.
 *
 * @implements CommandHandler<BuildShipmentItinerary>
 */
final readonly class BuildShipmentItineraryHandler implements CommandHandler
{
    public function __construct(
        private ShipmentRoutingFactsReader $facts,
        private OutsourcingDecision $decision,
        private ItineraryRouter $router,
        private ShipmentLegRepository $legs,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof BuildShipmentItinerary) {
            throw ShipmentsException::because('BuildShipmentItineraryHandler solo maneja BuildShipmentItinerary');
        }

        $facts = $this->facts->factsFor($command->shipmentId);
        if ($facts === null) {
            return;
        }

        $shipmentId = ShipmentId::fromString($facts->shipmentId);
        $legs = $this->buildLegs($facts);

        $this->legs->save(ShipmentItinerary::fromLegs($shipmentId, $legs));
    }

    /**
     * Arma los tramos del envío. Con origen y departamento destino, rutea la red según la decisión de
     * acarreo; sin esos datos, cae al tramo único por defecto.
     *
     * @return list<ShipmentLeg>
     */
    private function buildLegs(ShipmentRoutingFacts $facts): array
    {
        $originNodeId = self::clean($facts->originNodeId);
        $destinationDepartment = self::clean($facts->destinationDepartment);

        if ($originNodeId === null || $destinationDepartment === null) {
            return [$this->defaultLeg($facts)];
        }

        $choice = $this->decision->decide($facts->destinationDepartment, $facts->originDepartment);

        $planned = $this->router->planLegs(
            $originNodeId,
            self::clean($facts->destinationNodeId),
            $destinationDepartment,
            $facts->customerGeo(),
            $choice->mode,
            $choice->carrierId,
            $choice->carrierHubNodeId,
        );

        if ($planned === []) {
            return [$this->defaultLeg($facts)];
        }

        $legs = [];
        foreach ($planned as $leg) {
            $legs[] = ShipmentLeg::create(
                $this->legs->nextLegIdentity(),
                $leg['seq'],
                $leg['from_node_id'],
                $leg['to_node_id'],
                $leg['from_department'],
                $leg['to_department'],
                HaulageMode::fromLoose($leg['haulage_mode']),
                $leg['carrier_id'],
                LegStatus::Pending,
            );
        }

        return $legs;
    }

    /**
     * Tramo único por defecto: del origen al destino final, flota propia, pendiente. Mismo cimiento que el
     * backfill, para que un envío con datos incompletos igual tenga itinerario.
     */
    private function defaultLeg(ShipmentRoutingFacts $facts): ShipmentLeg
    {
        return ShipmentLeg::create(
            $this->legs->nextLegIdentity(),
            1,
            $facts->originNodeId,
            $facts->destinationNodeId,
            $facts->originDepartment,
            $facts->destinationDepartment,
            HaulageMode::OwnFleet,
            null,
            LegStatus::Pending,
        );
    }

    private static function clean(?string $value): ?string
    {
        $trimmed = $value !== null ? trim($value) : '';

        return $trimmed === '' ? null : $trimmed;
    }
}
