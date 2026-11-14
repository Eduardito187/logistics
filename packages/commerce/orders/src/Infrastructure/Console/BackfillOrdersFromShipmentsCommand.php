<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\Console;

use Carbon\CarbonImmutable;
use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\FinancingPlan;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Commerce\Orders\Domain\ValueObject\OrderLine;
use Commerce\Orders\Domain\ValueObject\ShippingAddress;
use Commerce\Orders\Infrastructure\Persistence\Eloquent\Mapper\OrderMapper;
use Commerce\Orders\Infrastructure\Persistence\Eloquent\Model\OrderRecord;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Throwable;

/**
 * Reconstruye una orden de commerce (agregado Order, el canónico del OMS) por cada PEDIDO real que hoy
 * vive solo como envío(s) en `shipments` (entraron por seeders y nunca pasaron por el checkout). Así el
 * listado «Pedidos» del admin refleja el volumen real sin romper el roadmap marketplace (la orden sigue
 * siendo la fuente de verdad del comercio; ver ADR 018).
 *
 * Agrupación (un PEDIDO → una orden):
 *  - Envíos CON `order_reference`: todos los que comparten esa referencia son el mismo pedido
 *    (multishipping, un pedido repartido a varios destinos) → UNA orden. La clave del grupo es el
 *    `order_reference`.
 *  - Envíos SIN `order_reference`: cada envío es su propio pedido → UNA orden por envío. La clave es su
 *    `external_id` (si lo tiene) o, en su defecto, su id.
 *
 * Idempotente y reanudable: antes de crear se comprueba si ya existe una orden para esa clave (como `id`
 * de la orden —caso de los envíos nacidos del flujo real, cuyo `order_reference` ES el id de la orden— o
 * como `order_reference` de una orden ya backfilleada). Si existe, se SALTA. Reejecutarlo no duplica.
 *
 * Escala: recorre los envíos por lotes (`--chunk`) para soportar millones de registros. Tolerante: un
 * grupo con datos faltantes se loguea y se salta sin abortar el lote.
 *
 * Estado derivado del avance del envío (default razonable): los envíos solo existen porque el pedido ya
 * fue pagado en el flujo real, así que un envío recién recibido (RECEIVED) ⇒ orden `paid`; un envío que ya
 * avanzó (PLANNED/IN_EXECUTION/DELIVERED/FAILED) ⇒ orden `fulfilling`; si todos los envíos del grupo están
 * anulados ⇒ orden `cancelled`. En multishipping gana el envío más avanzado del grupo. Financiamiento:
 * contado (`cash`), el default más simple y válido. El origen queda marcado con `source='backfill'`.
 *
 * Por defecto solo SIMULA (sin `--force`): informa cuántas órdenes crearía sin escribir nada. Con `--force`
 * escribe de verdad.
 *
 * Texto visible en inglés (regla de consola); comentarios en español.
 */
final class BackfillOrdersFromShipmentsCommand extends Command
{
    protected $signature = 'commerce:orders:backfill-from-shipments
        {--force : Actually create the orders (without this flag it only simulates)}
        {--chunk=500 : How many shipments/references to process per batch}';

    protected $description = 'Backfill commerce orders from existing shipments so the orders list reflects the real volume (idempotent)';

