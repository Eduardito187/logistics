<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Execution\Application\Command\ReportVehicleIncident\ReportVehicleIncident;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\IncidentSeverity;
use Logistics\Execution\Domain\ValueObject\IncidentType;
use Logistics\Execution\Domain\ValueObject\RouteStopStatus;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleRouteRecord;
use Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Vista de detalle de una ruta con sus paradas en orden y el estado de cada una (solo lectura).
 *
 * Lleva la acción "Report incident": el supervisor (o el chofer desde la app) reporta una avería o
 * accidente del camión y el sistema reacciona solo —reprograma SOLO las paradas pendientes, deja el
 * camión fuera de servicio y avisa a clientes/supervisor—. La acción NO escribe el Record: despacha
 * ReportVehicleIncident por el CommandBus, de modo que el dominio y el outbox queden en una transacción.
 */
final class ViewVehicleRoute extends ViewRecord
{
    protected static string $resource = VehicleRouteResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->reportIncidentAction(),
        ];
    }

    /**
     * Acción de reporte de incidente del camión en ruta. Solo tiene sentido si la ruta aún tiene paradas
     * pendientes por atender (si no, no hay nada que reprogramar). Pide gravedad, tipo y nota.
     */
    private function reportIncidentAction(): Action
    {
        return Action::make('reportIncident')
            ->label(__('Report incident'))
            ->icon('ds-exclamation-triangle')
            ->extraAttributes(['class' => 'ds-icon-bounce-hover'])
            ->color('danger')
            ->modalHeading(__('Report vehicle incident'))
            ->modalDescription(__('Report a breakdown or accident. Pending stops will be rescheduled automatically; delivered stops are not touched.'))
            ->modalSubmitActionLabel(__('Report incident'))
            ->visible(static fn (VehicleRouteRecord $record): bool => self::hasPendingStops($record))
            ->schema([
                Select::make('severity')
                    ->label(__('Severity'))
                    ->required()
                    ->native(false)
                    ->default(IncidentSeverity::Major->value)
                    ->helperText(__('Major takes the vehicle out of service and reschedules pending stops to the next day. Minor keeps the route with delayed ETAs.'))
                    ->options(self::severityOptions()),
                Select::make('type')
                    ->label(__('Type'))
                    ->required()
                    ->native(false)
                    ->default(IncidentType::Breakdown->value)
                    ->options(self::typeOptions()),
                Textarea::make('note')
                    ->label(__('Note'))
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (VehicleRouteRecord $record, array $data): void {
                try {
                    app(CommandBus::class)->dispatch(new ReportVehicleIncident(
                        vehicleId: (string) $record->vehicle_id,
                        severity: (string) $data['severity'],
                        type: (string) $data['type'],
                        routeId: (string) $record->getKey(),
                        note: isset($data['note']) && is_string($data['note']) && trim($data['note']) !== '' ? $data['note'] : null,
                        deviceId: 'admin-panel',
                        userId: (string) (auth()->id() ?? 'supervisor'),
                    ));

                    Notification::make()
                        ->title(__('Incident reported'))
                        ->body(__('Pending stops were rescheduled and the affected parties will be notified.'))
                        ->success()
                        ->send();

                    // Recarga la ficha para reflejar el nuevo estado de la ruta y de sus paradas.
                    $this->refreshFormData(['status']);
                } catch (ExecutionException $e) {
                    Notification::make()
                        ->title(__('Could not report incident'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * ¿La ruta tiene paradas pendientes (algo que reprogramar)?
     */
    private static function hasPendingStops(VehicleRouteRecord $record): bool
    {
        return $record->stops()
            ->where('status', RouteStopStatus::Pending->value)
            ->exists();
    }

    /**
     * Opciones de gravedad con etiqueta en inglés (desde el enum del dominio).
     *
     * @return array<string, string>
     */
    private static function severityOptions(): array
    {
        $options = [];
        foreach (IncidentSeverity::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Opciones de tipo con etiqueta en inglés (desde el enum del dominio).
     *
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];
        foreach (IncidentType::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }
}
