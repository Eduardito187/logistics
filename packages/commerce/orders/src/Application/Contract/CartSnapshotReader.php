<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Contract;

/**
 * Lectura read-only del carrito del contexto `cart`, para armar la orden en el checkout orquestado
 * (carrito → orden). Es un PUERTO de Application: la implementación vive en Infrastructure y lee la
 * proyección del carrito por query builder, sin acoplar `orders` al dominio de `cart` (así cada
 * contexto sigue siendo extraíble a microservicio; la lectura cruzada degrada a null si falta el dato).
 *
 * El precio de cada línea viaja YA congelado desde el carrito (client-side pricing): `orders` no vuelve
 * a consultar `pricing`.
 */
interface CartSnapshotReader
{
    /**
     * Instantánea del carrito lista para crear la orden, o null si el carrito no existe.
     *
     * `ship_to` sale null salvo que el carrito llegue a guardar datos de entrega (hoy no los guarda):
     * en el checkout los aporta el cuerpo del request. Las líneas vienen con la cantidad bajo `quantity`
     * (forma que consume `orders`), no `qty` (forma de `cart`).
     *
     * @return array{
     *     lines: list<array{sku: string, product_name: string, unit_price_cents: int, quantity: int}>,
     *     currency: string,
     *     customer_id: ?string,
     *     ship_to: ?array{customer_name: string, address_text: string, reference: ?string, phone: ?string, email: ?string}
     * }|null
     */
    public function snapshot(string $cartId): ?array;
}
