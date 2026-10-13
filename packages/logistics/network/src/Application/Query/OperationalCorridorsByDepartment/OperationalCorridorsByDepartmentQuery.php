<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\OperationalCorridorsByDepartment;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide los corredores operativos de un departamento: el insumo con el que planning/promise saben qué
 * puede salir (y entrar) por ese departamento. El departamento llega como string primitivo (el value
 * del enum Department); el handler lo valida.
 */
final readonly class OperationalCorridorsByDepartmentQuery implements Query
{
    public function __construct(public string $department) {}
}
