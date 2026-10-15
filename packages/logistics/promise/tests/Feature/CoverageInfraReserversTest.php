<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedMunicipalitySlotReserver;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedSlotReserver;
use Logistics\Promise\Infrastructure\Shipments\ShipmentsOrderDeliverySlotGateway;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Domain\Exception\ShipmentsException;

/*
| Cobertura de los adaptadores de Infrastructure de promise que cruzan a capacity y shipments SOLO por el
| bus (sin tocar base): se ejercitan con un CommandBus/QueryBus en memoria que simula el éxito y el
| rechazo del contexto destino, para recorrer tanto el happy-path como las ramas que traducen o tragan la
| excepción del otro contexto. Son pruebas puras (el adaptador es PHP + bus); viven en Feature por
| convención del contexto.
*/

/** CommandBus en memoria: no-op, o que lanza la excepción dada al despachar. */
function cvpBus(?Throwable $throws = null): CommandBus
{
    return new class($throws) implements CommandBus
    {
        public int $calls = 0;

        public function __construct(private readonly ?Throwable $throws) {}

        public function dispatch(Command $command): void
        {
            $this->calls++;
            if ($this->throws !== null) {
                throw $this->throws;
            }
        }
    };
}

/** QueryBus en memoria que devuelve siempre el valor dado. */
function cvpQueryBus(mixed $answer): QueryBus
{
    return new class($answer) implements QueryBus
    {
        public function __construct(private readonly mixed $answer) {}

        public function ask(Query $query): mixed
        {
            return $this->answer;
        }
    };
}

function cvpSlot(): DeliverySlot
{
    return DeliverySlot::of(new DateTimeImmutable('2026-10-20'), '09:00-13:00');
}

// ---------------------------------------------------------------------------
// CapacityBackedSlotReserver
// ---------------------------------------------------------------------------

it('reserva cupo por zona cuando el comando de capacity tiene éxito', function (): void {
    $reserver = new CapacityBackedSlotReserver(cvpBus());

    expect($reserver->reserve('zone-1', cvpSlot()))->toBeTrue();
});

it('devuelve false cuando capacity rechaza el consumo de cupo por zona', function (): void {
    $reserver = new CapacityBackedSlotReserver(cvpBus(CapacityException::because('cupo agotado')));

    expect($reserver->reserve('zone-1', cvpSlot()))->toBeFalse();
});

it('libera cupo por zona sin romper aunque capacity lance', function (): void {
    $ok = new CapacityBackedSlotReserver(cvpBus());
    $failing = new CapacityBackedSlotReserver(cvpBus(CapacityException::because('ya no existe')));

    $ok->release('zone-1', cvpSlot());
    $failing->release('zone-1', cvpSlot());

    // Si llegó hasta aquí sin excepción, la liberación best-effort se comportó bien.
    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// CapacityBackedMunicipalitySlotReserver
// ---------------------------------------------------------------------------

it('reserva cupo por municipio cuando el comando de capacity tiene éxito', function (): void {
    $reserver = new CapacityBackedMunicipalitySlotReserver(cvpBus());

    expect($reserver->reserve('muni-a', cvpSlot()))->toBeTrue();
});

it('devuelve false cuando capacity rechaza el consumo de cupo por municipio', function (): void {
    $reserver = new CapacityBackedMunicipalitySlotReserver(cvpBus(CapacityException::because('día bloqueado')));

    expect($reserver->reserve('muni-a', cvpSlot()))->toBeFalse();
});

it('libera cupo por municipio sin romper aunque capacity lance', function (): void {
    $ok = new CapacityBackedMunicipalitySlotReserver(cvpBus());
    $failing = new CapacityBackedMunicipalitySlotReserver(cvpBus(CapacityException::because('ya no existe')));

    $ok->release('muni-a', cvpSlot());
    $failing->release('muni-a', cvpSlot());

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// ShipmentsOrderDeliverySlotGateway
// ---------------------------------------------------------------------------

it('devuelve null como franja vigente cuando el envío no existe', function (): void {
    $gateway = new ShipmentsOrderDeliverySlotGateway(cvpQueryBus(null), cvpBus());

    expect($gateway->currentSlot('order-1'))->toBeNull();
});

it('fija la franja del pedido despachando el comando de shipments', function (): void {
    $bus = cvpBus();
    $gateway = new ShipmentsOrderDeliverySlotGateway(cvpQueryBus(null), $bus);

    $gateway->assignSlot('order-1', '2026-10-20', '09:00', '13:00');

    expect($bus->calls)->toBe(1);
});

it('traduce el error de shipments a una excepción de promise al fijar la franja', function (): void {
    $gateway = new ShipmentsOrderDeliverySlotGateway(
        cvpQueryBus(null),
        cvpBus(ShipmentsException::because('el pedido ya no admite franja')),
    );

    expect(fn () => $gateway->assignSlot('order-1', '2026-10-20', '09:00', '13:00'))
        ->toThrow(PromiseException::class);
});
