<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;
use Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource;
use Logistics\Coverage\Presentation\Filament\Resource\ZoneResource;
use Logistics\Network\Presentation\Filament\Resources\NodeResource;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Throwable;

/**
 * Mapa interactivo de zonas de cobertura sobre OpenStreetMap (Leaflet + Geoman).
 *
 * Permite ver todas las zonas (polígonos PostGIS) y dibujar/editar el polígono de una zona por
 * municipio/ciudad, persistiéndolo vía el dominio (Zone::define + ZoneRepository::save, que escribe
 * la geometría con ST_GeomFromGeoJSON). Los mapas del proyecto usan OSM, sin API key.
 */
final class CoverageMap extends Page
{
    // Herramienta de planificación de red: solo oficina (administrador/supervisor). Una tienda o un rol
    // de campo no gestiona zonas de cobertura.
    use OfficeOnly;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.coverage-map';

    public ?string $zoneId = null;

    public string $name = '';

    public ?string $parentAreaId = null;

    public string $drawnGeoJson = '';

    public static function getNavigationGroup(): string
    {
        return __('Network');
    }

    // Cockpit geográfico unificado (B13): un solo mapa con CAPAS (zonas de cobertura + nodos de la red)
    // sobre el mismo lienzo, en vez de "Mapa de coberturas" y "Mapa de nodos" como ítems sueltos del menú.
    public static function getNavigationLabel(): string
    {
        return __('Geography');
    }

    public function getTitle(): string
    {
        return __('Geography');
    }

    /**
     * Accesos «avanzados» a la gestión tabular de las entidades que viven dentro del mapa (zonas y áreas
     * geográficas): el mapa es el hogar principal, pero el CRUD detallado sigue disponible desde aquí —
     * mismo patrón que el calendario de despacho con cupos/bloqueos. No ensucia el menú lateral.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('coverageZones')
                ->label(__('Coverage Zones'))
                ->icon('heroicon-o-map')
                ->color('gray')
                ->url(ZoneResource::getUrl('index')),
            Action::make('geographicAreas')
                ->label(__('Geographic Areas'))
                ->icon('heroicon-o-globe-americas')
                ->color('gray')
                ->url(GeoAreaResource::getUrl('index')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'zones' => $this->zonesGeoJson(),
            'cities' => $this->cities(),
            // Opciones del dropdown "Editar zona existente": SIEMPRE la lista completa y estable de zonas.
            // No se filtra por el municipio elegido: filtrarla hacía que, al seleccionar una zona o un
            // municipio (props `live` → re-render), el dropdown colapsara a una sola opción (bug reportado).
            'zoneOptions' => $this->zoneOptions(),
            // Capa de nodos de la red (marcadores): el mapa de nodos se absorbe aquí como una capa
            // conmutable, para tener un único cockpit geográfico.
            'nodes' => $this->nodes(),
        ];
    }

    /**
     * Reacciona al cambio del municipio/ciudad: hace zoom animado al conjunto de sus zonas.
     * El dropdown de zonas ya queda filtrado por el re-render (ver getViewData/zoneOptions).
     */
    public function updatedParentAreaId(): void
    {
        $geojsons = $this->zoneGeoJsonsForParent($this->parentAreaId);

        if ($geojsons === []) {
            // Municipio vacío (o sin zonas con geometría): el mapa vuelve a encuadrar todas las zonas.
            $this->dispatch('focus-reset');

            return;
        }

        $this->dispatch('focus-area', geojsons: $geojsons);
    }

    /**
     * Reacciona a la selección de una zona: carga su polígono y hace zoom animado a él.
     * Reutiliza la misma lógica que el botón "Load".
     */
    public function updatedZoneId(): void
    {
        if ($this->zoneId === null || $this->zoneId === '') {
            return;
        }

        $this->loadZone();
    }

    /**
     * Carga el polígono de la zona seleccionada para editarlo en el mapa.
     */
    public function loadZone(): void
    {
        if (!$this->zoneId) {
            return;
        }

        $z = DB::table('coverage_zones')
            ->where('id', $this->zoneId)
            ->selectRaw('name, parent_area_id, ST_AsGeoJSON(area) as geojson')
            ->first();

        if ($z === null) {
            return;
        }

        $this->name = $z->name;
        $this->parentAreaId = $z->parent_area_id;
        $this->drawnGeoJson = (string) $z->geojson;
        $this->dispatch('zone-loaded', geojson: (string) $z->geojson);
    }

    /**
     * Prepara el formulario para una zona nueva.
     */
    public function newZone(): void
    {
        $this->zoneId = null;
        $this->name = '';
        $this->parentAreaId = null;
        $this->drawnGeoJson = '';
        $this->dispatch('zone-reset');
    }

