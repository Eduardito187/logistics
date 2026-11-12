<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

/**
 * Traduce el CÓDIGO de un nodo de red (tienda, CD) a su id (UUID), para poblar el nodo destino de un
 * envío de retiro en tienda / traspaso cuando la fuente manda el código y no el UUID.
 *
 * Contrato de Application; la implementación lee la red en solo lectura, sin acoplar el dominio de
 * `network`. Tolerante: si no encuentra el código (o la red no está disponible) devuelve null.
 */
interface NetworkNodeLookup
{
    public function idForCode(string $code): ?string;
}
