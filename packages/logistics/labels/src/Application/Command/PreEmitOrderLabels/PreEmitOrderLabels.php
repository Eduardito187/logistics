<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\PreEmitOrderLabels;

use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Shared\Application\Command\Command;

/**
 * Pre-emisión de las etiquetas de un pedido al planificar (P1-LBL-03).
 *
 * La dispara el worker/integrador cuando se publica un plan (evento planning.plan_published): deja las
 * etiquetas firmadas y renderizadas listas ANTES de que el operario prepare el pedido, para que la app
 * pueda imprimirlas incluso sin conexión. Es idempotente: si el pedido ya tiene etiquetas vigentes, no
 * hace nada (el relay del outbox puede reentregar el evento sin duplicar).
 *
 * Labels NO conoce el package shipments: quien dispara la pre-emisión traduce las líneas del pedido a
 * estos primitivos (OrderLineInput).
 */
final readonly class PreEmitOrderLabels implements Command
{
    /**
     * @param list<OrderLineInput> $lines líneas del pedido, en orden; la posición fija el número de línea
     */
    public function __construct(
        public string $orderNumber,
        public string $origin,
        public string $destination,
        public array $lines,
    ) {}
}
