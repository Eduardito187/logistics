<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Contract;

use Logistics\Labels\Application\ReadModel\DispatchLinesView;

/**
 * Puerto de lectura de las LÍNEAS DE DESPACHO de una orden (pantalla de etiquetado por producto): compone,
 * en SOLO lectura y cross-context, el producto (catálogo/envío), el destino (envío/red), la autorización
 * (plan publicado) y el estado de etiqueta (labels), sin acoplar el dominio de labels. La implementación
 * (Infrastructure) lee las tablas ajenas en solo-lectura y es tolerante (degrada a null/[] si falta algo).
 */
interface DispatchLinesReader
{
    public function read(string $orderNumber): DispatchLinesView;
}
