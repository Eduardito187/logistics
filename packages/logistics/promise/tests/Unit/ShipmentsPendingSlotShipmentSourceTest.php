<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use Logistics\Promise\Infrastructure\Shipments\ShipmentsPendingSlotShipmentSource;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\ReadModel\ShipmentView;

/*
| Adaptador real del puerto de envíos sloteables. Prueban que pide a shipments el rango de fechas desde
| el horizonte configurado y que filtra en PHP: deja solo los que tienen fecha y AÚN no ventana,
| respetando el límite. El cruce entre contextos es por QueryBus y primitivos.
*/

/** Clock fijo para que el rango de fechas sea determinista. */
function pendingSlotClock(string $instant): Clock
{
    return new class($instant) implements Clock
    {
        public function __construct(private string $instant) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->instant);
        }
    };
}

/** QueryBus que captura la consulta recibida (en `$captured`) y devuelve una lista fija de vistas. */
function pendingSlotQueryBus(array $views): QueryBus
{
    return new class($views) implements QueryBus
    {
        public ?Query $captured = null;

        /** @param list<ShipmentView> $views */
        public function __construct(private array $views) {}

        public function ask(Query $query): mixed
        {
            $this->captured = $query;

            return $this->views;
        }
    };
}

/** Vista con fecha prometida pero SIN ventana (franja incompleta): es sloteable. */
function pendingSlotView(string $id, ?string $date, ?string $start = null, ?string $end = null): ShipmentView
{
    return new ShipmentView(
        id: $id,
        source: 'SAP',
        externalId: 'EXT-'.$id,
        customerName: 'Cliente',
        customerDocumentId: null,
        customerPhone: null,
        customerEmail: null,
        addressText: 'Calle 1',
        addressReference: null,
        lat: null,
        lng: null,
        addressConfidenceScore: 0,
        addressConfidenceLevel: 'low',
        status: 'PLANNED',
        deliveryDate: $date,
        deliveryWindowStart: $start,
        deliveryWindowEnd: $end,
        destinationNodeId: null,
        lines: [],
    );
}

function pendingSlotConfig(mixed $horizon = 30): ConfigRepository
{
    return new ConfigRepository([
        'logistics-promise' => ['auto_scheduling' => ['sweep_horizon_days' => $horizon]],
    ]);
}

it('consulta shipments por el rango de fechas desde el horizonte configurado', function (): void {
    $bus = pendingSlotQueryBus([]);
    $source = new ShipmentsPendingSlotShipmentSource($bus, pendingSlotClock('2026-10-18 09:00:00'), pendingSlotConfig(10));

    iterator_to_array($source->pendingSlotShipments(100));

    expect($bus->captured)->toBeInstanceOf(ListShipments::class)
        ->and($bus->captured->deliveryDateFrom)->toBe('2026-10-18')
        ->and($bus->captured->deliveryDateTo)->toBe('2026-10-28');
});

it('solo devuelve los envíos con fecha y todavía sin ventana', function (): void {
    $views = [
        pendingSlotView('a', '2026-10-20'),                               // sloteable
        pendingSlotView('b', '2026-10-21', '09:00', '13:00'),             // ya tiene ventana → descartado
        pendingSlotView('c', null),                                       // sin fecha → descartado
        pendingSlotView('d', '2026-10-22'),                               // sloteable
    ];
    $source = new ShipmentsPendingSlotShipmentSource(pendingSlotQueryBus($views), pendingSlotClock('2026-10-18 09:00:00'), pendingSlotConfig());

    $result = iterator_to_array($source->pendingSlotShipments(100), false);

    expect($result)->toBe([
        ['shipment_id' => 'a', 'date' => '2026-10-20'],
        ['shipment_id' => 'd', 'date' => '2026-10-22'],
    ]);
});

it('respeta el límite de envíos a slotear', function (): void {
    $views = [
        pendingSlotView('a', '2026-10-20'),
        pendingSlotView('b', '2026-10-21'),
        pendingSlotView('c', '2026-10-22'),
    ];
    $source = new ShipmentsPendingSlotShipmentSource(pendingSlotQueryBus($views), pendingSlotClock('2026-10-18 09:00:00'), pendingSlotConfig());

    expect(iterator_to_array($source->pendingSlotShipments(2), false))->toHaveCount(2);
});

it('acepta una página de resultados y extrae sus ítems', function (): void {
    // El bus devuelve directamente un Page (no una lista): el adaptador toma sus ítems.
    $page = new Page([pendingSlotView('a', '2026-10-20')], 1);
    $busWithPage = new class($page) implements QueryBus
    {
        public function __construct(private Page $page) {}

        public function ask(Query $query): mixed
        {
            return $this->page;
        }
    };
    $source = new ShipmentsPendingSlotShipmentSource($busWithPage, pendingSlotClock('2026-10-18'), pendingSlotConfig());

    expect(iterator_to_array($source->pendingSlotShipments(100), false))->toBe([
        ['shipment_id' => 'a', 'date' => '2026-10-20'],
    ]);
});

it('cae al horizonte por defecto cuando la config no es numérica o es no positiva', function (): void {
    $bus = pendingSlotQueryBus([]);
    $source = new ShipmentsPendingSlotShipmentSource($bus, pendingSlotClock('2026-10-18 00:00:00'), pendingSlotConfig('nope'));

    iterator_to_array($source->pendingSlotShipments(100));

    // 30 días por defecto.
    expect($bus->captured->deliveryDateTo)->toBe('2026-11-17');
});
