<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\CheckDispatchSlot;

use Logistics\Shared\Application\Query\Query;

/**
 * Pregunta si una franja horaria concreta de un (municipio, fecha) está bloqueada para despacho: por un
 * bloqueo de la franja (propio o global) o por un bloqueo del DÍA entero (propio o global). `timeSlot` es
 * 'HH:MM-HH:MM'. `municipalityId` null consulta el alcance global.
 *
 * Es la consulta que usan la oferta de slots, la planificación y la reprogramación para rechazar una
 * franja bloqueada, sin acoplar su dominio al de capacity (cruce por el QueryBus y en primitivos).
 */
final readonly class CheckDispatchSlotQuery implements Query
{
    public function __construct(
        public string $date,
        public string $timeSlot,
        public ?string $municipalityId = null,
    ) {}
}
