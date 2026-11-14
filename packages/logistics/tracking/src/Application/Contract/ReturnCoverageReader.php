<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Contract;

/**
 * Lado lectura cross-context: cuánto de un pedido entregado ya se devolvió, por producto.
 *
 * Una devolución puede ser PARCIAL (2 de 3 productos): el pedido solo pasa a «devuelto» cuando se devolvió
 * completo; mientras tanto sigue «entregado» y cada producto conserva su historia. Solo primitivos.
 */
interface ReturnCoverageReader
{
    /**
     * Cantidades entregadas y devueltas del pedido, por SKU. Vacío si el pedido no tiene líneas conocidas.
     *
     * @return array<string, array{delivered: int, returned: int}>
     */
    public function coverage(string $orderReference): array;

    /**
     * ¿Se devolvió el pedido COMPLETO (cada producto, toda su cantidad)? Sin líneas conocidas responde true
     * para conservar el comportamiento previo (la devolución marca el pedido como devuelto).
     */
    public function isFullyReturned(string $orderReference): bool;
}
