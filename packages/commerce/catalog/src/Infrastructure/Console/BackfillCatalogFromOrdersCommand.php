<?php

declare(strict_types=1);

namespace Commerce\Catalog\Infrastructure\Console;

use Commerce\Catalog\Application\Command\DraftProduct\DraftProduct;
use Commerce\Catalog\Application\Command\PublishProduct\PublishProduct;
use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Domain\ValueObject\Sku;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puebla el catálogo maestro (agregado Product) a partir de los productos DISTINTOS que ya aparecen
 * inline en las órdenes (`commerce_orders.lines` jsonb) y en los envíos (`shipment_lines`).
 *
 * Nació porque el catálogo quedó vacío mientras llegaban órdenes/envíos que llevan el producto inline
 * (sku + nombre) pero nunca dieron de alta el producto maestro. Reúne los pares (sku, product_name)
 * distintos de ambas fuentes (lectura cruzada read-only) y, por cada SKU que todavía NO exista como
 * producto, despacha {@see DraftProduct} por el CommandBus.
 *
 * Idempotente: el alta solo ocurre para SKUs ausentes (guardia por SKU canónico vía repositorio), así
 * que re-correrlo no duplica. Sin SKU no se crea nada (no hay clave de catálogo). Nombre vacío → se usa
 * el propio SKU como nombre (regla del repo: nunca nombre nulo/vacío).
 *
 * Por defecto solo simula (`--dry-run` implícito al no pasar `--force`): informa cuántos crearía sin
 * escribir. Con `--force` ejecuta de verdad. Con `--publish` además publica cada producto recién creado.
 *
 * Texto visible en inglés (regla de consola); comentarios en español.
 */
final class BackfillCatalogFromOrdersCommand extends Command
{
    protected $signature = 'commerce:catalog:backfill-from-orders
        {--force : Actually create the products (without this flag it only simulates)}
        {--publish : Also publish each product created (default leaves them in draft)}';

    protected $description = 'Backfill the product catalog from distinct products found in orders and shipments (idempotent)';

    public function handle(CommandBus $commands, ProductRepository $products): int
    {
        // Sin --force la corrida es una simulación: no escribe nada.
        $dryRun = $this->option('force') !== true;
        $publish = $this->option('publish') === true;

        // Pares (sku → nombre) distintos de ambas fuentes, agrupados por SKU canónico (mayúsculas).
        $candidates = [];
        foreach ($this->fromOrders() as $row) {
            $this->remember($candidates, $row->sku, $row->product_name);
        }
        foreach ($this->fromShipments() as $row) {
            $this->remember($candidates, $row->sku, $row->product_name);
        }

        $found = count($candidates);
        $created = 0;
        $existing = 0;

        foreach ($candidates as $sku => $name) {
            if ($products->findByCanonicalSku(Sku::of($sku)) !== null) {
                $existing++;

                continue;
            }

            if ($dryRun) {
                $created++;

                continue;
            }

            $commands->dispatch(new DraftProduct(name: $name, canonicalSku: $sku));

            if ($publish) {
                // El alta no devuelve el id; se recupera por SKU para publicarlo.
                $product = $products->findByCanonicalSku(Sku::of($sku));
                if ($product !== null) {
                    $commands->dispatch(new PublishProduct($product->id()->toString()));
                }
            }

            $created++;
        }

        if ($dryRun) {
            $this->warn('Simulation (no --force): nothing was written.');
        }

        $this->line('Distinct products found in orders/shipments: '.$found);
        $this->line('Already present in the catalog: '.$existing);
        $this->line(($dryRun ? 'Would be created: ' : 'Created: ').$created);
        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Registra un candidato en el mapa SKU canónico → nombre. El primer nombre no vacío gana; si nunca
     * llega un nombre, el fallback es el propio SKU. Sin SKU no hay producto de catálogo que crear.
     *
     * @param array<string, string> $candidates
     */
    private function remember(array &$candidates, ?string $sku, ?string $name): void
    {
        $normalizedSku = strtoupper(trim((string) $sku));
        if ($normalizedSku === '') {
            return;
        }

        $name = trim((string) $name);
        $current = $candidates[$normalizedSku] ?? null;

        if ($current === null || $current === $normalizedSku) {
            $candidates[$normalizedSku] = $name !== '' ? $name : $normalizedSku;
        }
    }

    /**
     * Pares distintos (sku, product_name) de las líneas inline de las órdenes. Lectura cruzada read-only:
     * `jsonb_array_elements` desagrega el jsonb `lines` y se extraen los campos `sku`/`product_name`.
     *
     * @return Collection<int, object{sku: string|null, product_name: string|null}>
     */
    private function fromOrders(): Collection
    {
        /** @var Collection<int, object{sku: string|null, product_name: string|null}> $rows */
        $rows = DB::query()
            ->fromRaw('commerce_orders, jsonb_array_elements(lines) AS line')
            ->selectRaw("line->>'sku' AS sku, line->>'product_name' AS product_name")
            ->distinct()
            ->get();

        return $rows;
    }

    /**
     * Pares distintos (sku, product_name) de las líneas de los envíos. Lectura cruzada read-only.
     *
     * @return Collection<int, object{sku: string|null, product_name: string|null}>
     */
    private function fromShipments(): Collection
    {
        /** @var Collection<int, object{sku: string|null, product_name: string|null}> $rows */
        $rows = DB::table('shipment_lines')
            ->select('sku', 'product_name')
            ->distinct()
            ->get();

        return $rows;
    }
}
