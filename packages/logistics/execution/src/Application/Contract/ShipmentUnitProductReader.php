<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Puerta de lectura para mostrar QUÉ PRODUCTO es cada bulto de una recepción (foto, nombre, SKU y la
 * posición "unidad N de M" / "pieza N de M"). Esos datos viven en shipments (shipment_units → shipment_lines).
 *
 * Solo lectura y en primitivos, igual que StopEnrichmentReader: no expone modelos de otro contexto. La
 * implementación resuelve el lote completo en UNA consulta. Los ids que no son bultos conocidos (p. ej. el id de
 * una etiqueta escaneada) simplemente no aparecen en el mapa.
 */
interface ShipmentUnitProductReader
{
    /**
     * @param  list<string>                                                                                                                                                                                $unitIds
     * @return array<string, array{sku: string|null, product_name: string|null, product_image_url: string|null, unit_index: int|null, unit_total: int|null, piece_index: int|null, piece_total: int|null}> mapa unit id => producto
     */
    public function forUnitIds(array $unitIds): array;
}
