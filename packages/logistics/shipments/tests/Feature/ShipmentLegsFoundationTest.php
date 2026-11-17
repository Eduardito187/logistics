<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Logistics\Shipments\Application\Contract\ShipmentLegReader;
use Logistics\Shipments\Domain\Contract\ShipmentLegRepository;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentItinerary;
use Logistics\Shipments\Domain\Model\ShipmentLeg;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\HaulageMode;
use Logistics\Shipments\Domain\ValueObject\LegStatus;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Cimiento del modelo de tramos (hub-and-spoke): la migración es idempotente, el repositorio guarda y
 * relee el itinerario de un envío, y el backfill crea UN tramo inicial sin duplicar al repetirlo. Contra
 * PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->legs = app(ShipmentLegRepository::class);
    $this->shipments = app(ShipmentRepository::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Crea y guarda un envío mínimo, y devuelve su id. El origen/destino alimentan el tramo del backfill.
 */
function persistShipmentForLegs(ShipmentRepository $repository, string $externalId, ShipmentId $originNode, ShipmentId $destinationNode): ShipmentId
{
    $shipment = Shipment::receive(
        $repository->nextIdentity(),
        OrderSource::Sap,
        SourceReference::of(OrderSource::Sap, $externalId),
        Customer::of('Juana Perez'),
        Address::of('Av. Siempre Viva 742'),
        AddressConfidence::of(80),
        [ShipmentLine::create(ShipmentLineId::generate(), Sku::of('LG-GT32'), ProductName::of('Heladera LG'), 1)],
        $destinationNode->toString(),
        null,
        null,
        $originNode->toString(),
    );

    $repository->save($shipment);

    return $shipment->id();
}

it('creates the shipment_legs table idempotently (running the migration twice does not fail)', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_17_000100_create_shipment_legs_table.php';

    // La tabla ya existe (migrada por la suite). Correr up() de nuevo no debe fallar ni duplicar índices.
    $migration->up();
    $migration->up();

    expect(Schema::hasTable('shipment_legs'))->toBeTrue()
        ->and(Schema::hasColumn('shipment_legs', 'haulage_mode'))->toBeTrue()
        ->and(Schema::hasColumn('shipment_legs', 'departed_at'))->toBeTrue()
        ->and(Schema::hasIndex('shipment_legs', 'shipment_legs_shipment_id_seq_unique'))->toBeTrue()
        ->and(Schema::hasIndex('shipment_legs', 'shipment_legs_to_node_id_index'))->toBeTrue();
});

it('saves and reloads the itinerary of a shipment (legs ordered by seq)', function (): void {
    $shipmentId = ShipmentId::generate();
    $hub = ShipmentId::generate();
    $origin = ShipmentId::generate();
    $destination = ShipmentId::generate();

    $itinerary = ShipmentItinerary::fromLegs($shipmentId, [
        ShipmentLeg::create($this->legs->nextLegIdentity(), 2, $hub->toString(), $destination->toString(), 'tarija', 'tarija', HaulageMode::ThirdParty, ShipmentId::generate()->toString(), LegStatus::Pending),
        ShipmentLeg::create($this->legs->nextLegIdentity(), 1, $origin->toString(), $hub->toString(), 'santa_cruz', 'tarija', HaulageMode::OwnFleet, null, LegStatus::InTransit),
    ]);

    $this->legs->save($itinerary);

    expect($this->legs->hasLegs($shipmentId))->toBeTrue();

    $reloaded = $this->legs->findByShipmentId($shipmentId);
    $legs = $reloaded->legs();

    expect($legs)->toHaveCount(2)
        ->and($legs[0]->seq())->toBe(1)
        ->and($legs[0]->haulageMode())->toBe(HaulageMode::OwnFleet)
        ->and($legs[0]->status())->toBe(LegStatus::InTransit)
        ->and($legs[0]->fromNodeId())->toBe($origin->toString())
        ->and($legs[1]->seq())->toBe(2)
        ->and($legs[1]->haulageMode())->toBe(HaulageMode::ThirdParty)
        ->and($legs[1]->carrierId())->not->toBeNull();
});

it('reads legs through the read model, ordered by seq', function (): void {
    $shipmentId = ShipmentId::generate();
    $itinerary = ShipmentItinerary::fromLegs($shipmentId, [
        ShipmentLeg::create($this->legs->nextLegIdentity(), 1, null, null, 'santa_cruz', 'tarija'),
    ]);
    $this->legs->save($itinerary);

    $views = app(ShipmentLegReader::class)->forShipment($shipmentId->toString());

    expect($views)->toHaveCount(1)
        ->and($views[0]->seq)->toBe(1)
        ->and($views[0]->haulageMode)->toBe('own_fleet')
        ->and($views[0]->status)->toBe('pending')
        ->and($views[0]->toDepartment)->toBe('tarija');
});

it('returns an empty itinerary for a shipment without legs', function (): void {
    $itinerary = $this->legs->findByShipmentId(ShipmentId::generate());

    expect($itinerary->isEmpty())->toBeTrue();
});

it('backfills one initial leg per legless shipment and is idempotent on re-run', function (): void {
    $origin = ShipmentId::generate();
    $destination = ShipmentId::generate();
    $shipmentId = persistShipmentForLegs($this->shipments, 'DM-LEGS-1', $origin, $destination);

    expect($this->legs->hasLegs($shipmentId))->toBeFalse();

    $this->artisan('logistics:shipments:backfill-legs', ['--force' => true])->assertSuccessful();
    $this->artisan('logistics:shipments:backfill-legs', ['--force' => true])->assertSuccessful();

    $legs = $this->legs->findByShipmentId($shipmentId)->legs();

    expect($legs)->toHaveCount(1)
        ->and($legs[0]->seq())->toBe(1)
        ->and($legs[0]->haulageMode())->toBe(HaulageMode::OwnFleet)
        ->and($legs[0]->status())->toBe(LegStatus::Pending)
        ->and($legs[0]->fromNodeId())->toBe($origin->toString())
        ->and($legs[0]->toNodeId())->toBe($destination->toString());
});

it('does not apply changes in dry-run mode', function (): void {
    $shipmentId = persistShipmentForLegs($this->shipments, 'DM-LEGS-DRY', ShipmentId::generate(), ShipmentId::generate());

    $this->artisan('logistics:shipments:backfill-legs')->assertSuccessful();

    expect($this->legs->hasLegs($shipmentId))->toBeFalse();
});
