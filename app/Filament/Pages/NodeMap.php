<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Mapa de todos los nodos de la red sobre OpenStreetMap (Leaflet).
 *
 * Plotea cada nodo de `network_nodes` como marcador según sus coordenadas lat/lng reales, con popup
 * (code, name, type, status) y auto-zoom a todos los nodos. Mismo patrón de teselas con fallback que
 * el resto de mapas del admin (window.DismacMaps.tileLayer + config('maps.*')). Los mapas del
 * proyecto usan OSM, sin API key.
 */
final class NodeMap extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.node-map';

    public static function getNavigationGroup(): string
    {
        return __('Network');
    }

    public static function getNavigationLabel(): string
    {
        return __('Nodes map');
    }

    public function getTitle(): string
    {
        return __('Nodes map');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'nodes' => $this->nodes(),
        ];
    }

    /**
     * Carga todos los nodos de la red con sus coordenadas, como lista de arrays listos para el mapa.
     *
     * @return list<array{id: string, code: string, name: string, type: string, lat: float, lng: float, status: string}>
     */
    private function nodes(): array
    {
        return array_values(DB::table('network_nodes')
            ->select('id', 'code', 'name', 'type', 'lat', 'lng', 'status')
            ->orderBy('code')
            ->get()
            ->map(fn ($r): array => [
                'id' => (string) $r->id,
                'code' => (string) $r->code,
                'name' => (string) $r->name,
                'type' => (string) $r->type,
                'lat' => (float) $r->lat,
                'lng' => (float) $r->lng,
                'status' => (string) $r->status,
            ])
            ->all());
    }
}
