<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Resource\ZoneResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Application\Command\ImportCoverageKml;
use Logistics\Coverage\Application\Command\ImportCoverageMap;
use Logistics\Coverage\Application\Query\ResolveZoneForPoint;
use Logistics\Coverage\Application\ReadModel\ZoneView;
use Logistics\Coverage\Presentation\Filament\Resource\ZoneResource;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Listado de zonas de cobertura con los flujos operativos del contexto.
 *
 * No hay alta tipo CRUD: las zonas nacen con geometría. Las acciones de cabecera exponen los casos de
 * uso reales del bounded context y se ejecutan SIEMPRE por los buses CQRS (no escriben el Record):
 *
 *  - Resolve point to zone: consulta ResolveZoneForPoint (lat/lng → zona/nodo/reglas) por el QueryBus.
 *  - Import GeoJSON map:     comando ImportCoverageMap (FeatureCollection) por el CommandBus + outbox.
 *  - Import KML map:         comando ImportCoverageKml (XML crudo) por el CommandBus + outbox.
 */
final class ListZones extends ListRecords
{
    protected static string $resource = ZoneResource::class;

    /**
     * Acciones de cabecera: el flujo operativo del contexto, agrupado de forma clara.
     *
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Flujo de consulta destacado: resolver una coordenada a su zona.
            $this->resolvePointAction(),
            // Flujos de ingesta del mapa agrupados bajo un solo botón.
            ActionGroup::make([
                $this->importGeoJsonAction(),
                $this->importKmlAction(),
            ])
                ->label(__('Import coverage map'))
                ->icon('heroicon-o-arrow-up-tray')
                ->button(),
        ];
    }

    /**
     * Resuelve una coordenada (lat/lng) a la zona que la contiene y muestra zona, nodo y reglas.
     *
     * Es una lectura: despacha ResolveZoneForPoint por el QueryBus y presenta el ZoneView resultante.
     */
    private function resolvePointAction(): Action
    {
        return Action::make('resolvePoint')
            ->label(__('Resolve point to zone'))
            ->icon('heroicon-o-map-pin')
            ->color('primary')
            ->modalHeading(__('Resolve a coordinate'))
            ->modalDescription(__('Find which coverage zone contains a point, with its serving node and rules.'))
            ->modalSubmitActionLabel(__('Resolve'))
            ->schema([
                TextInput::make('lat')
                    ->label(__('Latitude'))
                    ->numeric()
                    ->required()
                    ->minValue(-90)
                    ->maxValue(90)
                    ->placeholder('-16.5000'),
                TextInput::make('lng')
                    ->label(__('Longitude'))
                    ->numeric()
                    ->required()
                    ->minValue(-180)
                    ->maxValue(180)
                    ->placeholder('-68.1500'),
            ])
            ->action(function (array $data): void {
                /** @var QueryBus $queryBus */
                $queryBus = app(QueryBus::class);

                $view = $queryBus->ask(new ResolveZoneForPoint(
                    (float) $data['lat'],
                    (float) $data['lng'],
                ));

                if (!$view instanceof ZoneView) {
                    Notification::make()
                        ->title(__('No coverage'))
                        ->body(__('No coverage zone contains the given point.'))
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Zone: :name', ['name' => $view->name]))
                    ->body(self::describeZone($view))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Importa el mapa de cobertura desde un FeatureCollection de GeoJSON pegado por el operador.
     *
     * Decodifica el JSON y despacha ImportCoverageMap por el CommandBus (transacción + outbox).
     */
    private function importGeoJsonAction(): Action
    {
        return Action::make('importGeoJson')
            ->label(__('From GeoJSON'))
            ->icon('heroicon-o-code-bracket')
            ->modalHeading(__('Import coverage map (GeoJSON)'))
            ->modalDescription(__('Paste a GeoJSON FeatureCollection with the current coverage polygons.'))
            ->modalSubmitActionLabel(__('Import'))
            ->schema([
                Textarea::make('geojson')
                    ->label(__('GeoJSON FeatureCollection'))
                    ->required()
                    ->rows(12)
                    ->placeholder('{ "type": "FeatureCollection", "features": [ ... ] }'),
            ])
            ->action(function (array $data): void {
                $raw = is_string($data['geojson'] ?? null) ? $data['geojson'] : '';
                $decoded = json_decode($raw, true);

                if (!is_array($decoded)) {
                    Notification::make()
                        ->title(__('Invalid GeoJSON'))
                        ->body(__('The content could not be parsed as a JSON object.'))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    /** @var CommandBus $commandBus */
                    $commandBus = app(CommandBus::class);
                    $commandBus->dispatch(new ImportCoverageMap($decoded));
                } catch (Throwable $e) {
                    Notification::make()
                        ->title(__('Import failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Coverage map imported'))
                    ->body(__('The GeoJSON coverage map was imported.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Importa el mapa de cobertura desde un documento KML (XML crudo) pegado por el operador.
     *
     * Despacha ImportCoverageKml por el CommandBus; el parseo del XML lo hace el servicio de dominio.
     */
    private function importKmlAction(): Action
    {
        return Action::make('importKml')
            ->label(__('From KML'))
            ->icon('heroicon-o-globe-alt')
            ->modalHeading(__('Import coverage map (KML)'))
            ->modalDescription(__('Paste a KML document (Google Earth / Maps) with the current coverage polygons.'))
            ->modalSubmitActionLabel(__('Import'))
            ->schema([
                Textarea::make('kml')
                    ->label(__('KML document'))
                    ->required()
                    ->rows(12)
                    ->placeholder('<?xml version="1.0" ...><kml> ... </kml>'),
            ])
            ->action(function (array $data): void {
                $kml = is_string($data['kml'] ?? null) ? $data['kml'] : '';

                try {
                    /** @var CommandBus $commandBus */
                    $commandBus = app(CommandBus::class);
                    $commandBus->dispatch(new ImportCoverageKml($kml));
                } catch (Throwable $e) {
                    Notification::make()
                        ->title(__('Import failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Coverage map imported'))
                    ->body(__('The KML coverage map was imported.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Arma un resumen legible del resultado de la resolución: nodo servidor, servicios y lead time.
     *
     * El nombre del nodo se resuelve por consulta directa a network_nodes para no acoplar este
     * contexto al package network (cada contexto es extraíble a microservicio).
     */
    private static function describeZone(ZoneView $view): string
    {
        $lines = [];

        $nodeId = $view->rules['nodeId'] ?? null;
        if (is_string($nodeId) && $nodeId !== '') {
            $node = DB::table('network_nodes')->where('id', $nodeId)->first();
            $lines[] = __('Serving node').': '.($node === null ? $nodeId : "{$node->name} ({$node->code})");
        } else {
            $lines[] = __('Serving node').': —';
        }

        $services = $view->rules['services'] ?? [];
        $lines[] = __('Services').': '.(is_array($services) && $services !== [] ? implode(', ', $services) : '—');

        $base = $view->rules['baseMinutes'] ?? null;
        $lines[] = __('Base lead time').': '.(is_numeric($base) ? ((int) $base).' '.__('min') : '—');

        $lines[] = __('Active').': '.($view->active ? __('yes') : __('no'));

        return implode("\n", $lines);
    }
}
