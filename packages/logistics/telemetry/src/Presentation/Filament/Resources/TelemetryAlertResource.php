<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\AcknowledgeAlert\AcknowledgeAlert;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\AlertRecord;
use Logistics\Telemetry\Presentation\Filament\Resources\TelemetryAlertResource\Pages\ListTelemetryAlerts;
use Logistics\Telemetry\Presentation\Filament\Resources\TelemetryAlertResource\Pages\ViewTelemetryAlert;
use Logistics\Telemetry\Presentation\Filament\Support\VehiclePlates;

/**
 * Admin de alertas de mal uso de la telemetría (exceso de velocidad, zona prohibida, uso no
 * autorizado...). Las alertas las levanta la ingesta; aquí se listan y se RECONOCEN.
 *
 * Nota de arquitectura: AlertRecord es solo proyección de lectura. El reconocimiento (acknowledge) se
 * hace despachando AcknowledgeAlert al CommandBus CQRS, nunca escribiendo el record. Las fechas se
 * guardan como texto ISO 8601 (ver AlertRecord/mapper). Texto visible en inglés (regla 5); comentarios
 * en español (regla 6).
 */
final class TelemetryAlertResource extends Resource
{
    protected static ?string $model = AlertRecord::class;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getNavigationSort(): int
    {
        return 54;
    }

    /**
     * Título legible de la alerta: la placa del vehículo, nunca el UUID (regla: ningún UUID visible).
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof AlertRecord) {
            return null;
        }

        return VehiclePlates::label($record->vehicle_id) ?? '—';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }

    public static function getModelLabel(): string
    {
        return __('Alert');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Alerts');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Etiquetas humanas (inglés) de cada tipo de alerta. Las claves son los valores del enum.
     *
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];
        foreach (AlertType::cases() as $type) {
            $options[$type->value] = __($type->label());
        }

        return $options;
    }

    /**
     * Etiquetas humanas (inglés) de cada severidad. Las claves son los valores del enum.
     *
     * @return array<string, string>
     */
    private static function severityOptions(): array
    {
        $options = [];
        foreach (AlertSeverity::cases() as $severity) {
            $options[$severity->value] = __($severity->label());
        }

        return $options;
    }

    /**
     * Color semántico del badge por severidad.
     */
    private static function severityColor(string $severity): string
    {
        return match ($severity) {
            AlertSeverity::Critical->value => 'danger',
            AlertSeverity::Warning->value => 'warning',
            default => 'info',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Alert'))
                ->schema([
                    TextEntry::make('vehicle_id')
                        ->label(__('Vehicle'))
                        ->formatStateUsing(static fn (string $state): string => VehiclePlates::label($state) ?? '—'),
                    TextEntry::make('type')
                        ->label(__('Type'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => self::typeOptions()[$state] ?? $state),
                    TextEntry::make('severity')
                        ->label(__('Severity'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => self::severityOptions()[$state] ?? $state)
                        ->color(static fn (string $state): string => self::severityColor($state)),
                    TextEntry::make('occurred_at')
                        ->label(__('Occurred at'))
                        ->dateTime(),
                    TextEntry::make('acknowledged_at')
                        ->label(__('Acknowledged at'))
                        ->dateTime()
                        ->placeholder(__('Not acknowledged')),
                    TextEntry::make('acknowledged_by')
                        ->label(__('Acknowledged by'))
                        ->placeholder('—'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->formatStateUsing(static fn (string $state): string => VehiclePlates::label($state) ?? '—')
                    ->sortable(),
                // UUID crudo del vehículo: oculto por defecto, disponible como columna toggleable para
                // depurar o cruzar datos. La placa legible vive en la columna "Vehicle" de arriba.
                TextColumn::make('vehicle_uuid')
                    ->label(__('Vehicle ID'))
                    ->getStateUsing(static fn (AlertRecord $record): string => $record->vehicle_id)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::typeOptions()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('severity')
                    ->label(__('Severity'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::severityOptions()[$state] ?? $state)
                    ->color(static fn (string $state): string => self::severityColor($state))
                    ->sortable(),
                TextColumn::make('occurred_at')
                    ->label(__('Occurred at'))
                    ->dateTime()
                    ->sortable(),
                IconColumn::make('acknowledged_at')
                    ->label(__('Acknowledged'))
                    ->boolean()
                    ->getStateUsing(static fn (AlertRecord $record): bool => $record->acknowledged_at !== null),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->native(false)
                    ->options(self::typeOptions()),
                SelectFilter::make('severity')
                    ->label(__('Severity'))
                    ->native(false)
                    ->options(self::severityOptions()),
                TernaryFilter::make('acknowledged')
                    ->label(__('Acknowledged'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Acknowledged'))
                    ->falseLabel(__('Not acknowledged'))
                    ->queries(
                        true: static function (Builder $query): Builder {
                            /** @var Builder<AlertRecord> $query */
                            return $query->whereNotNull('acknowledged_at');
                        },
                        false: static function (Builder $query): Builder {
                            /** @var Builder<AlertRecord> $query */
                            return $query->whereNull('acknowledged_at');
                        },
                        blank: static function (Builder $query): Builder {
                            /** @var Builder<AlertRecord> $query */
                            return $query;
                        },
                    ),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                // Reconocer: solo visible si aún no está reconocida. Despacha AcknowledgeAlert al bus con
                // el email del usuario del admin como actor.
                Action::make('acknowledge')
                    ->label(__('Acknowledge'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(static fn (AlertRecord $record): bool => $record->acknowledged_at === null)
                    ->action(static function (AlertRecord $record): void {
                        app(CommandBus::class)->dispatch(new AcknowledgeAlert(
                            alertId: (string) $record->getKey(),
                            by: (string) auth()->user()?->email,
                        ));

                        Notification::make()
                            ->title(__('Alert acknowledged'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTelemetryAlerts::route('/'),
            'view' => ViewTelemetryAlert::route('/{record}'),
        ];
    }
}
