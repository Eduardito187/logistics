<?php

declare(strict_types=1);

namespace Commerce\Search\Infrastructure\Console;

use Commerce\Search\Infrastructure\Index\ProductSearchIndexer;
use Illuminate\Console\Command;

/**
 * Reconstruye la proyección de búsqueda `commerce_product_search` desde el catálogo (`commerce_products`).
 *
 * Operación de mantenimiento del read-side: idempotente y segura de correr cuantas veces haga falta
 * (vacía y repuebla la proyección). El indexado incremental normal lo hace el dispatcher de outbox que
 * reacciona a `catalog.product_*`; este comando sirve para el arranque, una recarga masiva o una
 * reparación del índice.
 *
 * Texto visible en inglés (regla de consola); comentarios en español.
 */
final class ReindexProductSearchCommand extends Command
{
    protected $signature = 'commerce:search:reindex {--chunk=1000 : Batch size when the driver has no native FTS}';

    protected $description = 'Rebuild the product search projection (commerce_product_search) from the catalog.';

    public function handle(ProductSearchIndexer $indexer): int
    {
        $chunkOption = $this->option('chunk');
        $chunk = is_numeric($chunkOption) ? max(1, (int) $chunkOption) : 1000;

        $this->info('Rebuilding product search index…');

        $indexed = $indexer->reindexAll($chunk);

        $this->info("Product search index rebuilt: {$indexed} product(s) indexed.");

        return self::SUCCESS;
    }
}
