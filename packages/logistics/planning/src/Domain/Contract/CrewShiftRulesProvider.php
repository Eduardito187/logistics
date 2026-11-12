<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\CrewShiftWindow;
use Logistics\Planning\Domain\ValueObject\PlanDate;

/**
 * Puerto de solo lectura con la ventana de turno de las tripulaciones vigente para un día. El dominio
 * define QUÉ necesita; la implementación (que traduce las reglas de turnos del contexto de Flota) vive en
 * Infrastructure.
 *
 * Devuelve null cuando no hay reglas utilizables (o están desactivadas): el planificador se comporta como
 * siempre (sin acotar salida ni duración).
 */
interface CrewShiftRulesProvider
{
    public function windowFor(PlanDate $date): ?CrewShiftWindow;
}
