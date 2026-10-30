<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Contract;

/**
 * Puerto de lectura (Application) para exponer la DISPONIBILIDAD (available-to-promise) de un producto
 * del catálogo a partir de su `canonical_sku`.
 *
 * El dato físico vive en el contexto `inventory`; este contrato lo define Catalog y lo implementa su
 * capa de Infrastructure con lectura cruzada read-only (sin acoplar el dominio de inventory). Así la
 * Presentation pide disponibilidad por SKU sin conocer la tabla ni el dominio del otro contexto.
 */
interface ProductAvailabilityReader
{
    /**
     * Unidades disponibles para prometer del SKU canónico (agregado entre lotes/almacenes/dueños).
     * Si inventory no está desplegado o el SKU no tiene stock, devuelve 0 (nunca rompe la lectura).
     */
    public function availableForSku(string $canonicalSku): int;

    /**
     * Versión en lote para listados: resuelve varios SKUs en una sola consulta (evita N+1).
     * Siempre devuelve una entrada por cada SKU solicitado (los sin stock quedan en 0).
     *
     * @param  list<string>       $canonicalSkus
     * @return array<string, int> mapa canonical_sku => unidades disponibles
     */
    public function availableForSkus(array $canonicalSkus): array;
}
