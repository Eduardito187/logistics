<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTemplateRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages\CreateMessageTemplate;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages\EditMessageTemplate;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages\ListMessageTemplates;

/**
 * Admin de plantillas de mensaje (P1-NOT-08): el contenido por tipo de mensaje, canal y locale, con
 * variables `{{clave}}` que se reemplazan al renderizar. El asunto solo lo usa el canal email.
 *
 * Nota de arquitectura: Filament opera sobre el modelo Eloquent por diseño; aquí el contenido es
 * configuración editable. Todo texto visible va en inglés (regla CLAUDE.md #5) y los selects usan
 * ->native(false).
 */
final class MessageTemplateResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = MessageTemplateRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $recordTitleAttribute = 'message_type';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationSort(): int
    {
        return 42;
    }

    public static function getNavigationLabel(): string
    {
        return __('Message Templates');
    }

    public static function getModelLabel(): string
    {
        return __('message template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('message templates');
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
            'FCM' => __('Push'),
            'TELEGRAM' => __('Telegram'),
            'EMAIL' => __('Email'),
            'SMS' => __('SMS'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Identidad de la plantilla (tipo + canal + locale): no editable una vez creada, porque es
            // la clave única; la edición solo revisa asunto/cuerpo (ver EditMessageTemplate).
            Select::make('message_type')
                ->label(__('Message type'))
                ->options(self::messageTypes())
                ->native(false)
                ->required()
                ->disabled(fn (string $operation): bool => $operation === 'edit'),
            Select::make('channel')
                ->label(__('Channel'))
                ->options(self::channels())
                ->native(false)
                ->required()
                ->disabled(fn (string $operation): bool => $operation === 'edit'),
            TextInput::make('locale')
                ->label(__('Locale'))
                ->default('es')
                ->required()
                ->maxLength(8)
                ->disabled(fn (string $operation): bool => $operation === 'edit'),
            TextInput::make('subject')
                ->label(__('Subject (email only)'))
                ->maxLength(255),
            Textarea::make('body')
                ->label(__('Body'))
                ->helperText(__('Variables: {{order}}, {{shipment_id}}, {{tracking_topic}}, {{customer}}, {{eta}}, {{stops}}.'))
                ->required()
                ->rows(4),
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
                TextColumn::make('channel')
                    ->label(__('Channel'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::channels()[$state] ?? $state)
                    ->color(static fn (string $state): string => match ($state) {
                        Channel::Email->value => 'primary',
                        Channel::Sms->value => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('locale')
                    ->label(__('Locale'))
                    ->sortable(),
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->limit(40),
                TextColumn::make('body')
                    ->label(__('Body'))
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('updated_at')
                    ->label(__('Last update'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('message_type')
                    ->label(__('Message type'))
                    ->native(false)
                    ->options(self::messageTypes()),
                SelectFilter::make('channel')
                    ->label(__('Channel'))
                    ->native(false)
                    ->options(self::channels()),
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
            'index' => ListMessageTemplates::route('/'),
            'create' => CreateMessageTemplate::route('/create'),
            'edit' => EditMessageTemplate::route('/{record}/edit'),
        ];
    }
}
