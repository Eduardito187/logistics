<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Búsqueda transversal del command palette (⌘K): resuelve texto libre contra varias entidades-eje por
 * lectura directa (ilike, Postgres), en SOLO lectura y tolerante (cada fuente en su try/catch; si falta
 * una tabla/columna se omite). Devuelve ítems primitivos con una referencia LEGIBLE —placa, nombre,
 * código, nº de pedido— NUNCA el UUID (que viaja aparte como `id` para armar el enlace). Presentación
 * resuelve la URL por `type`. El alcance por rol lo aplica quien llama (hoy: oficina = admin/supervisor).
 */
final class GlobalSearch
{
    /** Máximo de resultados por grupo. */
    private const PER_GROUP = 5;

    /**
     * @return list<array{type:string,id:string,group:string,icon:string,title:string,subtitle:string}>
     */
    public function search(string $query): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $out = [];

        // Vehículos — por placa, marca, modelo o VIN.
        $this->source($out, static function () use ($like): array {
            return DB::table('fleet_vehicles')
                ->where(function ($w) use ($like): void {
                    $w->whereRaw('plate ilike ?', [$like])
                        ->orWhereRaw('make ilike ?', [$like])
                        ->orWhereRaw('model ilike ?', [$like])
                        ->orWhereRaw('vin ilike ?', [$like]);
                })
                ->orderBy('plate')->limit(self::PER_GROUP)
                ->get(['id', 'plate', 'make', 'model', 'status'])
                ->map(static fn ($r): array => [
                    'type' => 'vehicle', 'id' => (string) $r->id, 'group' => 'Vehículos', 'icon' => 'VH',
                    'title' => (string) $r->plate,
                    'subtitle' => trim(trim((string) $r->make.' '.(string) $r->model).' · '.self::statusLabel((string) $r->status), ' ·'),
                ])->all();
        });

        // Choferes — por nombre, licencia, documento o teléfono.
        $this->source($out, static function () use ($like): array {
            return DB::table('fleet_drivers')
                ->where(function ($w) use ($like): void {
                    $w->whereRaw('name ilike ?', [$like])
                        ->orWhereRaw('license_number ilike ?', [$like])
                        ->orWhereRaw('national_id ilike ?', [$like])
                        ->orWhereRaw('phone_primary ilike ?', [$like]);
                })
                ->orderBy('name')->limit(self::PER_GROUP)
                ->get(['id', 'name', 'license_number', 'active'])
                ->map(static fn ($r): array => [
                    'type' => 'driver', 'id' => (string) $r->id, 'group' => 'Choferes', 'icon' => 'CH',
                    'title' => (string) $r->name,
                    'subtitle' => trim('Lic. '.(string) $r->license_number).' · '.($r->active ? 'Activo' : 'Inactivo'),
                ])->all();
        });

        // Envíos — por nº de pedido (external_id), cliente o teléfono.
        $this->source($out, static function () use ($like): array {
            return DB::table('shipments')
                ->where(function ($w) use ($like): void {
                    $w->whereRaw('external_id ilike ?', [$like])
                        ->orWhereRaw('customer_name ilike ?', [$like])
                        ->orWhereRaw('customer_phone ilike ?', [$like]);
                })
                ->orderByDesc('created_at')->limit(self::PER_GROUP)
                ->get(['id', 'external_id', 'customer_name', 'status'])
                ->map(static fn ($r): array => [
                    'type' => 'shipment', 'id' => (string) $r->id, 'group' => 'Envíos', 'icon' => 'EN',
                    'title' => (string) ($r->external_id ?: $r->customer_name ?: 'Envío'),
                    'subtitle' => trim((string) $r->customer_name.' · '.self::statusLabel((string) $r->status), ' ·'),
                ])->all();
        });

        // Nodos de red — por código o nombre.
        $this->source($out, static function () use ($like): array {
            return DB::table('network_nodes')
                ->where(function ($w) use ($like): void {
                    $w->whereRaw('code ilike ?', [$like])->orWhereRaw('name ilike ?', [$like]);
                })
                ->orderBy('code')->limit(self::PER_GROUP)
                ->get(['id', 'code', 'name', 'type'])
                ->map(static fn ($r): array => [
                    'type' => 'node', 'id' => (string) $r->id, 'group' => 'Nodos', 'icon' => 'ND',
                    'title' => (string) ($r->name ?: $r->code),
                    'subtitle' => trim((string) $r->code.' · '.(string) $r->type, ' ·'),
                ])->all();
        });

