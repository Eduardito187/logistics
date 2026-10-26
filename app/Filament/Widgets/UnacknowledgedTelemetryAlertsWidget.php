<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsControlTowerData;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\AcknowledgeAlert\AcknowledgeAlert;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Presentation\Filament\Resources\TelemetryAlertResource;
use Logistics\Telemetry\Presentation\Filament\Support\VehiclePlates;
use Throwable;

/**
 * Torre de control — EXCEPCIÓN: alertas de telemetría sin reconocer (mal uso de la flota: movimiento
 * fuera de horario, inactividad excesiva, violación de geocerca, exceso de velocidad). Cada fila es un
 * incidente que alguien de operaciones debe tomar. La acción inline "Acknowledge" despacha el Command
 * {@see AcknowledgeAlert} por el CommandBus (CQRS), nunca Eloquent directo, y registra al usuario que
 * la reconoce. También enlaza (drill-down) a la ficha en {@see TelemetryAlertResource}.
 *
 * Lee `telemetry_alerts` (sin `acknowledged_at`) por consulta directa (solo lectura) y resuelve la
 * placa del vehículo sin exponer UUIDs. Tolera la tabla ausente devolviendo una tabla vacía.
 */
final class UnacknowledgedTelemetryAlertsWidget extends TableWidget
{
    use ReadsControlTowerData;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '30s';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Unacknowledged telemetry alerts'))
            ->description(__('Fleet misuse alerts waiting to be acknowledged by operations.'))
            ->records(fn (): array => $this->rows())
            // Carga diferida: la torre pinta primero y la tabla llega después, sin bloquear el render.
            ->deferLoading()
            ->paginated([10, 25])
            ->emptyStateHeading(__('All alerts acknowledged'))
            ->emptyStateDescription(__('No open telemetry alerts right now.'))
            ->emptyStateIcon('heroicon-o-shield-check')
            ->columns([
                TextColumn::make('severity_label')
                    ->label(__('Severity'))
                    ->badge()
                    ->color(fn (array $record): string => match ($record['severity']) {
                        AlertSeverity::Critical->value => 'danger',
                        AlertSeverity::Warning->value => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('vehicle')
                    ->label(__('Vehicle'))
                    ->weight('medium')
                    ->url(fn (array $record): ?string => $record['url'])
                    ->color(fn (array $record): string => $record['url'] !== null ? 'primary' : 'gray'),
                TextColumn::make('type_label')
                    ->label(__('Type'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('occurred_at')
                    ->label(__('Occurred at')),
            ])
            ->recordActions([
                Action::make('acknowledge')
                    ->label(__('Acknowledge'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Acknowledge alert'))
                    ->modalDescription(__('Take ownership of this alert so the team knows it is handled.'))
                    ->modalSubmitActionLabel(__('Acknowledge'))
                    ->action(fn (array $record) => $this->acknowledge($record['__key'])),
            ]);
    }

    /**
     * Despacha el reconocimiento de la alerta por el CommandBus y notifica el resultado. El "by" es el
     * usuario autenticado. Centraliza el manejo de error para no tumbar el panel.
     */
    private function acknowledge(string $alertId): void
    {
        try {
            /** @var CommandBus $bus */
            $bus = app(CommandBus::class);
            $bus->dispatch(new AcknowledgeAlert($alertId, (string) Auth::id()));

            Notification::make()
                ->title(__('Alert acknowledged'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Could not acknowledge the alert'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Filas del widget: alertas abiertas, ordenadas de la más reciente a la más antigua y resueltas a
     * texto legible. Tolera cualquier fallo de lectura devolviendo una tabla vacía.
     *
     * @return list<array{__key: string, vehicle: string, type_label: string, severity: string, severity_label: string, occurred_at: string, url: string|null}>
     */
    private function rows(): array
    {
        try {
            $alerts = DB::table('telemetry_alerts')
                ->whereNull('acknowledged_at')
                ->orderByDesc('occurred_at')
                ->limit(25)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $rows = [];
        foreach ($alerts as $alert) {
            $alertId = (string) $alert->id;
            $vehicleId = (string) $alert->vehicle_id;
            $severity = (string) $alert->severity;
            $type = (string) $alert->type;

            $rows[] = [
                '__key' => $alertId,
                'vehicle' => VehiclePlates::label($vehicleId) ?? '—',
                'type_label' => $this->typeLabel($type),
                'severity' => $severity,
                'severity_label' => $this->severityLabel($severity),
                'occurred_at' => Carbon::parse((string) $alert->occurred_at)->format('Y-m-d H:i'),
                'url' => $this->alertUrl($alertId),
            ];
        }

        return $rows;
    }

    /**
     * Etiqueta legible del tipo de alerta; cae al valor crudo si es un tipo no previsto.
     */
    private function typeLabel(string $type): string
    {
        $known = AlertType::tryFrom($type);

        return $known !== null ? (string) __($known->label()) : $type;
    }

    /**
     * Etiqueta legible de la severidad; cae al valor crudo si no es una severidad conocida.
     */
    private function severityLabel(string $severity): string
    {
        $known = AlertSeverity::tryFrom($severity);

        return $known !== null ? (string) __($known->label()) : $severity;
    }

    /**
     * URL a la ficha de la alerta; null si la ruta no resuelve.
     */
    private function alertUrl(string $alertId): ?string
    {
        try {
            return TelemetryAlertResource::getUrl('view', ['record' => $alertId]);
        } catch (Throwable) {
            return null;
        }
    }
}
