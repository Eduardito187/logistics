<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament;

use App\Filament\Support\RequestScopedQuery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Planning\Application\Command\RemoveShipmentRequirement\RemoveShipmentRequirement;
use Logistics\Planning\Application\Command\SetShipmentRequirement\SetShipmentRequirement;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\ShipmentRequirementRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\VehicleRestrictionRecord;
use Logistics\Planning\Presentation\Filament\ShipmentRequirementResource\Pages;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Admin de los REQUISITOS DE RUTEO por envío (R-04, Ola 0 "restricciones sin dueño"): tipo de carga, zona
 * de destino y dimensiones físicas del producto que el camión debe poder manejar. El auto-planificador los
 * lee en lote y solo asigna el envío a camiones compatibles.
 *
 * Toda escritura despacha un comando al CommandBus (SetShipmentRequirement / RemoveShipmentRequirement);
 * el record es solo proyección de lectura. El envío se muestra por la referencia del pedido y el cliente,
 * nunca por el UUID.
 *
 * Fuera del menú: depende del envío. Se llega desde «Delivery Plans» (acción de cabecera) y desde la ficha
 * del envío con `?shipment=<id>`, que acota la lista a ese envío.
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6).
 *
 * @extends resource<ShipmentRequirementRecord>
 */
final class ShipmentRequirementResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = ShipmentRequirementRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-cube';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('shipment requirement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shipment requirements');
    }

    /**
     * Título humano del registro: referencia del pedido y cliente, nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof ShipmentRequirementRecord) {
            return null;
        }

        return self::shipmentLabel($record->shipment) ?? '—';
    }

    /**
     * Eager-load del envío para pintar la referencia sin N+1.
     *
     * @return Builder<ShipmentRequirementRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('shipment');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('shipment_id')
                ->label(__('Shipment'))
                ->required()
                ->native(false)
                ->searchable()
                // La PK es el envío: no se cambia al editar (se borra y se crea otro).
                ->disabled(static fn (string $operation): bool => $operation === 'edit')
                ->getSearchResultsUsing(static fn (string $search): array => self::shipmentSearch($search))
                ->getOptionLabelUsing(static fn (string $value): ?string => self::shipmentLabel(
                    ShipmentRecord::query()->find($value),
                )),
            Select::make('load_type')
                ->label(__('Load type'))
                ->native(false)
                ->searchable()
                // Tipos de carga reales: los que admiten los camiones con restricciones y los ya usados.
                ->options(static fn (): array => self::knownLoadTypes())
                ->helperText(__('Leave empty if the shipment fits any truck.')),
            TextInput::make('zone')
                ->label(__('Zone'))
                ->maxLength(255),
            TextInput::make('height_m')
                ->label(__('Product height (m)'))
                ->numeric()
                ->minValue(0)
                ->step(0.01),
            TextInput::make('weight_kg')
                ->label(__('Product weight (kg)'))
                ->numeric()
                ->minValue(0)
                ->step(0.01),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Acota al envío al abrir desde su ficha (?shipment=<id>).
            ->modifyQueryUsing(RequestScopedQuery::by('shipment', 'shipment_id'))
            ->columns([
                TextColumn::make('shipment.order_reference')
                    ->label(__('Order reference'))
                    ->state(static fn (ShipmentRequirementRecord $record): string => self::shipmentLabel($record->shipment) ?? '—'),
                TextColumn::make('load_type')
                    ->label(__('Load type'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('zone')
                    ->label(__('Zone'))
                    ->placeholder('—'),
                TextColumn::make('height_m')
                    ->label(__('Product height (m)'))
                    ->numeric(2)
                    ->placeholder('—'),
                TextColumn::make('weight_kg')
                    ->label(__('Product weight (kg)'))
                    ->numeric(2)
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                self::removeAction(),
            ]);
    }

    /**
     * Quitar los requisitos del envío: despacha RemoveShipmentRequirement (nunca borra el record a mano).
     */
    public static function removeAction(): Action
    {
        return Action::make('removeRequirement')
            ->label(__('Remove requirement'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Remove shipment requirement'))
            ->modalDescription(__('The shipment will fit any truck again.'))
            ->action(static function (ShipmentRequirementRecord $record, CommandBus $commandBus): void {
                $commandBus->dispatch(new RemoveShipmentRequirement((string) $record->getKey()));

                Notification::make()
                    ->title(__('Requirement removed'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Arma el comando desde los datos del formulario.
     *
     * @param array<string, mixed> $data
     */
    public static function commandFrom(string $shipmentId, array $data): SetShipmentRequirement
    {
        $loadType = $data['load_type'] ?? null;
        $zone = $data['zone'] ?? null;
        $height = $data['height_m'] ?? null;
        $weight = $data['weight_kg'] ?? null;

        return new SetShipmentRequirement(
            shipmentId: $shipmentId,
            loadType: is_string($loadType) ? $loadType : null,
            zone: is_string($zone) ? $zone : null,
            heightM: is_numeric($height) ? (float) $height : null,
            weightKg: is_numeric($weight) ? (float) $weight : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShipmentRequirements::route('/'),
            'create' => Pages\CreateShipmentRequirement::route('/create'),
            'edit' => Pages\EditShipmentRequirement::route('/{record}/edit'),
        ];
    }

    /**
     * Búsqueda de envíos por referencia del pedido, id externo o cliente, con datos reales de Envíos:
     * valor = id (lo que persiste el comando), etiqueta = "referencia — cliente".
     *
     * @return array<string, string>
     */
    public static function shipmentSearch(string $search): array
    {
        $term = '%'.trim($search).'%';

        $options = [];
        $records = ShipmentRecord::query()
            ->where(static function (Builder $query) use ($term): void {
                $query->where('order_reference', 'ilike', $term)
                    ->orWhere('external_id', 'ilike', $term)
                    ->orWhere('customer_name', 'ilike', $term);
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        foreach ($records as $record) {
            $options[(string) $record->id] = self::shipmentLabel($record) ?? $record->external_id;
        }

        return $options;
    }

    /**
     * Etiqueta humana de un envío: "referencia del pedido — cliente" (o el id externo si no hay referencia).
     */
    public static function shipmentLabel(?ShipmentRecord $record): ?string
    {
        if (!$record instanceof ShipmentRecord) {
            return null;
        }

        $reference = $record->order_reference !== null && $record->order_reference !== ''
            ? $record->order_reference
            : $record->external_id;

        return "{$reference} — {$record->customer_name}";
    }

    /**
     * Tipos de carga conocidos (valor => etiqueta): los admitidos por los camiones con restricciones y los
     * ya usados en otros envíos.
     *
     * @return array<string, string>
     */
    private static function knownLoadTypes(): array
    {
        $types = [];
        foreach (VehicleRestrictionRecord::query()->whereNotNull('allowed_load_types')->limit(500)->get() as $restriction) {
            foreach ($restriction->allowed_load_types ?? [] as $type) {
                $types[$type] = $type;
            }
        }
        foreach (ShipmentRequirementRecord::query()->whereNotNull('load_type')->distinct()->limit(200)->pluck('load_type') as $type) {
            if (is_string($type) && $type !== '') {
                $types[$type] = $type;
            }
        }
        ksort($types);

        return $types;
    }
}
