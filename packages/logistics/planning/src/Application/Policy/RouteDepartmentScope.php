<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Policy;

/**
 * Resultado de evaluar la regla geográfica sobre las paradas de cliente de una ruta: el departamento
 * común (si se pudo determinar) y si la ruta es intermunicipal (varias ciudades dentro del mismo
 * departamento) o de un único municipio.
 *
 * Es informativo: la ruta ya es válida cuando llega acá (si violara la regla, la política habría
 * lanzado RouteSpansMultipleDepartments). Permite marcar/propagar el carácter intermunicipal.
 */
final readonly class RouteDepartmentScope
{
    public function __construct(
        public ?string $departmentId,
        public ?string $departmentName,
        public bool $intermunicipal,
    ) {}

    /**
     * Alcance vacío: no había paradas de cliente con departamento conocido, así que no hay regla que
     * marcar. Se usa, p. ej., para rutas de puros traspasos CD→CD.
     */
    public static function unknown(): self
    {
        return new self(null, null, false);
    }

    public function isResolved(): bool
    {
        return $this->departmentId !== null;
    }
}
