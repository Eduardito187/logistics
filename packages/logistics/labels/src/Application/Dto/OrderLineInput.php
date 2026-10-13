<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Dto;

/**
 * Línea de pedido para emitir etiquetas: primitivos tal como llegan de quien pide la emisión.
 *
 * DTO propio del contexto a propósito: Etiquetas NO conoce el package shipments. Quien integre traduce
 * su modelo a este DTO. El handler lo convierte a los primitivos que consume LabelBatchFactory.
 *
 *  - `quantity`      = unidades del producto en la línea (cada unidad es un "N de M" en `u`).
 *  - `piecesPerUnit` = piezas (cajas) por unidad: una etiqueta por pieza, numeradas "N de M" en `pc`.
 *  - `name`          = nombre del producto; dato del pedido, no viaja en el QR (ADR 004: sin más datos).
 */
final readonly class OrderLineInput
{
    public function __construct(
        public string $sku,
        public string $name,
        public int $quantity,
        public int $piecesPerUnit = 1,
    ) {}
}
