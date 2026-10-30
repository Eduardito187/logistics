<?php

declare(strict_types=1);

namespace Commerce\Catalog\Infrastructure\ReadModel;

use Commerce\Catalog\Application\Contract\ProductAvailabilityReader;
use Illuminate\Database\Connection;

/**
 * Lee la disponibilidad (available-to-promise) desde la tabla `inventory_lots` del contexto `inventory`,
 * por `sku` (= `canonical_sku` del producto del catálogo).
 *
 * LECTURA CRUZADA READ-ONLY: usa el query builder sobre la tabla del otro contexto (nunca sus modelos
 * Eloquent ni su dominio), igual que `Logistics\Execution\...\DbStopEnrichmentReader`. Así Catalog NO se
 * acopla al dominio de inventory: solo observa columnas estables. El ATP es `SUM(available_quantity)`
 * agregado entre todos los lotes del SKU (todos los almacenes/dueños), idéntico a como lo calcula
 * `EloquentStockQueryStore::availableToPromise` de inventory.
 *
 * Si la tabla no existe (inventory no desplegado todavía), degrada a 0 y no rompe la lectura del catálogo.
 */
final readonly class InventoryProductAvailabilityReader implements ProductAvailabilityReader
{
    /** Tabla de lotes del contexto inventory (fuente del stock físico). */
    private const LOTS_TABLE = 'inventory_lots';

    public function __construct(
        private Connection $connection,
    ) {}

    public function availableForSku(string $canonicalSku): int
    {
        $sku = trim($canonicalSku);
        if ($sku === '' || !$this->inventoryAvailable()) {
            return 0;
        }

        return (int) $this->connection->table(self::LOTS_TABLE)
            ->where('sku', $sku)
            ->sum('available_quantity');
    }

    public function availableForSkus(array $canonicalSkus): array
    {
        // Normaliza: recorta, descarta vacíos y deduplica, preservando el orden de entrada.
        $skus = array_values(array_unique(array_filter(
            array_map(static fn (string $sku): string => trim($sku), $canonicalSkus),
            static fn (string $sku): bool => $sku !== '',
        )));

        // Toda petición válida arranca en 0; así nunca falta una entrada aunque el SKU no tenga stock.
        $result = array_fill_keys($skus, 0);
        if ($skus === [] || !$this->inventoryAvailable()) {
            return $result;
        }

        // Una sola consulta agregada por SKU (evita N+1 en listados).
        $rows = $this->connection->table(self::LOTS_TABLE)
            ->whereIn('sku', $skus)
            ->groupBy('sku')
            ->get(['sku', $this->connection->raw('SUM(available_quantity) AS available')]);

        foreach ($rows as $row) {
            $result[(string) $row->sku] = (int) $row->available;
        }

        return $result;
    }

    /**
     * ¿Está desplegada la tabla de inventory en esta conexión? Si no, degradamos a 0 sin romper.
     */
    private function inventoryAvailable(): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable(self::LOTS_TABLE);
    }
}
