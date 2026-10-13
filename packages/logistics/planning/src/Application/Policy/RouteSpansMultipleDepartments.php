<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Policy;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Se viola la regla geográfica de rutas: una entrega a CLIENTES no puede cruzar departamentos.
 *
 * Una ruta a clientes debe ser del mismo municipio o, si es intermunicipal, del mismo departamento.
 * No existe la entrega a clientes multidepartamental (los traspasos CD→CD, que sí pueden cruzar
 * departamentos, no llevan clientes y están exentos).
 *
 * Extiende la DomainException compartida (PlanningException es final y no se puede extender); lleva un
 * mensaje legible para mostrar en el admin.
 */
final class RouteSpansMultipleDepartments extends DomainException
{
    public static function between(?string $firstDepartment, ?string $secondDepartment): self
    {
        $first = $firstDepartment ?? 'desconocido';
        $second = $secondDepartment ?? 'desconocido';

        return new self(
            "una entrega a clientes no puede cruzar departamentos: la ruta mezcla {$first} y {$second}. "
            .'Las paradas de cliente de un camión deben ser del mismo departamento '
            .'(del mismo municipio, o intermunicipales dentro del mismo departamento).',
        );
    }
}
