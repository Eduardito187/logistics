<?php

declare(strict_types=1);

use Logistics\Shipments\Application\Command\BuildShipmentItinerary\BuildShipmentItinerary;
use Logistics\Shipments\Application\Command\BuildShipmentItinerary\BuildShipmentItineraryHandler;
use Logistics\Shipments\Application\Contract\ItineraryRouter;
use Logistics\Shipments\Application\Contract\ShipmentRoutingFactsReader;
use Logistics\Shipments\Application\ReadModel\ShipmentRoutingFacts;
use Logistics\Shipments\Application\Service\OutsourcingDecision;
use Logistics\Shipments\Domain\Contract\ShipmentLegRepository;
use Logistics\Shipments\Domain\ValueObject\HaulageMode;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;

/**
 * Construcción del itinerario (BuildShipmentItinerary): decide propia vs. tercerizado por departamento
 * destino y PERSISTE los tramos. Usa un router y un lector de datos FALSOS (en memoria) y el repositorio de
 * tramos REAL (contra PostgreSQL, base logistics_test), para probar el mapeo, la decisión y la idempotencia
 * sin depender de la red sembrada.
 */
beforeEach(function (): void {
    $this->legs = app(ShipmentLegRepository::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Lector de datos de ruteo en memoria: devuelve los `facts` que se le pasan para el id consultado.
 */
function fakeFactsReader(ShipmentRoutingFacts $facts): ShipmentRoutingFactsReader
{
    return new class($facts) implements ShipmentRoutingFactsReader
    {
        public function __construct(private readonly ShipmentRoutingFacts $facts) {}

        public function factsFor(string $shipmentId): ?ShipmentRoutingFacts
        {
            return $shipmentId === $this->facts->shipmentId ? $this->facts : null;
        }
    };
}

/**
 * Router falso que devuelve una secuencia de tramos fija y recuerda con qué modo de acarreo lo llamaron.
 *
 * @param list<array{seq: int, from_node_id: string, to_node_id: string|null, from_department: string|null, to_department: string|null, haulage_mode: string, carrier_id: string|null}> $legs
 */
function fakeRouter(array $legs): ItineraryRouter
{
    return new class($legs) implements ItineraryRouter
    {
        public ?string $calledWithMode = null;

        /** @param list<array<string, mixed>> $legs */
        public function __construct(private readonly array $legs) {}

        public function planLegs(
            string $originNodeId,
            ?string $destinationNodeId,
            string $destinationDepartment,
            ?array $customerGeo,
            string $haulageMode,
            ?string $carrierId,
            ?string $carrierHubNodeId,
        ): array {
            $this->calledWithMode = $haulageMode;

            return $this->legs;
        }
    };
}

it('persists a single own-fleet leg for a same-department shipment', function (): void {
    $shipmentId = ShipmentId::generate();
    $origin = ShipmentId::generate()->toString();
    $destination = ShipmentId::generate()->toString();

    $facts = new ShipmentRoutingFacts($shipmentId->toString(), $origin, $destination, 'santa_cruz', 'santa_cruz', null, null);

    $router = fakeRouter([
        ['seq' => 1, 'from_node_id' => $origin, 'to_node_id' => $destination, 'from_department' => 'santa_cruz', 'to_department' => 'santa_cruz', 'haulage_mode' => 'own_fleet', 'carrier_id' => null],
    ]);

    $handler = new BuildShipmentItineraryHandler(fakeFactsReader($facts), new OutsourcingDecision([]), $router, $this->legs);
    $handler->handle(new BuildShipmentItinerary($shipmentId->toString()));

    $legs = $this->legs->findByShipmentId($shipmentId)->legs();

    expect($legs)->toHaveCount(1)
        ->and($legs[0]->seq())->toBe(1)
        ->and($legs[0]->haulageMode())->toBe(HaulageMode::OwnFleet)
        ->and($legs[0]->fromNodeId())->toBe($origin)
        ->and($legs[0]->toNodeId())->toBe($destination)
        ->and($router->calledWithMode)->toBe('own_fleet');
});

it('persists two legs for an interdepartmental own-fleet shipment (origin -> hub -> destination)', function (): void {
    $shipmentId = ShipmentId::generate();
    $origin = ShipmentId::generate()->toString();
    $hub = ShipmentId::generate()->toString();
    $destination = ShipmentId::generate()->toString();

    $facts = new ShipmentRoutingFacts($shipmentId->toString(), $origin, $destination, 'santa_cruz', 'tarija', null, null);

    $router = fakeRouter([
        ['seq' => 1, 'from_node_id' => $origin, 'to_node_id' => $hub, 'from_department' => 'santa_cruz', 'to_department' => 'tarija', 'haulage_mode' => 'own_fleet', 'carrier_id' => null],
        ['seq' => 2, 'from_node_id' => $hub, 'to_node_id' => $destination, 'from_department' => 'tarija', 'to_department' => 'tarija', 'haulage_mode' => 'own_fleet', 'carrier_id' => null],
    ]);

    $handler = new BuildShipmentItineraryHandler(fakeFactsReader($facts), new OutsourcingDecision([]), $router, $this->legs);
    $handler->handle(new BuildShipmentItinerary($shipmentId->toString()));

    $legs = $this->legs->findByShipmentId($shipmentId)->legs();

    expect($legs)->toHaveCount(2)
        ->and($legs[0]->seq())->toBe(1)
        ->and($legs[1]->seq())->toBe(2)
        ->and($legs[1]->toNodeId())->toBe($destination)
        ->and($router->calledWithMode)->toBe('own_fleet');
});

it('builds an outsourced itinerary (own-fleet handoff + third-party leg with carrier)', function (): void {
    $shipmentId = ShipmentId::generate();
    $origin = ShipmentId::generate()->toString();
    $carrierHub = ShipmentId::generate()->toString();
    $destination = ShipmentId::generate()->toString();
    $carrierId = ShipmentId::generate()->toString();

    $facts = new ShipmentRoutingFacts($shipmentId->toString(), $origin, $destination, 'santa_cruz', 'pando', null, null);

    $router = fakeRouter([
        ['seq' => 1, 'from_node_id' => $origin, 'to_node_id' => $carrierHub, 'from_department' => 'santa_cruz', 'to_department' => 'pando', 'haulage_mode' => 'own_fleet', 'carrier_id' => null],
        ['seq' => 2, 'from_node_id' => $carrierHub, 'to_node_id' => $destination, 'from_department' => 'pando', 'to_department' => 'pando', 'haulage_mode' => 'third_party', 'carrier_id' => $carrierId],
    ]);

    $decision = new OutsourcingDecision(['pando' => ['carrier_id' => $carrierId, 'carrier_hub_node_id' => $carrierHub]]);

    $handler = new BuildShipmentItineraryHandler(fakeFactsReader($facts), $decision, $router, $this->legs);
    $handler->handle(new BuildShipmentItinerary($shipmentId->toString()));

    $legs = $this->legs->findByShipmentId($shipmentId)->legs();

    expect($legs)->toHaveCount(2)
        ->and($router->calledWithMode)->toBe('outsourced')
        ->and($legs[0]->haulageMode())->toBe(HaulageMode::OwnFleet)
        ->and($legs[1]->haulageMode())->toBe(HaulageMode::ThirdParty)
        ->and($legs[1]->carrierId())->toBe($carrierId);
});

it('rebuilds the itinerary idempotently (re-running does not duplicate legs)', function (): void {
    $shipmentId = ShipmentId::generate();
    $origin = ShipmentId::generate()->toString();
    $destination = ShipmentId::generate()->toString();

    $facts = new ShipmentRoutingFacts($shipmentId->toString(), $origin, $destination, 'santa_cruz', 'santa_cruz', null, null);

    $router = fakeRouter([
        ['seq' => 1, 'from_node_id' => $origin, 'to_node_id' => $destination, 'from_department' => 'santa_cruz', 'to_department' => 'santa_cruz', 'haulage_mode' => 'own_fleet', 'carrier_id' => null],
    ]);

    $handler = new BuildShipmentItineraryHandler(fakeFactsReader($facts), new OutsourcingDecision([]), $router, $this->legs);

    $handler->handle(new BuildShipmentItinerary($shipmentId->toString()));
    $handler->handle(new BuildShipmentItinerary($shipmentId->toString()));

    expect($this->legs->findByShipmentId($shipmentId)->legs())->toHaveCount(1);
});

it('falls back to a single default leg when the shipment has no origin node', function (): void {
    $shipmentId = ShipmentId::generate();
    $destination = ShipmentId::generate()->toString();

    $facts = new ShipmentRoutingFacts($shipmentId->toString(), null, $destination, null, 'santa_cruz', null, null);

    // El router no debería ser invocado si falta el origen: devuelve 2 tramos para delatar una llamada indebida.
    $router = fakeRouter([
        ['seq' => 1, 'from_node_id' => 'x', 'to_node_id' => 'y', 'from_department' => null, 'to_department' => null, 'haulage_mode' => 'own_fleet', 'carrier_id' => null],
        ['seq' => 2, 'from_node_id' => 'y', 'to_node_id' => 'z', 'from_department' => null, 'to_department' => null, 'haulage_mode' => 'own_fleet', 'carrier_id' => null],
    ]);

    $handler = new BuildShipmentItineraryHandler(fakeFactsReader($facts), new OutsourcingDecision([]), $router, $this->legs);
    $handler->handle(new BuildShipmentItinerary($shipmentId->toString()));

    $legs = $this->legs->findByShipmentId($shipmentId)->legs();

    expect($legs)->toHaveCount(1)
        ->and($legs[0]->seq())->toBe(1)
        ->and($legs[0]->haulageMode())->toBe(HaulageMode::OwnFleet)
        ->and($legs[0]->toNodeId())->toBe($destination)
        ->and($router->calledWithMode)->toBeNull();
});

it('does nothing for an unknown shipment', function (): void {
    $known = ShipmentId::generate();
    $facts = new ShipmentRoutingFacts($known->toString(), null, null, null, null, null, null);
    $router = fakeRouter([]);

    $handler = new BuildShipmentItineraryHandler(fakeFactsReader($facts), new OutsourcingDecision([]), $router, $this->legs);

    $other = ShipmentId::generate();
    $handler->handle(new BuildShipmentItinerary($other->toString()));

    expect($this->legs->hasLegs($other))->toBeFalse();
});
