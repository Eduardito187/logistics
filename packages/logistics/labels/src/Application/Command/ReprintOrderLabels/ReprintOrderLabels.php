<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\ReprintOrderLabels;

use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Shared\Application\Command\Command;

/**
 * Reimpresión de las etiquetas de un pedido (P1-LBL-06): revoca las vigentes y emite un lote nuevo.
 *
 * Caso de uso cuando una etiqueta se dañó, se perdió o cambió el contenido del bulto. Las etiquetas
 * anteriores quedan revocadas (ya no son válidas) pero se conservan en la base para auditoría; las
 * nuevas llevan `id` nuevos, así la detección de duplicados sigue siendo dura. Lleva un motivo para
 * dejar rastro de por qué se reimprimió.
 */
final readonly class ReprintOrderLabels implements Command
{
    /**
     * @param list<OrderLineInput> $lines líneas del pedido, en orden; la posición fija el número de línea
     */
    public function __construct(
        public string $orderNumber,
        public string $origin,
        public string $destination,
        public array $lines,
        public string $reason,
    ) {}
}
