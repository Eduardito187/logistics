<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\ReadSide;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Tracking\Application\Contract\ReturnCoverageReader;

/**
 * Cruza por el query builder, en solo lectura, las líneas de los envíos del pedido (`shipment_lines`) con las
 * líneas de sus devoluciones (`return_lines`). No acopla el seguimiento al dominio de shipments ni de
 * returns (mismo patrón que {@see DbShipmentOrderReferenceReader}).
 *
 * La referencia de un envío de retiro es `orderId#n:nodeId`: se consideran tanto la referencia exacta como
 * sus variantes con sufijo «#n:».
 */
final readonly class DbReturnCoverageReader implements ReturnCoverageReader
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function coverage(string $orderReference): array
    {
        $reference = trim($orderReference);
        if ($reference === '') {
            return [];
        }

        $db = $this->connections->connection();
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $reference).'#n:%';

        $delivered = $db->table('shipment_lines as l')
            ->join('shipments as s', 's.id', '=', 'l.shipment_id')
            ->where('s.status', '<>', 'CANCELLED')
            ->where(static function ($query) use ($reference, $like): void {
                $query->where('s.order_reference', $reference)
                    ->orWhere('s.external_id', $reference)
                    ->orWhere('s.external_id', 'like', $like);
            })
            ->groupBy('l.sku')
            ->selectRaw('l.sku as sku, sum(l.quantity) as quantity')
            ->get();

        $coverage = [];
        foreach ($delivered as $row) {
            $coverage[(string) $row->sku] = ['delivered' => (int) $row->quantity, 'returned' => 0];
        }

        if ($coverage === []) {
            return [];
        }

        $returned = $db->table('return_lines as rl')
            ->join('returns as r', 'r.id', '=', 'rl.return_id')
            ->where(static function ($query) use ($reference, $like): void {
                $query->where('r.original_reference', $reference)
                    ->orWhere('r.original_reference', 'like', $like);
            })
            ->groupBy('rl.sku')
            ->selectRaw('rl.sku as sku, sum(rl.quantity) as quantity')
            ->get();

        foreach ($returned as $row) {
            $sku = (string) $row->sku;
            if (isset($coverage[$sku])) {
                $coverage[$sku]['returned'] = min($coverage[$sku]['delivered'], (int) $row->quantity);
            }
        }

        return $coverage;
    }

    public function isFullyReturned(string $orderReference): bool
    {
        $coverage = $this->coverage($orderReference);

        foreach ($coverage as $line) {
            if ($line['returned'] < $line['delivered']) {
                return false;
            }
        }

        return true;
    }
}
