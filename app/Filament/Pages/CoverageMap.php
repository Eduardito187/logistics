<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;

/**
 * Mapa interactivo de zonas de cobertura sobre OpenStreetMap (Leaflet + Geoman).
 *
 * Permite ver todas las zonas (polígonos PostGIS) y dibujar/editar el polígono de una zona por
 * municipio/ciudad, persistiéndolo vía el dominio (Zone::define + ZoneRepository::save, que escribe
 * la geometría con ST_GeomFromGeoJSON). Los mapas del proyecto usan OSM, sin API key.
 */
final class CoverageMap extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.coverage-map';

    public ?string $zoneId = null;

    public string $name = '';

    public ?string $parentAreaId = null;

    public string $drawnGeoJson = '';

    public static function getNavigationGroup(): string
    {
        return __('Network');
    }

    public static function getNavigationLabel(): string
    {
        return __('Coverage map');
    }

    public function getTitle(): string
    {
        return __('Coverage map');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'zones' => $this->zonesGeoJson(),
            'cities' => $this->cities(),
        ];
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
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'drawnGeoJson' => ['required', 'string'],
        ]);

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

            $repo->save(Zone::define($id, $this->name, $parent, $polygon, $rules));

            $this->zoneId = $id->toString();
            Notification::make()->title(__('Coverage zone saved'))->success()->send();
            $this->dispatch('zones-updated', zones: $this->zonesGeoJson());
        } catch (\Throwable $e) {
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
