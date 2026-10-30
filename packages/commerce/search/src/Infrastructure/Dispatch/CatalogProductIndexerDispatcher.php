<?php

declare(strict_types=1);

namespace Commerce\Search\Infrastructure\Dispatch;

use Commerce\Search\Infrastructure\Index\ProductSearchIndexer;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Puente catalog → search: mantiene la proyección de búsqueda al día de forma incremental.
 *
 * Adaptador de INTEGRACIÓN (capa Infrastructure, anti-corruption): consume los eventos del catálogo por su
 * NOMBRE (`catalog.product_drafted` | `catalog.product_published` | `catalog.product_archived`) y reindexa
 * el producto afectado leyendo su fila actual del catálogo en modo read-only (vía {@see ProductSearchIndexer}).
 * NUNCA hace `use` de una clase de `commerce/catalog` ni depende de él por composer: así `search` sigue
 * siendo extraíble a microservicio.
 *
 * Idempotencia (el outbox reentrega at-least-once): el indexado es un upsert por `product_id`, de modo que
 * un reintento del lote no duplica ni corrompe la entrada. Si el producto ya no existe en el catálogo, se
 * elimina de la proyección.
 */
final readonly class CatalogProductIndexerDispatcher implements MessageDispatcher
{
    /** Eventos del catálogo que afectan el índice de búsqueda. */
    private const HANDLED = [
        'catalog.product_drafted',
        'catalog.product_published',
        'catalog.product_archived',
    ];

    public function __construct(
        private ProductSearchIndexer $indexer,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if (!in_array($message->eventName(), self::HANDLED, true)) {
            return;
        }

        // El id del producto es el aggregateId del evento del catálogo. El indexador relee la fila
        // autoritativa y recalcula el tsvector (o la elimina si el producto desapareció).
        $this->indexer->indexProduct($message->aggregateId());
    }
}
