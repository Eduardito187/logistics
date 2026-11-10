<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\GetDispatchLines;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta las LÍNEAS DE DESPACHO de una orden para la pantalla de etiquetado por producto de la app:
 * cada línea con su producto (nombre + imagen), origen y destino humanos, su envío (para agrupar
 * multishipping), si está AUTORIZADA (plan publicado) y el estado de su etiqueta (pending/emitted/revoked).
 *
 * La forma es n-a-n (destino POR LÍNEA) desde ya: hoy una orden es 1 envío (destino único), pero el
 * contrato soporta el split real producto→destino sin rehacer el cliente.
 */
final readonly class GetDispatchLines implements Query
{
    public function __construct(
        public string $orderNumber,
    ) {}
}
