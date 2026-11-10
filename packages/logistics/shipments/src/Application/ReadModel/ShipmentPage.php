<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

use Logistics\Shared\Application\ReadModel\Page;

/**
 * Página de envíos con paginación por CURSOR (keyset), para colecciones que crecen sin techo.
 *
 * A diferencia del modo por offset ({@see Page}, que trae `total`
 * y páginas numeradas), el keyset no cuenta el total ni escanea-y-descarta: avanza por el último `id`
 * visto (UUID v7, orden temporal), así la página N cuesta lo mismo que la 1.
 *
 *  - `items`: envíos de esta página (ya como ShipmentView).
 *  - `nextCursor`: id del último envío de la página para pedir la siguiente (null = no hay más).
 */
final readonly class ShipmentPage
{
    /**
     * @param list<ShipmentView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}
}
