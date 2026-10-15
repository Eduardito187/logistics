<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\OperationalCorridorsByDepartment;

use Logistics\Network\Application\ReadModel\OperationalCorridorView;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\Service\OperationalCorridorResolver;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve los corredores operativos de un departamento combinando nodos y tramos y delegando la
 * regla en el servicio de dominio OperationalCorridorResolver (PHP puro). Devuelve modelos de lectura
 * primitivos, listos para la API o para que planning/promise los consuman por el QueryBus.
 *
 * @implements QueryHandler<OperationalCorridorsByDepartmentQuery, list<OperationalCorridorView>>
 */
final readonly class OperationalCorridorsByDepartmentHandler implements QueryHandler
{
    public function __construct(
        private NodeRepository $nodes,
        private LegRepository $legs,
        private OperationalCorridorResolver $resolver = new OperationalCorridorResolver,
    ) {}

    /**
     * @return list<OperationalCorridorView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof OperationalCorridorsByDepartmentQuery);

        $department = Department::tryFrom($query->department)
            ?? throw NetworkException::because("departamento desconocido: {$query->department}");

        $corridors = $this->resolver->inDepartment($this->nodes->all(), $this->legs->all(), $department);

        return array_map(OperationalCorridorView::fromCorridor(...), $corridors);
    }
}
