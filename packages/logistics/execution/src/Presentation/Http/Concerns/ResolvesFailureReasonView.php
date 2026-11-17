<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Concerns;

use Logistics\Execution\Application\Query\ListFailureReasons\ListFailureReasons;
use Logistics\Execution\Application\ReadModel\FailureReasonView;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Resuelve la vista de UN motivo del catálogo por su code, para devolver el recurso tras crear/editar/
 * desactivar. No hay consulta de "uno solo" porque el catálogo es pequeño: se filtra el listado completo.
 */
trait ResolvesFailureReasonView
{
    /**
     * @return array<string, mixed>|null
     */
    protected function failureReasonByCode(QueryBus $queryBus, string $code): ?array
    {
        // Normaliza el code igual que el dominio para casar con la forma persistida (MAYÚSCULAS).
        $normalized = FailureReasonCode::fromString($code)->value;

        /** @var list<FailureReasonView> $views */
        $views = $queryBus->ask(new ListFailureReasons());
        foreach ($views as $view) {
            if ($view->code === $normalized) {
                return $view->toArray();
            }
        }

        return null;
    }
}
