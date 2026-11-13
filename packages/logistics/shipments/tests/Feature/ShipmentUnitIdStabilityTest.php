<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\DeliverySlot;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Estabilidad de ids al reguardar (regresión del bug de staging): guardar un envío que ya existe NO debe
 * cambiar el id de sus bultos ni de sus líneas. Antes se borraba e insertaba todo en cada save(), lo que
 * recreaba las filas y orfanaba los escaneos/etiquetas que referenciaban el id del bulto. Contra
 * PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->repository = app(ShipmentRepository::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Arma y guarda un envío recibido con una línea de dos unidades (dos bultos) y lo devuelve persistido.
 */
function persistShipmentForIdStability(ShipmentRepository $repository, string $externalId): Shipment
{
    $shipment = Shipment::receive(
        $repository->nextIdentity(),
        OrderSource::Sap,
        SourceReference::of(OrderSource::Sap, $externalId),
        Customer::of('Juana Perez'),
        Address::of('Av. Siempre Viva 742'),
        AddressConfidence::of(80),
        [ShipmentLine::create(ShipmentLineId::generate(), Sku::of('LG-GT32'), ProductName::of('Heladera LG'), 2)],
    );

    $repository->save($shipment);

    return $shipment;
}

/**
 * Lee los ids de los bultos de un envío ya persistido, ordenados para comparar sin depender del orden.
 *
 * @return list<string>
 */
function unitIdsOf(?Shipment $shipment): array
{
    $ids = array_map(
        static fn ($unit): string => $unit->id()->toString(),
        $shipment?->physicalUnits() ?? [],
    );
    sort($ids);

    return $ids;
}

it('keeps unit ids stable when the shipment is rescheduled and saved again', function (): void {
    $saved = persistShipmentForIdStability($this->repository, 'DM-IDSTAB-1');
    $originalUnitIds = unitIdsOf($saved);

    expect($originalUnitIds)->toHaveCount(2);

    // Reprogramar cambia la franja comprometida y vuelve a guardar el envío entero.
    $reloaded = $this->repository->findById($saved->id());
    $reloaded->reschedule(DeliverySlot::of('2026-11-20', '09:00', '12:00'), 'cliente pidió otra fecha');
    $this->repository->save($reloaded);

    $afterReschedule = $this->repository->findById($saved->id());

    expect(unitIdsOf($afterReschedule))->toBe($originalUnitIds)
        ->and($afterReschedule->physicalUnits())->toHaveCount(2)
        ->and($afterReschedule->deliverySlot())->not->toBeNull();
});

it('keeps unit ids stable when the shipment status advances and is saved again', function (): void {
    $saved = persistShipmentForIdStability($this->repository, 'DM-IDSTAB-2');
    $originalUnitIds = unitIdsOf($saved);

    // Avanzar el estado (p. ej. por asignación a un camión) vuelve a guardar el envío.
    $reloaded = $this->repository->findById($saved->id());
    $reloaded->progressTo(ShipmentStatus::InExecution);
    $this->repository->save($reloaded);

    $afterAdvance = $this->repository->findById($saved->id());

    expect(unitIdsOf($afterAdvance))->toBe($originalUnitIds)
        ->and($afterAdvance->status())->toBe(ShipmentStatus::InExecution)
        ->and($afterAdvance->physicalUnits())->toHaveCount(2);
});

it('persists exactly the aggregate units: adds new ones and keeps existing ids', function (): void {
    $saved = persistShipmentForIdStability($this->repository, 'DM-IDSTAB-3');
    $originalUnitIds = unitIdsOf($saved);

    // Agregar una línea genera bultos nuevos; los que ya existían deben conservar su id.
    $reloaded = $this->repository->findById($saved->id());
    $reloaded->addLine(ShipmentLine::create(ShipmentLineId::generate(), Sku::of('SNY-TV55'), ProductName::of('Televisor Sony'), 1));
    $this->repository->save($reloaded);

    $afterAddLine = $this->repository->findById($saved->id());
    $afterUnitIds = unitIdsOf($afterAddLine);

    expect($afterAddLine->physicalUnits())->toHaveCount(3);
    foreach ($originalUnitIds as $originalId) {
        expect($afterUnitIds)->toContain($originalId);
    }
});

it('removes units dropped from the aggregate while keeping the survivors ids', function (): void {
    $saved = persistShipmentForIdStability($this->repository, 'DM-IDSTAB-4');

    // Reconstituir el envío con un solo bulto (se "quitó" uno del agregado) y guardarlo: la base debe
    // reflejar exactamente ese bulto, sin cambiar su id.
    $survivor = $saved->physicalUnits()[0];
    $trimmed = Shipment::reconstitute(
        $saved->id(),
        $saved->source(),
        $saved->sourceReference(),
        $saved->customer(),
        $saved->address(),
        $saved->addressConfidence(),
        $saved->status(),
        $saved->lines(),
        [$survivor],
    );
    $this->repository->save($trimmed);

    $reloaded = $this->repository->findById($saved->id());

    expect($reloaded->physicalUnits())->toHaveCount(1)
        ->and($reloaded->physicalUnits()[0]->id()->toString())->toBe($survivor->id()->toString());
});

it('creates missing shipments and units on a plain save', function (): void {
    $id = ShipmentId::generate();
    $shipment = Shipment::reconstitute(
        $id,
        OrderSource::Sap,
        SourceReference::of(OrderSource::Sap, 'DM-IDSTAB-5'),
        Customer::of('Juana Perez'),
        Address::of('Av. Siempre Viva 742'),
        AddressConfidence::of(80),
        ShipmentStatus::Received,
        [ShipmentLine::create(ShipmentLineId::generate(), Sku::of('LG-GT32'), ProductName::of('Heladera LG'), 1)],
        [],
    );

    $this->repository->save($shipment);

    $reloaded = $this->repository->findById($id);

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->lines())->toHaveCount(1);
});
