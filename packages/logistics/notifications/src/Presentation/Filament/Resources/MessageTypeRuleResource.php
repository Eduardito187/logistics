<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTypeRuleRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages\CreateMessageTypeRule;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages\EditMessageTypeRule;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages\ListMessageTypeRules;

/**
 * Admin de las reglas de cascada configurables por tipo de mensaje (P1-NOT-01). Define qué canales se
 * intentan, en qué orden, y bajo qué condición se escala al siguiente. Un tipo sin regla usa la
 * cascada por defecto del ADR 006 (ver la página "Message Type Rules").
 *
 * Todo texto visible va en inglés y los selects usan ->native(false) (regla CLAUDE.md #5). El select de
 * canales es múltiple y reordenable: su orden ES el orden de la cascada.
 */
final class MessageTypeRuleResource extends Resource
{
    protected static ?string $model = MessageTypeRuleRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $recordTitleAttribute = 'message_type';

    public static function getNavigationGroup(): string
    {
        return __('Notifications');
    }

    public static function getNavigationLabel(): string
    {
        return __('Cascade Rules');
    }

    public static function getModelLabel(): string
    {
        return __('cascade rule');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cascade rules');
    }

    /**
     * Etiquetas humanas (traducibles) para cada tipo de mensaje (nunca el valor crudo del enum).
     *
     * @return array<string, string>
     */
    private static function messageTypes(): array
    {
        return [
            'PURCHASE_CONFIRMED' => __('Purchase confirmed'),
            'ORDER_RECEIVED' => __('Order received'),
            'AT_DISTRIBUTION_CENTER' => __('At distribution center'),
            'IN_PREPARATION' => __('In preparation'),
            'LOADED' => __('Loaded'),
            'EN_ROUTE' => __('En route'),
            'ARRIVING' => __('Arriving'),
            'DELIVERED' => __('Delivered'),
            'REQUIRES_SIGNATURE' => __('Requires signature'),
            'FAILED' => __('Delivery failed'),
            'RESCHEDULED' => __('Rescheduled'),
            'READY_FOR_PICKUP' => __('Ready for pickup'),
            'PICKED_UP' => __('Picked up'),
            'CONFIRM_LOCATION' => __('Confirm location'),
        ];
    }

    /**
     * Etiquetas humanas (traducibles) para cada canal.
     *
     * @return array<string, string>
     */
    private static function channels(): array
    {
        return [
            'PUSH_FCM' => __('Push'),
            'WEB_PUSH' => __('Web push'),
            'WHATSAPP' => __('WhatsApp'),
            'TELEGRAM' => __('Telegram'),
            'EMAIL' => __('Email'),
            'SMS' => __('SMS'),
        ];
    }

    /**
     * Etiquetas humanas (traducibles) para cada condición de escalado.
     *
     * @return array<string, string>
     */
    private static function escalateConditions(): array
    {
        return [
            'NO_OPEN' => __('No open'),
            'ON_FAILURE' => __('On failure'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // El tipo de mensaje es la clave (una regla por tipo): no se edita una vez creado.
            Select::make('message_type')
                ->label(__('Message type'))
                ->options(self::messageTypes())
                ->native(false)
                ->required()
                ->disabled(fn (string $operation): bool => $operation === 'edit'),
            Select::make('channels')
                ->label(__('Channels (in cascade order)'))
                ->options(self::channels())
                ->multiple()
                ->native(false)
                ->required()
                ->helperText(__('The selection order is the cascade order.')),
            Select::make('escalate_when')
                ->label(__('Escalate when'))
                ->options(self::escalateConditions())
                ->native(false)
                ->default(EscalateCondition::NoOpen->value)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('message_type')
                    ->label(__('Message type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::messageTypes()[$state] ?? $state)
                    ->color('info')
                    ->sortable(),
                TextColumn::make('channels')
                    ->label(__('Cascade'))
                    ->state(fn (MessageTypeRuleRecord $record): string => implode(
                        ' -> ',
                        array_map(static fn (string $c): string => self::channels()[$c] ?? $c, $record->channels),
                    ))
                    ->badge(),
                TextColumn::make('escalate_when')
                    ->label(__('Escalate when'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::escalateConditions()[$state] ?? $state)
                    ->color(static fn (string $state): string => $state === EscalateCondition::OnFailure->value ? 'danger' : 'gray'),
                TextColumn::make('updated_at')
                    ->label(__('Last update'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ])
            ->defaultSort('message_type');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListMessageTypeRules::route('/'),
            'create' => CreateMessageTypeRule::route('/create'),
            'edit' => EditMessageTypeRule::route('/{record}/edit'),
        ];
    }
}
