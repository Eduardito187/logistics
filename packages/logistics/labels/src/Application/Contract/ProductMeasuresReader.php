<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Contract;

use Logistics\Labels\Domain\ValueObject\ProductMeasures;

/**
 * Puerto de lectura de las medidas físicas de un producto por SKU, para elegir el tamaño de la etiqueta.
 *
 * Etiquetas NO conoce el package de catálogo: la implementación de infraestructura lee las tablas del
 * productor (commerce) en SOLO-LECTURA y de forma TOLERANTE (referencia cross-context por datos, no por
 * objeto de dominio; mismo patrón que el enricher). Devuelve null cuando el producto no existe, no tiene
 * medidas, o el catálogo todavía no está desplegado: en todos esos casos la elección degrada al perfil
 * Standard. Nunca debe lanzar por ausencia de datos.
 */
interface ProductMeasuresReader
{
    public function measuresFor(string $sku): ?ProductMeasures;
}
