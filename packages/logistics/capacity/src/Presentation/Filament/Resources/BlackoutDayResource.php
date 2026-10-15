<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Capacity\Application\Command\BlockDispatchDay\BlockDispatchDayCommand;
use Logistics\Capacity\Application\Command\UnblockDispatchDay\UnblockDispatchDayCommand;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\DispatchBlackoutDayRecord;
use Logistics\Capacity\Presentation\Filament\Resources\BlackoutDayResource\Pages\ListBlackoutDays;
use Logistics\Capacity\Presentation\Filament\Support\MunicipalityOptions;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\GeoAreaRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin Filament de días bloqueados de despacho (feriados u otros motivos). Un bloqueo es GLOBAL
 * (municipio null: toda la red) o POR MUNICIPIO, y la clave natural es (fecha, municipio).
 *
 * Nota de arquitectura: el record Eloquent (DispatchBlackoutDayRecord) es la proyección de LECTURA que
 * alimenta la tabla; muestra el municipio por NOMBRE (relación eager-loaded), nunca el UUID. Toda
 * MUTACIÓN pasa por el CommandBus CQRS: bloquear despacha BlockDispatchDayCommand y desbloquear
 * (la acción "Unblock" de la fila) despacha UnblockDispatchDayCommand; el admin nunca escribe el record
 * directamente. Todo texto visible en inglés (regla 5); comentarios en español (regla 6).
 *
 * @extends resource<DispatchBlackoutDayRecord>
 */
final class BlackoutDayResource extends Resource
{
    protected static ?string $model = DispatchBlackoutDayRecord::class;

    protected static ?int $navigationSort = 21;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-no-symbol';
    }

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('Blackout day');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Blackout days');
    }

    /**
     * Eager-load del municipio para que la columna legible no dispare N+1.
     *
     * @return Builder<DispatchBlackoutDayRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('municipality');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('blackout_date')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),
                // Alcance legible: "Global" para toda la red, o el nombre del municipio (nunca el UUID).
                TextColumn::make('scope')
                    ->label(__('Scope'))
                    ->badge()
                    ->color(static fn (DispatchBlackoutDayRecord $record): string => $record->municipality_id === null ? 'warning' : 'gray')
                    ->state(static fn (DispatchBlackoutDayRecord $record): string => self::scopeLabel($record)),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('global')
                    ->label(__('Global blackouts'))
                    ->native(false)
                    ->queries(
                        true: static fn (Builder $query): Builder => $query->whereNull('municipality_id'),
                        false: static fn (Builder $query): Builder => $query->whereNotNull('municipality_id'),
                        blank: static fn (Builder $query): Builder => $query,
                    ),
            ])
            ->defaultSort('blackout_date', 'desc')
            ->recordActions([
                self::unblockAction(),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListBlackoutDays::route('/'),
        ];
    }

    /**
     * Flujo "Block a day": da de alta un bloqueo despachando BlockDispatchDayCommand al CommandBus. Se
     * expone como acción de cabecera del listado. Municipio vacío = bloqueo global (toda la red).
     */
    public static function blockAction(): Action
    {
        return Action::make('block')
            ->label(__('Block a day'))
            ->icon('heroicon-o-no-symbol')
            ->modalHeading(__('Block a dispatch day'))
            ->modalDescription(__('Mark a day as a holiday or otherwise closed. Leave the municipality empty to block the whole network.'))
            ->schema(self::blockFormComponents())
            ->action(static function (array $data): void {
                try {
                    app(CommandBus::class)->dispatch(new BlockDispatchDayCommand(
                        date: substr((string) ($data['blackout_date'] ?? ''), 0, 10),
                        municipalityId: self::blankToNull($data['municipality_id'] ?? null),
                        reason: trim((string) ($data['reason'] ?? '')),
                        blockedBy: self::currentUserId(),
                    ));
                } catch (CapacityException $exception) {
                    self::failure(__('Could not block the day'), $exception);

                    return;
                }

                Notification::make()
                    ->title(__('Dispatch day blocked'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Acción de fila "Unblock": desbloquea el día despachando UnblockDispatchDayCommand (por su clave
     * natural fecha+municipio). No borra el record a mano; el handler elimina el bloqueo en su transacción.
     */
    public static function unblockAction(): Action
    {
        return Action::make('unblock')
            ->label(__('Unblock'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Unblock this dispatch day'))
            ->action(static function (DispatchBlackoutDayRecord $record): void {
                try {
                    app(CommandBus::class)->dispatch(new UnblockDispatchDayCommand(
                        date: substr((string) $record->blackout_date, 0, 10),
                        municipalityId: $record->municipality_id,
                    ));
                } catch (CapacityException $exception) {
                    self::failure(__('Could not unblock the day'), $exception);

                    return;
                }

                Notification::make()
                    ->title(__('Dispatch day unblocked'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Componentes del formulario de bloqueo. Municipio buscable y native(false), alimentado del catálogo
     * real de coverage; vacío = global.
     *
     * @return array<int, Component>
     */
    private static function blockFormComponents(): array
    {
        return [
            DatePicker::make('blackout_date')
                ->label(__('Date'))
                ->native(false)
                ->required()
                ->default(date('Y-m-d')),
            Select::make('municipality_id')
                ->label(__('Municipality'))
                ->helperText(__('Leave empty to block the whole network (global).'))
                ->placeholder(__('Global (all municipalities)'))
                ->native(false)
                ->searchable()
                ->options(static fn (): array => MunicipalityOptions::all()),
            TextInput::make('reason')
                ->label(__('Reason'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('e.g. National holiday')),
        ];
    }

    /**
     * Etiqueta legible del alcance de un bloqueo: "Global" o el nombre del municipio (cae al UUID solo si
     * el municipio ya no existe en la proyección).
     */
    private static function scopeLabel(DispatchBlackoutDayRecord $record): string
    {
        if ($record->municipality_id === null) {
            return __('Global');
        }

        $municipality = $record->municipality;

        return $municipality instanceof GeoAreaRecord ? $municipality->name : (string) $record->municipality_id;
    }

    /**
     * Convierte cadenas vacías a null (el select de municipio manda '' cuando se deja global).
     */
    private static function blankToNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Identificador del usuario autenticado (auditoría), como string o null.
     */
    private static function currentUserId(): ?string
    {
        $id = Filament::auth()->id();

        return $id === null ? null : (string) $id;
    }

    /**
     * Notificación de error de un flujo: muestra el motivo de dominio sin tumbar el request.
     */
    private static function failure(string $title, CapacityException $exception): void
    {
        Notification::make()
            ->title($title)
            ->body($exception->getMessage())
            ->danger()
            ->send();
    }
}
