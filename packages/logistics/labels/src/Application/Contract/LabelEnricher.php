<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Contract;

use Logistics\Labels\Application\ReadModel\EmittedLabelView;

/**
 * Puerto de ENRIQUECIMIENTO de etiquetas: resuelve, en LECTURA, los datos humanos (producto, origen,
 * destino) que no viven en el dominio de etiquetas sino en otros contextos (catálogo, envíos, red).
 *
 * El dominio de labels NO conoce esos contextos (ADR 004): el enriquecimiento ocurre en el borde de
 * lectura, tolerante (si un contexto no está disponible, degrada a null), sin acoplar el dominio. La
 * implementación (Infrastructure) lee las tablas ajenas en solo-lectura.
 */
interface LabelEnricher
{
    /**
     * Devuelve las vistas con producto/origen/destino resueltos a nombres humanos (nunca uuid).
     *
     * @param  list<EmittedLabelView> $views
     * @return list<EmittedLabelView>
     */
    public function enrich(string $orderNumber, array $views): array;
}
