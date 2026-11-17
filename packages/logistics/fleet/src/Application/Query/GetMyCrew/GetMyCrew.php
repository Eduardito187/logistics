<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetMyCrew;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta la asignación del día de un TRIPULANTE: el vehículo y la tripulación que el admin armó para una
 * fecha, vistos por quien la integra. El tripulante puede ser el CHOFER (se resuelve por `driverId`, su
 * `users.driver_id`) o un AYUDANTE (se resuelve por `helperId`, su `users.helper_id`); la vista resultante
 * es la misma (vehículo + chofer + ayudantes + ruta vigente). `date` es la fecha de armado en formato
 * 'Y-m-d' (ya resuelta por el controller a la zona America/La_Paz).
 *
 * Se pasa uno de los dos vínculos (el que tenga la cuenta); el otro queda null. Si ambos vienen null, el
 * handler devuelve null (sin asignación).
 *
 * `allowOpenCarryover`: true solo cuando se pide la asignación VIGENTE (sin `?date` explícito). En ese caso,
 * si no hay tripulación en `date` pero el chofer tiene una ruta EN CURSO de otro día (una entrega que cruzó
 * la medianoche o larga a otra ciudad/país), NO se le quita: se usa la tripulación de esa ruta abierta hasta
 * que se cierre. Con una fecha explícita (p. ej. previsualizar un día futuro) queda false: esa fecha exacta.
 */
final readonly class GetMyCrew implements Query
{
    public function __construct(
        public ?string $driverId,
        public string $date,
        public ?string $helperId = null,
        public bool $allowOpenCarryover = false,
    ) {}
}
