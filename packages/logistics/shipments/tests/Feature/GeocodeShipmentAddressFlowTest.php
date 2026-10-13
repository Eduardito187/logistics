<?php

declare(strict_types=1);

use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\GeocodeShipmentAddress\GeocodeShipmentAddress;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Shipments\Domain\Contract\Geocoder;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Domain\ValueObject\GeocodeResult;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Flujo de re-geocodificación contra PostgreSQL (logistics_test): se ingresa un pedido sin coordenada
 * (confianza media del stub), luego se re-geocodifica con un proveedor que devuelve un punto exacto, y
 * el envío queda con la coordenada fijada y la confianza recalculada. Verifica la mejora del comando
 * GeocodeShipmentAddress: ya no solo recalcula el score, también persiste el punto.
 */
function fakeGeocoder(GeocodeResult $result): Geocoder
{
    return new class($result) implements Geocoder
    {
        public function __construct(private readonly GeocodeResult $result) {}

        public function geocode(Address $address): GeocodeResult
        {
            return $this->result;
        }

        public function score(Address $address): AddressConfidence
        {
            return $this->result->confidence;
        }
    };
}

function receiveOrderWithoutCoordinate(string $externalId): void
{
    app(CommandBus::class)->dispatch(new ReceiveShipment(
        source: 'STORE',
        externalId: $externalId,
        customerName: 'Cliente de Prueba',
        addressText: 'Av. Arce 2345, La Paz',
        addressReference: 'Edificio Multicentro',
        lat: null,
        lng: null,
        lines: [new ShipmentLineInput('SKU-1', 'Horno', 1, null, null)],
    ));
}

function reloadOrder(string $externalId): ?Shipment
{
    return app(ShipmentRepository::class)->findBySourceReference(
        SourceReference::of(OrderSource::Store, $externalId),
    );
}

it('fixes the coordinate and recalculates confidence on re-geocoding', function (): void {
    $externalId = 'ORD-GEO-'.uniqid();

    receiveOrderWithoutCoordinate($externalId);

    $before = reloadOrder($externalId);
    expect($before)->not->toBeNull()
        ->and($before?->address()->hasGeoPoint())->toBeFalse();

    app()->instance(Geocoder::class, fakeGeocoder(GeocodeResult::of(
        AddressConfidence::of(95),
        GeocodeMatchType::Exact,
        GeoPoint::of(-16.5002, -68.1503),
    )));

    app(CommandBus::class)->dispatch(
        new GeocodeShipmentAddress(
            $before?->id()->toString() ?? '',
        ),
    );

    $after = reloadOrder($externalId);

    expect($after?->address()->hasGeoPoint())->toBeTrue()
        ->and($after?->address()->geoPoint?->lat)->toBe(-16.5002)
        ->and($after?->address()->geoPoint?->lng)->toBe(-68.1503)
        ->and($after?->addressConfidence()->score)->toBe(95);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('recalculates confidence without dropping an existing point when the provider finds nothing', function (): void {
    $externalId = 'ORD-GEO-'.uniqid();

    app(CommandBus::class)->dispatch(new ReceiveShipment(
        source: 'STORE',
        externalId: $externalId,
        customerName: 'Cliente',
        addressText: 'Zona Sur',
        addressReference: null,
        lat: -16.55,
        lng: -68.08,
        lines: [new ShipmentLineInput('SKU-2', 'Lavadora', 1, null, null)],
    ));

    $before = reloadOrder($externalId);
    expect($before?->address()->hasGeoPoint())->toBeTrue();

    app()->instance(Geocoder::class, fakeGeocoder(
        GeocodeResult::notFound(AddressConfidence::of(10)),
    ));

    app(CommandBus::class)->dispatch(
        new GeocodeShipmentAddress(
            $before?->id()->toString() ?? '',
        ),
    );

    $after = reloadOrder($externalId);

    expect($after?->addressConfidence()->score)->toBe(10)
        ->and($after?->address()->hasGeoPoint())->toBeTrue()
        ->and($after?->address()->geoPoint?->lat)->toBe(-16.55);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
