<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\EmitShipmentLabels;

use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Shared\Application\Command\Command;

/**
 * Intención de emitir el lote de etiquetas de un pedido: una por bulto físico de cada línea.
 *
 * DTO inmutable con primitivos. El handler arma los payloads, los firma, renderiza su ZPL y los
 * persiste detectando duplicados.
 */
final readonly class EmitShipmentLabels implements Command
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
