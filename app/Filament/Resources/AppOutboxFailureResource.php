<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AppOutboxFailureResource\Pages\ListAppOutboxFailures;
use App\Models\AppOutboxFailure;
use App\Models\Device;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Monitoreo de acciones offline de la app que el servidor rechazó para siempre (o que agotaron
 * reintentos), reportadas por los teléfonos con `POST /app/outbox-failures`.
 *
 * Para supervisores de la torre de control: ver qué operación de terreno no llegó (quién, qué equipo,
 * qué operación, qué código), filtrar por usuario/equipo/fecha/estado y marcarla como atendida. Solo
 * lectura salvo «Mark as resolved»: las filas las crea la app. Sin UUIDs visibles (usuario por nombre,
 * equipo por su nombre legible; los ids de la ruta llegan enmascarados como `{id}`).
 *
 * El texto visible va íntegramente en inglés (regla 5 del proyecto).
 *
 * @extends resource<AppOutboxFailure>
 */
final class AppOutboxFailureResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = AppOutboxFailure::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-signal-slash';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationSort(): int
    {
        return 90;
    }

    public static function getNavigationLabel(): string
    {
        return __('Rejected app actions');
    }

    public static function getModelLabel(): string
    {
        return __('rejected app action');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rejected app actions');
    }

    /** Contador de navegación: rechazos sin atender. */
    public static function getNavigationBadge(): ?string
    {
        $open = AppOutboxFailure::query()->whereNull('resolved_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['user', 'device']))
            ->paginationPageOptions([25, 50, 100])
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('Occurred at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('User'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('device_label')
                    ->label(__('Device'))
                    ->placeholder('—')
                    ->getStateUsing(static fn (AppOutboxFailure $record): ?string => $record->device?->displayName()),
                TextColumn::make('kind')
                    ->label(__('Operation'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::reasonLabel($state))
                    ->color(static fn (string $state): string => $state === 'rejected' ? 'danger' : 'warning'),
                TextColumn::make('http_status')
                    ->label(__('HTTP status'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('problem_code')
                    ->label(__('Error code'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('endpoint')
                    ->label(__('Endpoint'))
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(static fn (?string $state): ?string => $state)
                    ->toggleable(),
                TextColumn::make('attempts')
                    ->label(__('Attempts'))
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('app_version')
                    ->label(__('App version'))
                    ->placeholder('—')
                    ->toggleable(),
                self::statusColumn(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(['open' => __('Open'), 'resolved' => __('Resolved')])
                    ->default('open')
                    ->query(static function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'open' => $query->whereNull('resolved_at'),
                            'resolved' => $query->whereNotNull('resolved_at'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('user_id')
                    ->label(__('User'))
                    ->native(false)
                    ->relationship('user', 'name')
                    ->searchable(),
                SelectFilter::make('device_id')
                    ->label(__('Device'))
                    ->native(false)
                    ->relationship('device', 'name', static fn (Builder $query): Builder => $query
                        ->whereIn('id', AppOutboxFailure::query()->select('device_id')->whereNotNull('device_id')))
                    ->getOptionLabelFromRecordUsing(static fn (Device $record): string => $record->displayName())
                    ->searchable(),
                SelectFilter::make('reason')
                    ->label(__('Reason'))
                    ->native(false)
                    ->options(self::reasonOptions()),
                self::periodFilter(),
            ])
            ->recordActions([
                self::resolveAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::resolveBulkAction(),
                ]),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAppOutboxFailures::route('/'),
        ];
    }

    /**
     * Columna de estado: abierta/resuelta, con quién y cuándo la resolvió en el tooltip.
     */
    private static function statusColumn(): TextColumn
    {
        return TextColumn::make('resolved_at')
            ->label(__('Status'))
            ->badge()
            ->getStateUsing(static fn (AppOutboxFailure $record): string => $record->isResolved() ? __('Resolved') : __('Open'))
            ->color(static fn (AppOutboxFailure $record): string => $record->isResolved() ? 'success' : 'danger')
            ->tooltip(static function (AppOutboxFailure $record): ?string {
                if (!$record->isResolved()) {
                    return null;
                }

                // __() puede devolver array (clave de grupo): solo se usa si es texto.
                $text = __('Resolved by :actor on :date', [
                    'actor' => $record->resolved_by ?? '—',
                    'date' => $record->resolved_at?->toDayDateTimeString() ?? '—',
                ]);

                return is_string($text) ? $text : null;
            });
    }

    /**
     * Filtro por rango de fechas de ocurrencia (límite superior inclusivo por día).
     */
    private static function periodFilter(): Filter
    {
        return Filter::make('period')
            ->label(__('Date range'))
            ->schema([
                DatePicker::make('from')->label(__('From'))->native(false),
                DatePicker::make('until')->label(__('To'))->native(false),
            ])
            ->query(static function (Builder $query, array $data): Builder {
                /** @var Builder<AppOutboxFailure> $query */
                $from = $data['from'] ?? null;
                $until = $data['until'] ?? null;
                if (is_string($from) && $from !== '') {
                    $query->where('occurred_at', '>=', Carbon::parse($from)->startOfDay());
                }
                if (is_string($until) && $until !== '') {
                    $query->where('occurred_at', '<', Carbon::parse($until)->addDay()->startOfDay());
                }

                return $query;
            })
            ->indicateUsing(static fn (array $data): ?string => ($data['from'] ?? null) || ($data['until'] ?? null)
                ? (string) __('Date range')
                : null);
    }

    private static function resolveAction(): Action
    {
        return Action::make('resolve')
            ->label(__('Mark as resolved'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Mark as resolved'))
            ->modalDescription(__('Confirm that this rejected action was followed up.'))
            ->visible(static fn (AppOutboxFailure $record): bool => !$record->isResolved())
            ->action(static function (AppOutboxFailure $record): void {
                $record->markResolved(self::actorLabel());

                Notification::make()->title(__('Marked as resolved'))->success()->send();
            });
    }

    private static function resolveBulkAction(): BulkAction
    {
        return BulkAction::make('resolveSelected')
            ->label(__('Mark selected as resolved'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(static function (Collection $records): void {
                $actor = self::actorLabel();
                $count = 0;
                foreach ($records as $record) {
                    if ($record instanceof AppOutboxFailure && !$record->isResolved()) {
                        $record->markResolved($actor);
                        $count++;
                    }
                }

                Notification::make()
                    ->title(__(':count action(s) marked as resolved', ['count' => $count]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Nombre legible del actor del admin que resuelve (nunca un id técnico).
     */
    private static function actorLabel(): ?string
    {
        $user = Filament::auth()->user();
        if (!$user instanceof User) {
            return null;
        }

        $name = $user->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : $user->email;
    }

    /**
     * @return array<string, string>
     */
    private static function reasonOptions(): array
    {
        $options = [];
        foreach (AppOutboxFailure::REASONS as $reason) {
            $options[$reason] = self::reasonLabel($reason);
        }

        return $options;
    }

    private static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'rejected' => __('Rejected by server'),
            'retries_exhausted' => __('Retries exhausted'),
            'invalid_payload' => __('Invalid payload'),
            'unknown_kind' => __('Unknown operation'),
            default => $reason,
        };
    }
}
