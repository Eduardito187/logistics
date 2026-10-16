<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Puerta de lectura para ENRIQUECER las paradas de la ruta del chofer con datos del envío que NO viven en
 * Ejecución (teléfono del cliente, referencia de dirección y líneas del pedido viven en shipments).
 *
 * El contrato es de solo lectura y en primitivos: no expone agregados ni modelos de otro contexto, así la
 * Aplicación de Ejecución no se acopla al dominio de shipments. La implementación (Infrastructure) resuelve
 * los datos por lectura directa de las tablas, EN LOTE, para evitar una consulta por parada.
 */
interface StopEnrichmentReader
{
    /**
     * Resuelve, para un lote de envíos, el teléfono del cliente, la referencia de dirección y las líneas.
     *
     * @param  list<string>                                                                                                                                                            $shipmentIds ids de envío de las paradas de la ruta
     * @return array<string, array{customer_phone: string|null, address_reference: string|null, items: list<array{sku: string, name: string, quantity: int, image_url: string|null}>}>
     *                                                                                                                                                                                 mapa id de envío => datos; los envíos no encontrados simplemente no aparecen en el mapa
     */
    public function forShipmentIds(array $shipmentIds): array;
}
