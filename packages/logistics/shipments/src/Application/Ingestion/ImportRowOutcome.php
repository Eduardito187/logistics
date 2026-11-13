<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Ingestion;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Desenlace de una fila de la importación, para el reporte que vuelve al operador.
 */
enum ImportRowOutcome: string
{
    use CaseInsensitiveEnum;

    /** Fila ingresada como pedido nuevo. */
    case Imported = 'IMPORTED';

    /** Fila válida pero ya existente por idempotencia (misma fuente + id externo): no se duplicó. */
    case Duplicate = 'DUPLICATE';

    /** Fila rechazada: formato inválido o error al ingresarla. */
    case Failed = 'FAILED';
}
