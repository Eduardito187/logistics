<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Service;

use DateInterval;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\Service\DispatchDayAvailability;
use Logistics\Capacity\Domain\ValueObject\ActivationWindow;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Materializa los cupos de una activación por rango, por LOTES de días de calendario. Por cada fecha del
 * lote cuyo día de la semana esté activo y que NO esté bloqueada (feriado u otro motivo), despacha un
 * DefineCapacity por cada ventana (franja+unidad+cantidad), reusando la idempotencia de DefineCapacity:
 * un cupo que ya existe (por su clave natural, municipio incluido) se salta sin error.
 *
 * Procesar por lotes permite a la cola trocear rangos grandes (p. ej. dos meses × varias ventanas ×
 * varios municipios) sin un job eterno: devuelve la fecha desde la que continuar, o null si terminó.
 *
 * Cada DefineCapacity corre en su propia transacción (la del CommandBus), así una carrera en un cupo no
 * tumba el lote entero. Es Application: orquesta comandos y el servicio de dominio de disponibilidad.
 */
final class CapacityWindowActivator
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly DispatchDayAvailability $availability,
    ) {}

    /**
     * Procesa hasta `maxDays` días de calendario desde `from` (sin pasar de `to`) para un municipio.
     * La activación por rango es SIEMPRE por municipio (el eje de gestión de cupos); "todos" lo resuelve
     * quien dispara la activación expandiendo la lista de municipios. Devuelve el resultado del lote con
     * el cursor de continuación.
     *
     * @param list<int>              $weekdays días de la semana activos (1 = lunes … 7 = domingo)
     * @param list<ActivationWindow> $windows  ventanas a materializar en cada día elegible
     */
    public function activateChunk(
        string $municipalityId,
        SlotDate $from,
        SlotDate $to,
        array $weekdays,
        array $windows,
        int $maxDays,
    ): ActivationChunkResult {
        if ($maxDays < 1) {
            throw CapacityException::because('el tamaño de lote de activación debe ser al menos 1 día');
        }

        $created = 0;
        $skippedExisting = 0;
        $skippedBlocked = 0;
        $skippedInactive = 0;

        $cursor = $from->toDateTime();
        $processed = 0;

        while ($processed < $maxDays) {
            $date = SlotDate::fromDateTime($cursor);
            if ($date->isAfter($to)) {
                // Se recorrió todo el rango dentro de este lote: no hay continuación.
                return new ActivationChunkResult($created, $skippedExisting, $skippedBlocked, $skippedInactive, null);
            }

            $weekday = (int) $cursor->format('N');
            if (!in_array($weekday, $weekdays, true)) {
                $skippedInactive++;
            } elseif ($this->availability->isBlocked($date, $municipalityId)) {
                $skippedBlocked++;
            } else {
                foreach ($windows as $window) {
                    $this->defineBucket($municipalityId, $date, $window) ? $created++ : $skippedExisting++;
                }
            }

            $cursor = $cursor->add(new DateInterval('P1D'));
            $processed++;
        }

        // Se agotó el lote antes de llegar a `to`: continúa desde el cursor si aún está dentro del rango.
        $next = SlotDate::fromDateTime($cursor);
        $nextFrom = $next->isAfter($to) ? null : $next;

        return new ActivationChunkResult($created, $skippedExisting, $skippedBlocked, $skippedInactive, $nextFrom);
    }

    /**
     * Despacha DefineCapacity para una fecha y ventana. Devuelve true si creó el cupo, false si ya
     * existía (carrera o re-ejecución): DefineCapacity es idempotente por la clave natural.
     */
    private function defineBucket(string $municipalityId, SlotDate $date, ActivationWindow $window): bool
    {
        try {
            $this->commandBus->dispatch(new DefineCapacityCommand(
                nodeId: null,
                zoneId: null,
                date: $date->toString(),
                timeSlot: $window->timeSlot->toString(),
                unit: $window->unit->value,
                total: $window->quantity->value(),
                municipalityId: $municipalityId,
            ));

            return true;
        } catch (CapacityException) {
            // El cupo ya existía (o una carrera lo definió entremedio): se salta, no es un error.
            return false;
        }
    }
}
