<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\ReadModel;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Logistics\Labels\Application\Contract\PrinterReader;
use Logistics\Labels\Application\ReadModel\PrinterView;

/**
 * Lectura SQL directa del registro de impresoras, con el nombre del nodo (sede) resuelto por LEFT JOIN
 * a `network_nodes` (lectura cross-context tolerante: si el nodo ya no existe, `node_name` queda null).
 */
final class EloquentPrinterReader implements PrinterReader
{
    public function available(?string $nodeId, bool $allNodes): array
    {
        $rows = DB::table('label_printers as p')
            ->leftJoin('network_nodes as n', 'n.id', '=', 'p.node_id')
            ->where('p.active', true)
            ->when(!$allNodes, static function (Builder $query) use ($nodeId): void {
                // Las de la sede del usuario + las compartidas (sin sede).
                $query->where(static function (Builder $scope) use ($nodeId): void {
                    $scope->whereNull('p.node_id');
                    if ($nodeId !== null && $nodeId !== '') {
                        $scope->orWhere('p.node_id', $nodeId);
                    }
                });
            })
            ->orderBy('p.name')
            ->orderBy('p.id')
            ->get(['p.id', 'p.name', 'p.model', 'p.host', 'p.port', 'p.language', 'p.node_id', 'n.name as node_name', 'p.location']);

        $views = [];
        foreach ($rows as $row) {
            $views[] = new PrinterView(
                id: (string) $row->id,
                name: (string) $row->name,
                model: $row->model !== null ? (string) $row->model : null,
                host: (string) $row->host,
                port: (int) $row->port,
                language: (string) $row->language,
                nodeId: $row->node_id !== null ? (string) $row->node_id : null,
                nodeName: $row->node_name !== null ? (string) $row->node_name : null,
                location: $row->location !== null ? (string) $row->location : null,
            );
        }

        return $views;
    }
}
