<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\ReadModel\CatalogMeasurements;

/**
 * Puerto de LECTURA cruzada hacia el catálogo (commerce/catalog) para la foto de medidas de las líneas
 * (Roadmap 02, Fase 2.1). Shipments no depende del dominio del catálogo: la implementación de
 * Infrastructure lee sus tablas en solo lectura y devuelve primitivos.
 *
 * Contrato de tolerancia: NUNCA lanza. Si el catálogo no está disponible o el SKU no existe, el SKU
 * simplemente no aparece en el resultado y el resolutor cae al default global.
 */
interface CatalogMeasurementsReader
{
    /**
     * Medidas del producto y de su categoría para cada SKU encontrado, indexadas por la clave
     * normalizada del SKU ({@see CatalogMeasurements::keyOf()}).
     *
     * @param  list<string>                       $skus
     * @return array<string, CatalogMeasurements>
     */
    public function forSkus(array $skus): array;
}
