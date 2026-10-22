<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Logistics\Returns\Application\Command\DispositionReturn\DispositionReturn;
use Logistics\Returns\Application\Command\ReceiveReturn\ReceiveReturn;
use Logistics\Returns\Domain\ValueObject\Disposition;
use Logistics\Returns\Domain\ValueObject\ReturnKind;
use Logistics\Returns\Domain\ValueObject\ReturnStatus;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Model\ReturnRecord;
use Logistics\Returns\Presentation\Filament\Resources\ReturnResource\Pages\ListReturns;
use Logistics\Returns\Presentation\Filament\Resources\ReturnResource\Pages\ViewReturn;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de devoluciones / logística inversa (grupo Operations).
 *
 * Es una proyección de lectura sobre ReturnRecord; toda MUTACIÓN de negocio (recibir, disponer) despacha un
 * Command al CommandBus CQRS, nunca escribe el record directo (respeta invariantes + outbox). Texto visible
 * en inglés (regla 5); comentarios en español (regla 6). Sin UUID visible: el seller se muestra por su
 * nombre legal (lectura cross-context read-only, sin dep composer); retail se muestra como "Retail".
 */
final class ReturnResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = ReturnRecord::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Returns');
    }

    public static function getNavigationSort(): int
    {
        return 40;
    }

    public static function getModelLabel(): string
    {
        return __('Return');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Returns');
    }

    /**
     * Vista de detalle (solo lectura): referencia original, seller, tipo, disposición y estado.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Return'))
                ->schema([
                    TextEntry::make('original_reference')
                        ->label(__('Original reference')),
                    TextEntry::make('owner_party_id')
                        ->label(__('Seller'))
                        ->formatStateUsing(static fn (?string $state): string => self::sellerName($state)),
                    TextEntry::make('kind')
                        ->label(__('Kind'))
                        ->formatStateUsing(static fn (string $state): string => self::kindLabel($state)),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(static fn (string $state): string => self::statusLabel($state)),
                    TextEntry::make('disposition')
                        ->label(__('Disposition'))
                        ->placeholder(__('Not dispositioned yet'))
                        ->formatStateUsing(static fn (?string $state): string => $state === null
                            ? __('Not dispositioned yet')
                            : self::dispositionLabel($state)),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static function (Builder $query): Builder {
                /** @var Builder<ReturnRecord> $query */
                return $query->with('lines');
            })
            ->columns([
                TextColumn::make('original_reference')
                    ->label(__('Original reference'))
                    ->searchable(),
                TextColumn::make('owner_party_id')
                    ->label(__('Seller'))
                    ->formatStateUsing(static fn (?string $state): string => self::sellerName($state)),
                TextColumn::make('kind')
                    ->label(__('Kind'))
                    ->formatStateUsing(static fn (string $state): string => self::kindLabel($state)),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(static fn (string $state): string => self::statusLabel($state)),
                TextColumn::make('disposition')
                    ->label(__('Disposition'))
                    ->placeholder('—')
                    ->formatStateUsing(static fn (?string $state): string => $state === null ? '—' : self::dispositionLabel($state)),
                TextColumn::make('lines_count')
                    ->label(__('Lines'))
                    ->counts('lines'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(self::statusOptions())
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                self::receiveAction(),
                self::dispositionAction(),
            ]);
    }

    /**
     * Recibir: despacha ReceiveReturn. Visible solo si está iniciada.
     */
    private static function receiveAction(): Action
    {
        return Action::make('receive')
            ->label(__('Record receipt'))
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('Record return receipt'))
            ->visible(static fn (ReturnRecord $record): bool => $record->status === ReturnStatus::Initiated->value)
            ->action(static function (ReturnRecord $record): void {
                self::dispatch(new ReceiveReturn(
                    returnId: (string) $record->getKey(),
                ), __('Return received'));
            });
    }

    /**
     * Disponer: despacha DispositionReturn. Visible solo si está recibida. Restock engancha con inventory.
     */
    private static function dispositionAction(): Action
    {
        return Action::make('disposition')
            ->label(__('Disposition'))
            ->icon('heroicon-o-clipboard-document-check')
            ->color('warning')
            ->modalHeading(__('Disposition return'))
            ->modalSubmitActionLabel(__('Disposition'))
            ->visible(static fn (ReturnRecord $record): bool => $record->status === ReturnStatus::Received->value)
            ->schema([
                Select::make('disposition')
                    ->label(__('Disposition'))
                    ->options(self::dispositionOptions())
                    ->native(false)
                    ->required(),
            ])
            ->action(static function (ReturnRecord $record, array $data): void {
                self::dispatch(new DispositionReturn(
                    returnId: (string) $record->getKey(),
                    disposition: (string) ($data['disposition'] ?? ''),
                ), __('Return dispositioned'));
            });
    }

    /**
     * Despacha un comando por el CommandBus y notifica el resultado (éxito o error de dominio).
     */
    private static function dispatch(Command $command, string $successTitle): void
    {
        try {
            /** @var CommandBus $bus */
            $bus = app(CommandBus::class);
            $bus->dispatch($command);

            Notification::make()->title($successTitle)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Could not complete the action'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Nombre legal del seller a partir de su id (lectura read-only de la tabla sellers, sin dep composer).
     * Null = devolución de producto retail de Dismac.
     */
    private static function sellerName(?string $sellerId): string
    {
        if ($sellerId === null || $sellerId === '') {
            return __('Retail');
        }

        $name = DB::table('sellers')->where('id', $sellerId)->value('legal_name');

        return is_string($name) && $name !== '' ? $name : __('Unknown seller');
    }

    private static function kindLabel(string $state): string
    {
        return self::kindOptions()[$state] ?? $state;
    }

    private static function dispositionLabel(string $state): string
    {
        return self::dispositionOptions()[$state] ?? $state;
    }

    private static function statusLabel(string $state): string
    {
        return self::statusOptions()[$state] ?? $state;
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            ReturnStatus::Dispositioned->value, ReturnStatus::Closed->value => 'success',
            ReturnStatus::Received->value => 'info',
            ReturnStatus::Initiated->value => 'warning',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (ReturnStatus::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function kindOptions(): array
    {
        $options = [];
        foreach (ReturnKind::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function dispositionOptions(): array
    {
        $options = [];
        foreach (Disposition::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListReturns::route('/'),
            'view' => ViewReturn::route('/{record}'),
        ];
    }
}
