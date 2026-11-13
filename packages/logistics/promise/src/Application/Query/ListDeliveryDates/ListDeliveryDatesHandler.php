<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\ListDeliveryDates;

use DateInterval;
use DateTimeImmutable;
use Logistics\Promise\Application\ReadModel\DeliveryDateView;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchCalendar;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Service\CustomerLocationResolver;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Resuelve los DÍAS ofrecibles al cliente: traduce el destino a un municipio y, día a día en el horizonte,
 * cuenta las franjas con cupo que devuelve el calendario de despacho del municipio (que ya excluye días
 * bloqueados y fuera del horizonte activo). Devuelve solo los días con al menos una ventana factible.
 *
 * Solo lectura: no reserva ni escribe. Si el municipio no se puede resolver, devuelve lista vacía (no hay
 * nada que ofrecer, no es un error). La respuesta no lleva PII: solo municipio, fecha y conteo de ventanas.
 *
 * @implements QueryHandler<ListDeliveryDates, list<DeliveryDateView>>
 */
final readonly class ListDeliveryDatesHandler implements QueryHandler
{
    /** Tope defensivo del horizonte: evita barridos absurdos si el canal pide un rango enorme. */
    private const int MAX_HORIZON_DAYS = 60;

    public function __construct(
        private CustomerLocationResolver $location,
        private MunicipalityDispatchCalendar $calendar,
        private Clock $clock,
    ) {}

    /**
     * @return list<DeliveryDateView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListDeliveryDates);

        $municipalityId = $this->location->resolve(
            $query->municipalityId,
            $query->latitude,
            $query->longitude,
            $query->shipmentId,
        );

        if ($municipalityId === null) {
            return [];
        }

        $from = $this->parseFrom($query->fromDate);
        $horizon = $this->boundedHorizon($query->horizonDays);

        $views = [];
        for ($offset = 0; $offset < $horizon; $offset++) {
            $date = $from->add(new DateInterval("P{$offset}D"));

            $windows = 0;
            foreach ($this->calendar->availableSlots($municipalityId, $date) as $slot) {
                if ($slot->hasCapacity()) {
                    $windows++;
                }
            }

            if ($windows > 0) {
                $views[] = new DeliveryDateView($municipalityId, $date->format('Y-m-d'), $windows);
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
