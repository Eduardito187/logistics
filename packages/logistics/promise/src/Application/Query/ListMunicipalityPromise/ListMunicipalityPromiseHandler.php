<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\ListMunicipalityPromise;

use DateInterval;
use DateTimeImmutable;
use Logistics\Promise\Application\ReadModel\MunicipalitySlotView;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchCalendar;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Service\CustomerLocationResolver;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Resuelve la promesa de entrega del cliente: traduce el destino (municipio/coordenada/envío) a un
 * municipio con el CustomerLocationResolver y, día a día en el horizonte pedido, pregunta al calendario
 * de despacho del municipio las franjas con cupo. El calendario (Infrastructure, contra capacity) ya
 * excluye días bloqueados y fuera del horizonte activo, así que lo que vuelve es prometible sin más.
 *
 * Solo lectura: no reserva ni escribe. Si el municipio no se puede resolver (dirección fuera de cobertura
 * o envío sin geocodificar), devuelve lista vacía: no hay nada que prometer, no es un error. La respuesta
 * no lleva PII (ni envío, ni cliente, ni dirección): solo municipio, fecha, franja y cupo.
 *
 * @implements QueryHandler<ListMunicipalityPromise, list<MunicipalitySlotView>>
 */
final readonly class ListMunicipalityPromiseHandler implements QueryHandler
{
    /** Tope defensivo del horizonte: evita barridos absurdos si el canal pide un rango enorme. */
    private const int MAX_HORIZON_DAYS = 60;

    public function __construct(
        private CustomerLocationResolver $location,
        private MunicipalityDispatchCalendar $calendar,
        private Clock $clock,
    ) {}

    /**
     * @return list<MunicipalitySlotView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListMunicipalityPromise);

        $municipalityId = $this->location->resolve(
            $query->municipalityId,
            $query->latitude,
            $query->longitude,
            $query->shipmentId,
        );

        // Sin municipio resoluble no hay cobertura conocida: nada que prometer (no es un error de regla).
        if ($municipalityId === null) {
            return [];
        }

        $from = $this->parseFrom($query->fromDate);
        $horizon = $this->boundedHorizon($query->horizonDays);

        $views = [];
        for ($offset = 0; $offset < $horizon; $offset++) {
            $date = $from->add(new DateInterval("P{$offset}D"));

            foreach ($this->calendar->availableSlots($municipalityId, $date) as $slot) {
                // El calendario ya solo devuelve franjas de días despachables; se reconfirma el cupo > 0.
                if (!$slot->hasCapacity()) {
                    continue;
                }

                $views[] = MunicipalitySlotView::fromSlotCapacity($municipalityId, $date->format('Y-m-d'), $slot);
            }
        }

        return $views;
    }

    private function parseFrom(?string $fromDate): DateTimeImmutable
    {
        if ($fromDate === null || $fromDate === '') {
            return $this->clock->now()->setTime(0, 0, 0);
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
        if ($date === false) {
            throw PromiseException::because("fecha de inicio inválida (se esperaba Y-m-d): {$fromDate}");
        }

        return $date;
    }

    private function boundedHorizon(int $horizonDays): int
    {
        if ($horizonDays < 1) {
            return 1;
        }

        return min($horizonDays, self::MAX_HORIZON_DAYS);
    }
}
