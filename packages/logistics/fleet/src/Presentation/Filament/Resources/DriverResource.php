<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Logistics\Fleet\Domain\ValueObject\LicenseCategory;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\CreateDriver;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\EditDriver;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\ListDrivers;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\ViewDriver;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;
use Logistics\Shared\Presentation\Filament\ImageFallback;

/**
 * Resource de administración de choferes de la flota.
 *
 * Filament usa DriverModel para el lado de LECTURA (tabla y formulario). El alta despacha el comando
 * RegisterDriver por el CommandBus (solo los datos de licencia). La edición despacha UpdateDriverProfile
 * con los datos personales (foto, CI, nacimiento, teléfonos, dirección, categoría de licencia, contacto
 * de emergencia y referencias); los campos de registro (nombre, licencia, vehículos, estado) no son
 * mutables porque el dominio no tiene un comando para ello.
 *
 * Todo el texto visible va en inglés (regla 5 de CLAUDE.md).
 */
final class DriverResource extends Resource
{
    protected static ?string $model = DriverModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Driver');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Drivers');
    }

    // Título legible del registro: el nombre del chofer, no el UUID.
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        // Los datos de registro no se editan (no hay comando): quedan deshabilitados en edición.
        $lockedOnEdit = static fn (string $operation): bool => $operation === 'edit';

        return $schema->components([
            Section::make(__('License'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(150)
                        ->disabled($lockedOnEdit),

                    TextInput::make('license_number')
                        ->label(__('License number'))
                        ->required()
                        ->maxLength(50)
                        ->disabled($lockedOnEdit),

                    DatePicker::make('license_expiry')
                        ->label(__('License expiry'))
                        ->native(false)
                        ->required()
                        ->format('Y-m-d')
                        ->disabled($lockedOnEdit),

                    TextInput::make('license_document_type')
                        ->label(__('License document type'))
                        ->default('driver_license')
                        ->maxLength(50)
                        ->disabled($lockedOnEdit),

                    Select::make('enabled_vehicle_ids')
                        ->label(__('Enabled vehicles'))
                        ->multiple()
                        ->native(false)
                        ->searchable()
                        ->disabled($lockedOnEdit)
                        // Datos reales de la flota: búsqueda por placa.
                        ->getSearchResultsUsing(static fn (string $search): array => VehicleModel::query()
                            ->where('plate', 'ilike', "%{$search}%")
                            ->orderBy('plate')
                            ->limit(50)
                            ->pluck('plate', 'id')
                            ->all())
                        ->getOptionLabelsUsing(static fn (array $values): array => VehicleModel::query()
                            ->whereKey($values)
                            ->pluck('plate', 'id')
                            ->all()),

                    Toggle::make('active')
                        ->label(__('Active'))
                        ->default(true)
                        ->disabled($lockedOnEdit),
                ])
                ->columns(2),

            // Datos personales: solo se gestionan en edición (el alta solo registra la licencia).
            Section::make(__('Personal details'))
                ->visibleOn('edit')
                ->schema([
                    FileUpload::make('photo_path')
                        ->label(__('Photo'))
                        ->disk(self::documentsDisk())
                        ->directory('fleet-photos')
                        ->visibility('private')
                        ->image()
                        ->imageEditor()
                        ->acceptedFileTypes(['image/jpeg', 'image/png']),

                    DatePicker::make('birth_date')
                        ->label(__('Birth date'))
                        ->native(false)
                        ->format('Y-m-d')
                        ->maxDate(now()),

                    TextInput::make('national_id')
                        ->label(__('National ID'))
                        ->maxLength(40),

                    TextInput::make('phone_primary')
                        ->label(__('Primary phone'))
                        ->tel()
                        ->maxLength(40),

                    TextInput::make('phone_secondary')
                        ->label(__('Secondary phone'))
                        ->tel()
                        ->maxLength(40),

                    Select::make('license_category')
                        ->label(__('License category'))
                        ->native(false)
                        ->options(self::licenseCategoryOptions()),

                    Textarea::make('address')
                        ->label(__('Address'))
                        ->maxLength(1000)
                        ->columnSpanFull(),

                    TextInput::make('emergency_contact_name')
                        ->label(__('Emergency contact name'))
                        ->maxLength(160),

                    TextInput::make('emergency_contact_phone')
                        ->label(__('Emergency contact phone'))
                        ->tel()
                        ->maxLength(40),

                    Repeater::make('references')
                        ->label(__('Personal references'))
                        ->schema([
                            TextInput::make('name')
                                ->label(__('Name'))
                                ->required()
                                ->maxLength(160),
                            TextInput::make('relationship')
                                ->label(__('Relationship'))
                                ->maxLength(80),
                            TextInput::make('phone')
                                ->label(__('Phone'))
                                ->tel()
                                ->maxLength(40),
                            TextInput::make('note')
                                ->label(__('Note'))
                                ->maxLength(255),
                        ])
                        ->columns(2)
                        ->default([])
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Foto cuadrada pequeña (nunca un círculo gigante — ver gotcha 20 de la skill).
                ImageColumn::make('photo_path')
                    ->label(__('Photo'))
                    ->square()
                    ->imageSize(40)
                    ->checkFileExistence(false)
                    // La foto vive en disco (s3/privado): puede faltar o el disco no ser alcanzable. Se
                    // resuelve a una URL servible o null; con null, el defaultImageUrl pinta un avatar.
                    ->getStateUsing(static fn ($record): ?string => ImageFallback::diskUrl(self::documentsDisk(), $record->photo_path))
                    ->defaultImageUrl(ImageFallback::placeholder('avatar'))
                    ->extraImgAttributes(ImageFallback::onError('avatar')),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                // Edad derivada de la fecha de nacimiento (no es columna en la tabla).
                TextColumn::make('birth_date')
                    ->label(__('Age'))
                    ->placeholder('—')
                    ->formatStateUsing(static fn (?string $state): ?int => self::ageFromBirthDate($state)),
                TextColumn::make('license_number')
                    ->label(__('License number'))
                    ->searchable(),
                TextColumn::make('license_category')
                    ->label(__('License category'))
                    ->placeholder('—')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (?string $state): ?string => self::licenseCategoryLabel($state)),
                TextColumn::make('license_expiry')
                    ->label(__('License expiry'))
                    ->date('Y-m-d')
                    ->sortable()
                    // Badge por vencimiento de la licencia: rojo si venció, ámbar si vence pronto.
                    ->badge()
                    ->color(static fn (?string $state): string => self::expiryColor($state)),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label(__('Active')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Profile'))
                ->schema([
                    // Foto cuadrada pequeña (ver gotcha 20 de la skill): nunca circular gigante.
                    ImageEntry::make('photo_path')
                        ->label(__('Photo'))
                        ->square()
                        ->imageSize(56)
                        ->checkFileExistence(false)
                        ->getStateUsing(static fn ($record): ?string => ImageFallback::diskUrl(self::documentsDisk(), $record->photo_path))
                        ->defaultImageUrl(ImageFallback::placeholder('avatar'))
                        ->extraImgAttributes(ImageFallback::onError('avatar')),
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('national_id')->label(__('National ID'))->placeholder('—'),
                    TextEntry::make('birth_date')
                        ->label(__('Age'))
                        ->placeholder('—')
                        ->formatStateUsing(static fn (?string $state): ?int => self::ageFromBirthDate($state)),
                    TextEntry::make('phone_primary')->label(__('Primary phone'))->placeholder('—'),
                    TextEntry::make('phone_secondary')->label(__('Secondary phone'))->placeholder('—'),
                    TextEntry::make('address')->label(__('Address'))->placeholder('—'),
                    TextEntry::make('emergency_contact_name')->label(__('Emergency contact name'))->placeholder('—'),
                    TextEntry::make('emergency_contact_phone')->label(__('Emergency contact phone'))->placeholder('—'),
                ])
                ->columns(2),

            Section::make(__('License'))
                ->schema([
                    TextEntry::make('license_number')->label(__('License number'))->copyable(),
                    TextEntry::make('license_category')
                        ->label(__('License category'))
                        ->placeholder('—')
                        ->badge()
                        ->color('gray')
                        ->formatStateUsing(static fn (?string $state): ?string => self::licenseCategoryLabel($state)),
                    TextEntry::make('license_expiry')->label(__('License expiry'))->date('Y-m-d'),
                    IconEntry::make('active')->label(__('Active'))->boolean(),
                    TextEntry::make('enabled_vehicle_ids')
                        ->label(__('Enabled vehicles'))
                        ->badge()
                        ->placeholder('—')
                        // Muestra las placas reales de los vehículos habilitados para este chofer.
                        ->formatStateUsing(static fn (string $state): string => VehicleModel::query()
                            ->whereKey($state)
                            ->value('plate') ?? $state),
                ])
                ->columns(2),

            Section::make(__('Personal references'))
                ->schema([
                    RepeatableEntry::make('references')
                        ->label(__('Personal references'))
                        ->placeholder('—')
                        ->schema([
                            TextEntry::make('name')->label(__('Name')),
                            TextEntry::make('relationship')->label(__('Relationship'))->placeholder('—'),
                            TextEntry::make('phone')->label(__('Phone'))->placeholder('—'),
                            TextEntry::make('note')->label(__('Note'))->placeholder('—'),
                        ])
                        ->columns(2),
                ]),

            // Documentos (CI, licencia, carné de salud, etc.) de este chofer, filtrados por dueño.
            OwnerDocuments::infolistSection('driver'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDrivers::route('/'),
            'create' => CreateDriver::route('/create'),
            'view' => ViewDriver::route('/{record}'),
            'edit' => EditDriver::route('/{record}/edit'),
        ];
    }

    /**
     * Opciones de la categoría de licencia con etiqueta en inglés (desde el enum del dominio).
     *
     * @return array<string, string>
     */
    public static function licenseCategoryOptions(): array
    {
        $options = [];
        foreach (LicenseCategory::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Etiqueta legible de la categoría de licencia; null si no está registrada o no es válida.
     */
    private static function licenseCategoryLabel(?string $category): ?string
    {
        if ($category === null || $category === '') {
            return null;
        }

        $known = LicenseCategory::tryFrom($category);

        return $known !== null ? (string) __($known->label()) : $category;
    }

    /**
     * Edad en años cumplidos a hoy a partir de la fecha de nacimiento 'Y-m-d'; null si no hay fecha válida.
     */
    private static function ageFromBirthDate(?string $birthDate): ?int
    {
        if ($birthDate === null || $birthDate === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        if ($date === false) {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        if ($date > $today) {
            return 0;
        }

        return $date->diff($today)->y;
    }

    /**
     * Disco donde se guardan fotos y archivos de flota (configurable por env).
     */
    private static function documentsDisk(): string
    {
        $disk = config('fleet.documents_disk');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }

    /**
     * Color semántico del badge de vencimiento de la licencia según cuánto falta para que venza.
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
}
