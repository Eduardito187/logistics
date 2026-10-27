<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
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
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\CreateHelper;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\EditHelper;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\ListHelpers;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\ViewHelper;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shared\Presentation\Filament\ImageFallback;

/**
 * Resource de administración de ayudantes de la flota.
 *
 * Filament usa HelperModel para el lado de LECTURA (tabla y formulario). El alta despacha RegisterHelper
 * por el CommandBus. La edición despacha SetHelperAvailability (estado) y UpdateHelperProfile (datos
 * personales: foto, CI, nacimiento, teléfono secundario, dirección, contacto de emergencia y
 * referencias). El `phone` del alta es el teléfono primario y no se modifica luego.
 *
 * Todo el texto visible va en inglés (regla 5 de CLAUDE.md).
 */
final class HelperResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = HelperModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'ds-user-plus';

    protected static ?int $navigationSort = 14;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Helper');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Helpers');
    }

    // Título legible del registro: el nombre del ayudante, no el UUID.
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        // Nombre, teléfono primario y documentos de alta no se editan (no hay comando): deshabilitados.
        $lockedOnEdit = static fn (string $operation): bool => $operation === 'edit';

        return $schema->components([
            Section::make(__('Identity'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(150)
                        ->disabled($lockedOnEdit),

                    TextInput::make('phone')
                        ->label(__('Primary phone'))
                        ->tel()
                        ->maxLength(30)
                        ->disabled($lockedOnEdit),

                    Toggle::make('active')
                        ->label(__('Active'))
                        ->default(true),

                    Repeater::make('documents')
                        ->label(__('Documents'))
                        ->disabled($lockedOnEdit)
                        ->schema([
                            TextInput::make('type')
                                ->label(__('Document type'))
                                ->required()
                                ->maxLength(50),
                            DatePicker::make('expires_on')
                                ->label(__('Expires on'))
                                ->native(false)
                                ->required()
                                ->format('Y-m-d'),
                        ])
                        ->columns(2)
                        ->default([])
                        ->columnSpanFull(),
                ])
                ->columns(2),

            // Datos personales: solo se gestionan en edición (el alta solo registra identidad y documentos).
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

                    TextInput::make('phone_secondary')
                        ->label(__('Secondary phone'))
                        ->tel()
                        ->maxLength(40),

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
                TextColumn::make('phone')
                    ->label(__('Primary phone'))
                    ->placeholder('—'),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean(),
                TextColumn::make('documents')
                    ->label(__('Documents'))
                    ->formatStateUsing(static fn ($state): int => is_array($state) ? count($state) : 0),
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
                    TextEntry::make('phone')->label(__('Primary phone'))->placeholder('—'),
                    TextEntry::make('phone_secondary')->label(__('Secondary phone'))->placeholder('—'),
                    TextEntry::make('address')->label(__('Address'))->placeholder('—'),
                    TextEntry::make('emergency_contact_name')->label(__('Emergency contact name'))->placeholder('—'),
                    TextEntry::make('emergency_contact_phone')->label(__('Emergency contact phone'))->placeholder('—'),
                    IconEntry::make('active')->label(__('Active'))->boolean(),
                ])
                ->columns(2),

            Section::make(__('Hiring documents'))
                ->schema([
                    RepeatableEntry::make('documents')
                        ->label(__('Documents'))
                        ->placeholder('—')
                        ->schema([
                            TextEntry::make('type')->label(__('Document type')),
                            TextEntry::make('expires_on')->label(__('Expires on'))->date('Y-m-d'),
                        ])
                        ->columns(2),
                ]),

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

            // Documentos escaneados (CI, carné de salud, etc.) de este ayudante, filtrados por dueño.
            OwnerDocuments::infolistSection('helper'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListHelpers::route('/'),
            'create' => CreateHelper::route('/create'),
            'view' => ViewHelper::route('/{record}'),
            'edit' => EditHelper::route('/{record}/edit'),
        ];
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
}
