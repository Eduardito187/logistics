<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ReserveMunicipalitySlot;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención del cliente de RESERVAR una franja de despacho (día + ventana) para su envío, sobre el
 * calendario por MUNICIPIO. Toma una entrega del cupo del municipio para esa fecha y franja (en capacity)
 * y fija la franja en el envío (en shipments), de forma atómica e idempotente.
 *
 * El municipio se resuelve del propio envío (su coordenada geocodificada); el canal puede además pasar un
 * municipio o una coordenada explícitos (precedencia: municipio > coordenada > envío). DTO inmutable de
 * primitivos: la fecha es 'Y-m-d' y la ventana es inicio/fin en 'HH:MM'.
 */
final readonly class ReserveMunicipalitySlot implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $date,
        public string $windowStart,
        public string $windowEnd,
        public ?string $municipalityId = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {}
}
