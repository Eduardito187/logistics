<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\FleetDocumentModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource\Pages\CreateDocument;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource\Pages\ListDocuments;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource\Pages\ViewDocument;

/**
 * Resource de administración de documentos de flota (con vencimiento y archivo en S3).
 *
 * Filament usa FleetDocumentModel para LECTURA; el alta despacha RegisterDocument por el CommandBus. El
 * dueño (vehículo, chofer o ayudante) se elige con un select buscable cuyas opciones dependen del tipo
 * de dueño seleccionado, con datos reales. No se expone edición: un documento se registra y, si cambia,
 * se registra uno nuevo.
 *
 * Todo el texto visible va en inglés (regla 5 de CLAUDE.md).
 */
final class DocumentResource extends Resource
{
    protected static ?string $model = FleetDocumentModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Document');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Documents');
    }

    // Título legible del registro: el tipo de documento, nunca el UUID.
    protected static ?string $recordTitleAttribute = 'document_type';

    /**
     * Eager-load de los tres posibles dueños (vehículo, chofer, ayudante) para que el accesor
     * `ownerName` resuelva la etiqueta legible sin disparar N+1 por fila.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['ownerVehicle', 'ownerDriver', 'ownerHelper']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('owner_type')
                ->label(__('Owner type'))
                ->required()
                ->native(false)
                // `live` re-evalúa el select de dueño cuando cambia el tipo.
                ->live()
                // Al cambiar el tipo de dueño se limpia el dueño elegido (ya no aplica).
                ->afterStateUpdated(static function (Set $set): void {
                    $set('owner_id', null);
                })
                ->options(self::ownerTypeOptions()),

            Select::make('owner_id')
                ->label(__('Owner'))
                ->required()
                ->native(false)
                ->searchable()
                ->getSearchResultsUsing(static fn (string $search, Get $get): array => self::searchOwners((string) $get('owner_type'), $search))
                ->getOptionLabelUsing(static fn (?string $value, Get $get): ?string => self::ownerLabel((string) $get('owner_type'), (string) $value)),

            TextInput::make('document_type')
                ->label(__('Document type'))
                ->required()
                ->maxLength(50),

            DatePicker::make('expires_on')
                ->label(__('Expires on'))
                ->native(false)
                ->required()
                ->format('Y-m-d'),

            DatePicker::make('issued_on')
                ->label(__('Issued on'))
                ->native(false)
                ->format('Y-m-d'),

            FileUpload::make('file_path')
                ->label(__('File'))
                ->disk(self::documentsDisk())
                ->directory('fleet-documents')
                ->visibility('private')
                ->downloadable()
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png']),

            Textarea::make('note')
                ->label(__('Note'))
                ->maxLength(1000)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('owner_type')
                    ->label(__('Owner type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => __(DocumentOwnerType::from($state)->label())),
                // Etiqueta legible del dueño (placa o nombre) vía el accesor `ownerName`, que lee las
                // relaciones ya eager-cargadas; no se consulta por fila.
                TextColumn::make('ownerName')
                    ->label(__('Owner'))
                    ->placeholder('—'),
                TextColumn::make('document_type')
                    ->label(__('Document type'))
                    ->searchable(),
                TextColumn::make('expires_on')
                    ->label(__('Expires on'))
                    ->date('Y-m-d')
                    ->sortable()
                    // Badge por vencimiento: rojo si ya venció, ámbar si vence pronto, verde si vigente.
                    ->badge()
                    ->color(static fn (?string $state): string => self::expiryColor($state)),
            ])
            ->filters([
                SelectFilter::make('owner_type')
                    ->label(__('Owner type'))
                    ->native(false)
                    ->options(self::ownerTypeOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('expires_on');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('owner_type')
                ->label(__('Owner type'))
                ->badge()
                ->formatStateUsing(static fn (string $state): string => __(DocumentOwnerType::from($state)->label())),
            TextEntry::make('ownerName')
                ->label(__('Owner'))
                ->placeholder('—'),
            TextEntry::make('document_type')->label(__('Document type')),
            TextEntry::make('expires_on')
                ->label(__('Expires on'))
                ->date('Y-m-d')
                ->badge()
                ->color(static fn (?string $state): string => self::expiryColor($state)),
            TextEntry::make('issued_on')->label(__('Issued on'))->date('Y-m-d')->placeholder('—'),
            TextEntry::make('file_path')->label(__('File'))->placeholder('—'),
            TextEntry::make('note')->label(__('Note'))->placeholder('—'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'create' => CreateDocument::route('/create'),
            'view' => ViewDocument::route('/{record}'),
        ];
    }

    /**
     * Opciones del tipo de dueño con etiqueta en inglés (desde el enum del dominio).
     *
     * @return array<string, string>
     */
    public static function ownerTypeOptions(): array
    {
        $options = [];
        foreach (DocumentOwnerType::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Busca posibles dueños reales según el tipo seleccionado (placas o nombres).
     *
     * @return array<string, string>
     */
    private static function searchOwners(string $ownerType, string $search): array
    {
        return match ($ownerType) {
            DocumentOwnerType::Vehicle->value => VehicleModel::query()
                ->where('plate', 'ilike', "%{$search}%")
                ->orderBy('plate')
                ->limit(50)
                ->pluck('plate', 'id')
                ->all(),
            DocumentOwnerType::Driver->value => DriverModel::query()
                ->where('name', 'ilike', "%{$search}%")
                ->orderBy('name')
                ->limit(50)
                ->pluck('name', 'id')
                ->all(),
            DocumentOwnerType::Helper->value => HelperModel::query()
                ->where('name', 'ilike', "%{$search}%")
                ->orderBy('name')
                ->limit(50)
                ->pluck('name', 'id')
                ->all(),
            default => [],
        };
    }

    /**
     * Color semántico del badge de vencimiento según cuánto falta para que el documento venza.
     *
     * Rojo (danger) si ya venció, ámbar (warning) si vence dentro de 30 días, verde (success) si sigue
     * vigente, gris si no hay fecha. La fecha llega como texto 'Y-m-d' (Postgres).
     */
    private static function expiryColor(?string $expiresOn): string
    {
        if ($expiresOn === null || $expiresOn === '') {
            return 'gray';
        }

        $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiresOn);
        if ($expiry === false) {
            return 'gray';
        }

        $today = new \DateTimeImmutable('today');
        if ($expiry < $today) {
            return 'danger';
        }

        $daysLeft = (int) $today->diff($expiry)->format('%a');

        return $daysLeft <= 30 ? 'warning' : 'success';
    }

    /**
     * Etiqueta real del dueño (placa o nombre) según su tipo.
     */
    private static function ownerLabel(string $ownerType, string $ownerId): ?string
    {
        return match ($ownerType) {
            DocumentOwnerType::Vehicle->value => VehicleModel::query()->whereKey($ownerId)->value('plate'),
            DocumentOwnerType::Driver->value => DriverModel::query()->whereKey($ownerId)->value('name'),
            DocumentOwnerType::Helper->value => HelperModel::query()->whereKey($ownerId)->value('name'),
            default => null,
        };
    }

    /**
     * Disco S3 donde se guardan los archivos de documentos (configurable por env).
     */
    private static function documentsDisk(): string
    {
        $disk = config('fleet.documents_disk');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }
}
