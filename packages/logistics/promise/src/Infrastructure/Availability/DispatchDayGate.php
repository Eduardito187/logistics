<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Availability;

use DateTimeImmutable;
use Logistics\Capacity\Application\Query\CheckDispatchDay\CheckDispatchDayQuery;
use Logistics\Capacity\Application\ReadModel\DispatchDayAvailabilityView;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchGate;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Colaborador de infraestructura que decide si un (municipio, fecha) es DESPACHABLE antes de ofrecer sus
 * franjas al cliente. Pregunta al contexto de capacidad —por el QueryBus y en primitivos— si el día está
 * bloqueado (feriado u otro motivo, propio del municipio o global) y si cae dentro del horizonte activo
 * de cupos de ese municipio.
 *
 * Vive junto a los adaptadores de disponibilidad de promesa porque es una regla de CRUCE con capacity,
 * no del dominio de promesa: el dominio solo pide "franjas con cupo"; este filtro adicional (día abierto
 * y dentro del horizonte activo) es parte del CÓMO se resuelve esa disponibilidad contra capacity. El día
 * que capacity se extraiga a un microservicio, esta clase pasa a llamar su API sin tocar el dominio.
 *
 * Es defensivo a propósito: aunque el generador de calendario SALTA los días bloqueados y fuera de
 * horizonte al crear los cupos, un feriado puede declararse —o el horizonte acortarse— DESPUÉS de que
 * los cupos ya existen. Revalidar en el momento de ofrecer evita prometer un día que dejó de ser válido.
 */
final readonly class DispatchDayGate implements MunicipalityDispatchGate
{
    public function __construct(private QueryBus $queryBus) {}

    /**
     * ¿El municipio puede despachar en esa fecha? Falso si el día está bloqueado o queda fuera del
     * horizonte activo del municipio. `municipalityId` null consulta el alcance global (cupos a mano o
     * sin municipio resuelto): así un bloqueo u horizonte global también frena la oferta.
     */
    public function isDispatchable(?string $municipalityId, DateTimeImmutable $date): bool
    {
        /** @var DispatchDayAvailabilityView $view */
        $view = $this->queryBus->ask(new CheckDispatchDayQuery($date->format('Y-m-d'), $municipalityId));

        return !$view->blocked && $view->withinHorizon;
    }
}