        // Transportistas — por nombre o NIT.
        $this->source($out, static function () use ($like): array {
            return DB::table('carriers')
                ->where(function ($w) use ($like): void {
                    $w->whereRaw('name ilike ?', [$like])->orWhereRaw('tax_id ilike ?', [$like]);
                })
                ->orderBy('name')->limit(self::PER_GROUP)
                ->get(['id', 'name', 'tax_id', 'status'])
                ->map(static fn ($r): array => [
                    'type' => 'carrier', 'id' => (string) $r->id, 'group' => 'Transportistas', 'icon' => 'TR',
                    'title' => (string) $r->name,
                    'subtitle' => trim('NIT '.(string) $r->tax_id).' · '.self::statusLabel((string) $r->status),
                ])->all();
        });

        // Clientes — por nombre (derivados de los envíos; la clave del cliente es su nombre). El `id` es el
        // propio nombre, que es lo que consume la ficha 360 del cliente (CustomerProfile::urlFor).
        $this->source($out, static function () use ($like): array {
            return DB::table('shipments')
                ->whereRaw('customer_name ilike ?', [$like])
                ->groupBy('customer_name')
                ->orderByRaw('max(created_at) desc')
                ->limit(self::PER_GROUP)
                ->get(['customer_name', DB::raw('count(*) as n')])
                ->map(static fn ($r): array => [
                    'type' => 'customer', 'id' => (string) $r->customer_name, 'group' => 'Clientes', 'icon' => 'CL',
                    'title' => (string) $r->customer_name,
                    'subtitle' => ((int) $r->n).' '.((int) $r->n === 1 ? 'envío' : 'envíos'),
                ])->all();
        });

        // Pedidos comerciales (OMS) — por nombre de cliente en ship_to. El título es el cliente (no hay nº
        // de pedido legible); el id (UUID) viaja aparte para armar el enlace al hub del pedido.
        $this->source($out, static function () use ($like): array {
            return DB::table('commerce_orders')
                ->whereRaw("ship_to->>'customer_name' ilike ?", [$like])
                ->orderByDesc('created_at')->limit(self::PER_GROUP)
                ->get(['id', 'status', 'ship_to'])
                ->map(static function ($r): array {
                    $shipTo = is_string($r->ship_to) ? json_decode($r->ship_to, true) : [];
                    $name = is_array($shipTo) && isset($shipTo['customer_name']) && is_string($shipTo['customer_name'])
                        ? $shipTo['customer_name']
                        : 'Pedido';

                    return [
                        'type' => 'order', 'id' => (string) $r->id, 'group' => 'Pedidos', 'icon' => 'PD',
                        'title' => $name,
                        'subtitle' => self::statusLabel((string) $r->status),
                    ];
                })->all();
        });

        return $out;
    }

    /**
     * Ejecuta una fuente de búsqueda tolerante: agrega sus filas al acumulador; si lanza (tabla/columna
     * ausente), se omite esa fuente sin romper el palette.
     *
     * @param list<array{type:string,id:string,group:string,icon:string,title:string,subtitle:string}>                 $out
     * @param \Closure():array<int,array{type:string,id:string,group:string,icon:string,title:string,subtitle:string}> $resolver
     */
    private function source(array &$out, \Closure $resolver): void
    {
        try {
            foreach ($resolver() as $row) {
                $out[] = $row;
            }
        } catch (Throwable) {
            // fuente no disponible en este entorno — se omite.
        }
    }

    /** Traduce un código de estado a una etiqueta corta legible (fallback: el propio código en minúsculas). */
    private static function statusLabel(string $status): string
    {
        return match (strtolower($status)) {
            'in_service', 'available', 'active' => 'En servicio',
            'maintenance' => 'En taller',
            'inactive', 'suspended' => 'Inactivo',
            'delivered' => 'Entregado',
            'in_transit' => 'En tránsito',
            'failed' => 'Fallido',
            'received' => 'Recibido',
            'planned' => 'Planificado',
            default => $status !== '' ? strtolower($status) : '—',
        };
    }
}