    public function save(): void
    {
        // Validación con notificación EXPLÍCITA: ni `name` (input crudo) ni `drawnGeoJson` (sin campo
        // visible) tienen anchor de error en el blade, así que un `$this->validate()` fallaba en silencio
        // y «Guardar» parecía no responder. Mostramos un mensaje claro por cada caso.
        $name = trim($this->name);
        if ($name === '') {
            Notification::make()->title(__('Zone name is required'))->danger()->send();

            return;
        }

        if (mb_strlen($name) > 120) {
            Notification::make()->title(__('Zone name is too long'))->danger()->send();

            return;
        }

        $ring = $this->extractRing($this->drawnGeoJson);
        if ($ring === null) {
            Notification::make()->title(__('Draw a polygon on the map first'))->danger()->send();

            return;
        }

        try {
            $polygon = Polygon::fromRing($ring);
            /** @var ZoneRepository $repo */
            $repo = app(ZoneRepository::class);

            if ($this->zoneId) {
                $existing = $repo->findById(ZoneId::fromString($this->zoneId));
                $rules = $existing?->rules() ?? ZoneRules::fromArray([]);
                $id = ZoneId::fromString($this->zoneId);
            } else {
                $id = $repo->nextIdentity();
                $rules = ZoneRules::fromArray([]);
            }

            $parent = ($this->parentAreaId !== null && $this->parentAreaId !== '')
                ? GeoAreaId::fromString($this->parentAreaId)
                : null;

            $repo->save(Zone::define($id, $name, $parent, $polygon, $rules));

            $this->name = $name;
            $this->zoneId = $id->toString();
            Notification::make()->title(__('Coverage zone saved'))->success()->send();
            $this->dispatch('zones-updated', zones: $this->zonesGeoJson());
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Could not save the zone'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return list<array{id: string, name: string, active: bool, geojson: string}>
     */
    private function zonesGeoJson(): array
    {
        return array_values(DB::table('coverage_zones')
            ->selectRaw('id, name, active, ST_AsGeoJSON(area) as geojson')
            ->whereNotNull('area')
            ->get()
            ->map(fn ($r): array => [
                'id' => (string) $r->id,
                'name' => (string) $r->name,
                'active' => (bool) $r->active,
                'geojson' => (string) $r->geojson,
            ])
            ->all());
    }

    /**
     * Zonas para el dropdown "Editar zona existente": SIEMPRE todas las zonas, en una query estable y
     * ordenada por nombre. No depende del estado del formulario (no se filtra por `parentAreaId`): así el
     * dropdown conserva TODAS sus opciones tras cada re-render de Livewire, se elija lo que se elija.
     *
     * @return list<array{id: string, name: string}>
     */
    private function zoneOptions(): array
    {
        return array_values(DB::table('coverage_zones')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($r): array => [
                'id' => (string) $r->id,
                'name' => (string) $r->name,
            ])
            ->all());
    }

    /**
     * GeoJSON de las zonas de un municipio (para el zoom animado del mapa).
     * Devuelve [] si no hay municipio o ninguna de sus zonas tiene geometría.
     *
     * @return list<string>
     */
    private function zoneGeoJsonsForParent(?string $parentAreaId): array
    {
        if ($parentAreaId === null || $parentAreaId === '') {
            return [];
        }

        return array_values(DB::table('coverage_zones')
            ->where('parent_area_id', $parentAreaId)
            ->whereNotNull('area')
            ->selectRaw('ST_AsGeoJSON(area) as geojson')
            ->get()
            ->map(fn ($r): string => (string) $r->geojson)
            ->all());
    }

    /**
     * @return array<string, string>
     */
    private function cities(): array
    {
        return DB::table('coverage_geo_areas')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Nodos de la red como marcadores para la capa de nodos del mapa. Cada uno enlaza a su ficha 360
     * (NodeResource::view) para no dejar el marcador huérfano. Tolerante: si falta la tabla o la ruta,
     * devuelve lo que pueda sin romper el mapa.
     *
     * @return list<array{code: string, name: string, type: string, status: string, lat: float, lng: float, url: ?string}>
     */
    private function nodes(): array
    {
        try {
            return array_values(DB::table('network_nodes')
                ->select('id', 'code', 'name', 'type', 'lat', 'lng', 'status')
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->orderBy('code')
                ->get()
                ->map(function ($r): array {
                    $url = null;
                    try {
                        $url = NodeResource::getUrl('view', ['record' => (string) $r->id]);
                    } catch (Throwable) {
                        $url = null;
                    }

                    return [
                        'code' => (string) $r->code,
                        'name' => (string) $r->name,
                        'type' => (string) $r->type,
                        'status' => (string) $r->status,
                        'lat' => (float) $r->lat,
                        'lng' => (float) $r->lng,
                        'url' => $url,
                    ];
                })
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Extrae el anillo exterior [[lng,lat],...] de un GeoJSON (Feature, Polygon o geometry), cerrándolo.
     *
     * @return list<array{0: float, 1: float}>|null
     */
    private function extractRing(string $geoJson): ?array
    {
        $data = json_decode($geoJson, true);
        if (!is_array($data)) {
            return null;
        }

        $geometry = $data['geometry'] ?? $data;
        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates) || !isset($coordinates[0]) || !is_array($coordinates[0])) {
            return null;
        }

        $ring = [];
        foreach ($coordinates[0] as $point) {
            if (is_array($point) && isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])) {
                $ring[] = [(float) $point[0], (float) $point[1]];
            }
        }

        if (count($ring) < 3) {
            return null;
        }

        $first = $ring[array_key_first($ring)];
        $last = $ring[array_key_last($ring)];
        if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
            $ring[] = $first;
        }

        return $ring;
    }
}
