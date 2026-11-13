<?php

declare(strict_types=1);

use Logistics\Promise\Application\Query\ListDeliveryDates\ListDeliveryDates;
use Logistics\Promise\Application\Query\ListDeliveryDates\ListDeliveryDatesHandler;
use Logistics\Promise\Application\ReadModel\DeliveryDateView;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchCalendar;
use Logistics\Promise\Domain\Contract\MunicipalityResolver;
use Logistics\Promise\Domain\Contract\ShipmentLocator;
use Logistics\Promise\Domain\Service\CustomerLocationResolver;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;
use Logistics\Shared\Domain\Clock\Clock;

/*
| Query ListDeliveryDates (Módulo 1): el canal muestra SOLO fechas. Prueban que devuelve los días con al
| menos una ventana con cupo, cuenta las ventanas, respeta el horizonte y es vacío sin municipio.
*/

/** Reloj fijo. */
function lddClock(string $moment): Clock
{
    return new class($moment) implements Clock
    {
        public function __construct(private readonly string $moment) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->moment);
        }
    };
}

function lddResolver(?string $municipalityId): MunicipalityResolver
{
    return new class($municipalityId) implements MunicipalityResolver
    {
        public function __construct(private readonly ?string $municipalityId) {}

        public function resolve(float $latitude, float $longitude): ?string
        {
            return $this->municipalityId;
        }
    };
}

function lddShipmentLocator(): ShipmentLocator
{
    return new class implements ShipmentLocator
    {
        public function locate(string $shipmentId): ?array
        {
            return null;
        }
    };
}

/**
 * Calendario falso por fecha: mapea 'Y-m-d' a la lista de franjas (etiqueta => cupo) de ese día.
 *
 * @param array<string, array<string, float>> $byDate
 */
function lddCalendar(array $byDate): MunicipalityDispatchCalendar
{
    return new class($byDate) implements MunicipalityDispatchCalendar
    {
        /** @param array<string, array<string, float>> $byDate */
        public function __construct(private readonly array $byDate) {}

        public function availableSlots(string $municipalityId, DateTimeImmutable $date): array
        {
            $slots = $this->byDate[$date->format('Y-m-d')] ?? [];
            $capacities = [];
            foreach ($slots as $label => $available) { // NOSONAR: FP de data-flow; el foreach inicializa label/available.
                $capacities[] = SlotCapacity::of((string) $label, $available);
            }

            return $capacities;
        }
    };
}

/**
 * @param array<string, array<string, float>> $byDate
 */
function lddHandler(array $byDate, string $now = '2026-11-20 06:00:00', ?string $municipalityId = 'muni-a'): ListDeliveryDatesHandler
{
    return new ListDeliveryDatesHandler(
        new CustomerLocationResolver(lddResolver($municipalityId), lddShipmentLocator()),
        lddCalendar($byDate),
        lddClock($now),
    );
}

it('devuelve solo los días con al menos una ventana con cupo y cuenta las ventanas', function (): void {
    $handler = lddHandler([
        '2026-11-20' => ['09:00-13:00' => 5.0, '14:00-18:00' => 2.0],
        '2026-11-21' => ['09:00-13:00' => 0.0], // sin cupo → no aparece
        '2026-11-22' => ['14:00-18:00' => 1.0],
    ]);

    /** @var list<DeliveryDateView> $views */
    $views = $handler->handle(new ListDeliveryDates(municipalityId: 'muni-a', fromDate: '2026-11-20', horizonDays: 3));

    expect($views)->toHaveCount(2)
        ->and($views[0]->date)->toBe('2026-11-20')
        ->and($views[0]->windowCount)->toBe(2)
        ->and($views[1]->date)->toBe('2026-11-22')
        ->and($views[1]->windowCount)->toBe(1);
});

it('devuelve lista vacía cuando no se resuelve el municipio', function (): void {
    $views = lddHandler(['2026-11-20' => ['09:00-13:00' => 5.0]], municipalityId: null)
        ->handle(new ListDeliveryDates(latitude: -16.5, longitude: -68.1, fromDate: '2026-11-20', horizonDays: 3));

    expect($views)->toBe([]);
});

it('usa el reloj del negocio como fecha de inicio cuando no se pasa fromDate', function (): void {
    $views = lddHandler(['2026-11-20' => ['09:00-13:00' => 5.0]], now: '2026-11-20 06:00:00')
        ->handle(new ListDeliveryDates(municipalityId: 'muni-a', horizonDays: 1));

    expect($views)->toHaveCount(1)
        ->and($views[0]->date)->toBe('2026-11-20');
});