    public function handle(): int
    {
        // Sin --force la corrida es una simulación: no escribe nada.
        $dryRun = $this->option('force') !== true;
        $chunk = $this->chunkSize();

        $created = 0;
        $existing = 0;
        $skipped = 0;

        // 1) Envíos CON referencia de pedido: cada referencia distinta es UN pedido (multishipping).
        $this->eachReference($chunk, function (string $reference) use ($dryRun, &$created, &$existing, &$skipped): void {
            $this->process(
                key: $reference,
                shipments: $this->shipmentsFor('order_reference', $reference),
                dryRun: $dryRun,
                created: $created,
                existing: $existing,
                skipped: $skipped,
            );
        });

        // 2) Envíos SIN referencia: cada envío es su propio pedido.
        ShipmentRecord::query()
            ->whereNull('order_reference')
            ->with('lines')
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $shipments) use ($dryRun, &$created, &$existing, &$skipped): void {
                /** @var Collection<int, ShipmentRecord> $shipments */
                foreach ($shipments as $shipment) {
                    $this->process(
                        key: $this->singletonKey($shipment),
                        shipments: new Collection([$shipment]),
                        dryRun: $dryRun,
                        created: $created,
                        existing: $existing,
                        skipped: $skipped,
                    );
                }
            });

        if ($dryRun) {
            $this->warn('Simulation (no --force): nothing was written.');
        }

        $this->line('Already present (skipped): '.$existing);
        $this->line('Skipped (incomplete data): '.$skipped);
        $this->line(($dryRun ? 'Would be created: ' : 'Created: ').$created);
        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Procesa un grupo (un pedido). Salta si ya hay una orden para la clave; si no, crea (o cuenta, en
     * dry-run). Tolerante: cualquier error de datos del grupo se loguea y se salta sin abortar el lote.
     *
     * @param Collection<int, ShipmentRecord> $shipments
     */
    private function process(string $key, Collection $shipments, bool $dryRun, int &$created, int &$existing, int &$skipped): void
    {
        if ($shipments->isEmpty()) {
            return;
        }

        if ($this->alreadyBackfilled($key)) {
            $existing++;

            return;
        }

        if ($dryRun) {
            // En simulación no se construye el agregado (no se detectan datos faltantes): solo se cuenta.
            $created++;

            return;
        }

        try {
            $this->createOrder($key, $shipments);
            $created++;
        } catch (Throwable $e) {
            $skipped++;
            $this->warn('Skipped order_reference "'.$key.'": '.$e->getMessage());
        }
    }

    /**
     * Construye el agregado Order reutilizando la vía de dominio (`Order::place`) y lo persiste con su
     * estado derivado del avance de los envíos. Los eventos de dominio NO se publican al outbox: el
     * backfill es un relleno histórico y reemitir `order_paid` recrearía los envíos (los que ya existen).
     *
     * @param Collection<int, ShipmentRecord> $shipments
     */
    private function createOrder(string $key, Collection $shipments): void
    {
        /** @var ShipmentRecord $first */
        $first = $shipments->first();

        $order = Order::place(
            OrderId::generate(),
            null,
            $this->addressFor($first),
            $this->linesFor($shipments),
            $this->currencyFor($shipments),
            null,
            FinancingPlan::Cash,
        );

        // Lleva el agregado al estado derivado por la vía de dominio (sin inventar estados a mano).
        $status = $this->deriveStatus($shipments);
        if ($status === 'cancelled') {
            $order->cancel('backfill', 'manual');
        } else {
            // paid y fulfilling arrancan por el pago (contado); fulfilling además marca el envío solicitado.
            $order->markPaid('backfill');
            if ($status === 'fulfilling') {
                $order->markFulfilling();
            }
        }

        // Se parte del shape tipado de OrderMapper::toRow() (mismas claves que el repo real) + las columnas
        // propias del backfill. No se anota `@var array<string, mixed>`: eso ensancharía el shape y haría que
        // updateOrCreate rechace las claves por no ser "model property" (todas lo son: source/order_reference
        // tras la migración, created_at/updated_at son timestamps del modelo).
        $attributes = (new OrderMapper)->toRow($order);
        $attributes['source'] = 'backfill';
        $attributes['order_reference'] = $key;
        // placed_at = fecha de creación del pedido = la del envío más antiguo del grupo.
        $attributes['created_at'] = $this->placedAt($shipments);
        $attributes['updated_at'] = CarbonImmutable::now();

        OrderRecord::query()->updateOrCreate(['id' => $attributes['id']], $attributes);
    }

    /**
     * ¿Ya hay una orden para esta clave? Como id de la orden (envíos del flujo real: su `order_reference`
     * es el id de la orden) o como `order_reference` de una orden ya backfilleada.
     */
    private function alreadyBackfilled(string $key): bool
    {
        return OrderRecord::query()
            ->where('id', $key)
            ->orWhere('order_reference', $key)
            ->exists();
    }

    /**
     * Líneas de la orden a partir de las líneas de todos los envíos del grupo (producto = nombre + SKU +
     * cantidad; precio del envío si existe, si no 0). Nombre vacío → se usa el SKU (nunca nombre vacío).
     *
     * @param  Collection<int, ShipmentRecord> $shipments
     * @return list<OrderLine>
     */
    private function linesFor(Collection $shipments): array
    {
        $lines = [];
        foreach ($shipments as $shipment) {
            foreach ($shipment->lines as $line) {
                assert($line instanceof ShipmentLineRecord);
                $sku = trim((string) $line->sku);
                if ($sku === '') {
                    continue;
                }
                $name = trim((string) $line->product_name);
                $priceCents = $line->unit_price !== null ? (int) round(((float) $line->unit_price) * 100) : 0;
                $quantity = max(1, (int) $line->quantity);

                $lines[] = OrderLine::of($sku, $name !== '' ? $name : $sku, $priceCents, $quantity);
            }
        }

        return $lines;
    }

    /**
     * Datos de entrega del pedido tomados del envío (nombre/teléfono/email/dirección). El documento del
     * cliente no tiene lugar en el agregado Order, así que no se traslada. Fallbacks mínimos para no perder
     * el pedido si el envío viene con el contacto incompleto.
     */
    private function addressFor(ShipmentRecord $shipment): ShippingAddress
    {
        $name = trim((string) $shipment->customer_name);
        $addressText = trim((string) $shipment->address_text);

        return ShippingAddress::of(
            $name !== '' ? $name : 'Unknown customer',
            $addressText !== '' ? $addressText : 'N/A',
            $shipment->address_reference,
            $shipment->customer_phone,
            $shipment->customer_email,
        );
    }

    /**
     * Moneda del pedido: la de la primera línea con moneda, o BOB por defecto.
     *
     * @param Collection<int, ShipmentRecord> $shipments
     */
    private function currencyFor(Collection $shipments): string
    {
        foreach ($shipments as $shipment) {
            foreach ($shipment->lines as $line) {
                assert($line instanceof ShipmentLineRecord);
                $currency = strtoupper(trim((string) $line->unit_price_currency));
                if ($currency !== '') {
                    return $currency;
                }
            }
        }

        return 'BOB';
    }

    /**
     * Estado de la orden derivado del avance de los envíos del grupo (gana el más avanzado): RECEIVED ⇒
     * paid; PLANNED/IN_EXECUTION/DELIVERED/FAILED ⇒ fulfilling; todos anulados ⇒ cancelled.
     *
     * @param Collection<int, ShipmentRecord> $shipments
     */
    private function deriveStatus(Collection $shipments): string
    {
        $anyActive = false;
        $anyAdvanced = false;

        foreach ($shipments as $shipment) {
            $status = ShipmentStatus::tryFromAnyCase((string) $shipment->status);
            if ($status === null || $status === ShipmentStatus::Cancelled) {
                continue;
            }

            $anyActive = true;
            // Más allá de RECEIVED (rank ≥ 1) o un intento fallido ⇒ el pedido ya está en fulfillment.
            $rank = $status->progressRank();
            if ($status === ShipmentStatus::Failed || ($rank !== null && $rank >= 1)) {
                $anyAdvanced = true;
            }
        }

        if (!$anyActive) {
            return 'cancelled';
        }

        return $anyAdvanced ? 'fulfilling' : 'paid';
    }

    /**
     * placed_at del pedido = fecha del envío más antiguo del grupo (o ahora si ninguno la tiene).
     *
     * @param Collection<int, ShipmentRecord> $shipments
     */
    private function placedAt(Collection $shipments): CarbonImmutable
    {
        $earliest = null;
        foreach ($shipments as $shipment) {
            // `created_at` es un timestamp de Eloquent (no está en el @property de ShipmentRecord); se lee por
            // getAttribute y se estrecha a string|DateTimeInterface antes de parsear (evita mixed en parse).
            $createdAt = $shipment->getAttribute('created_at');
            if (!is_string($createdAt) && !$createdAt instanceof DateTimeInterface) {
                continue;
            }
            $candidate = CarbonImmutable::parse($createdAt);
            if ($earliest === null || $candidate->lessThan($earliest)) {
                $earliest = $candidate;
            }
        }

        return $earliest ?? CarbonImmutable::now();
    }

    /** Clave de un envío sin referencia de pedido: su `external_id`, o su id si viniera vacío. */
    private function singletonKey(ShipmentRecord $shipment): string
    {
        $externalId = trim((string) $shipment->external_id);

        return $externalId !== '' ? $externalId : (string) $shipment->getKey();
    }

    /**
     * Carga los envíos de un grupo por una columna/valor, con sus líneas, ordenados por fecha de creación.
     *
     * @return Collection<int, ShipmentRecord>
     */
    private function shipmentsFor(string $column, string $value): Collection
    {
        return ShipmentRecord::query()
            ->where($column, $value)
            ->with('lines')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Recorre por lotes las referencias de pedido DISTINTAS de los envíos con `order_reference`, invocando
     * el callback con cada referencia.
     *
     * @param callable(string): void $callback
     */
    private function eachReference(int $chunk, callable $callback): void
    {
        // DISTINCT sobre `order_reference`: una fila por pedido. El paginado por offset de `chunk` es estable
        // porque el backfill escribe en `commerce_orders`, no en `shipments` (el conjunto leído no cambia).
        ShipmentRecord::query()
            ->whereNotNull('order_reference')
            ->select('order_reference')
            ->distinct()
            ->orderBy('order_reference')
            ->chunk($chunk, function (Collection $rows) use ($callback): void {
                /** @var Collection<int, ShipmentRecord> $rows */
                foreach ($rows as $row) {
                    $reference = (string) $row->order_reference;
                    if ($reference !== '') {
                        $callback($reference);
                    }
                }
            });
    }

    /** Tamaño de lote saneado (mínimo 1), tolerante a un valor no numérico en la opción. */
    private function chunkSize(): int
    {
        $raw = $this->option('chunk');

        return is_numeric($raw) ? max(1, (int) $raw) : 500;
    }
}
