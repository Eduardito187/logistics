<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Contract;

/**
 * Puerto de lectura que resuelve el CÓDIGO del nodo CD de ORIGEN de un envío (campo `org` del QR), a
 * partir de sus datos primitivos. La implementación lee la red (CDs) y delega la regla pura en
 * Domain\Service\OriginCdSelector. Etiquetas NO conoce el package de red: solo recibe/devuelve códigos.
 */
interface OriginResolver
{
    /**
     * Código del CD de origen del envío, o '' si no se pudo resolver.
     *
     * @param ?string $destinationNodeId nodo destino del envío (retiro en tienda / nodo), o null si es a domicilio
     * @param ?float  $lat               latitud del envío (domicilio), o null
     * @param ?float  $lng               longitud del envío (domicilio), o null
     * @param ?string $orderReference    referencia del pedido (para inferir departamento por prefijo), o null
     */
    public function resolve(
        ?string $destinationNodeId,
        ?float $lat,
        ?float $lng,
        ?string $orderReference,
    ): string;
}
