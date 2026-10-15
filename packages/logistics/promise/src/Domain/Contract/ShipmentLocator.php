<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

/**
 * Puerto hacia el contexto de envíos para ubicar geográficamente un envío: devuelve la coordenada de su
 * dirección de entrega, con la que promise resuelve el municipio del envío (vía MunicipalityResolver).
 *
 * El canal del cliente puede pedir la promesa o reservar "para mi envío" sin repetir la dirección; este
 * puerto deja que promise lea la coordenada ya geocodificada del envío. El dominio define QUÉ necesita
 * (lat/lng del envío); CÓMO se lee —con la consulta pública de `logistics/shipments`— lo resuelve
 * Infrastructure. Así promise no importa su dominio: lo conoce solo por este contrato y por primitivos.
 *
 * Tolerante: si el envío no existe o no tiene coordenada (dirección sin geocodificar), devuelve null.
 */
interface ShipmentLocator
{
    /**
     * Coordenada de la dirección de entrega del envío, o null si el envío no existe o no está geocodificado.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function locate(string $shipmentId): ?array;
}
