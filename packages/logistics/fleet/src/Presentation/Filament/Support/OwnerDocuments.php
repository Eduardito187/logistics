<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Logistics\Fleet\Application\Command\RegisterDocument\RegisterDocument;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentType;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\FleetDocumentModel;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\ImageFallback;
use Throwable;

/**
 * Helpers de presentación para los documentos de una entidad de la flota (vehículo, chofer o ayudante).
 *
 * El dueño de `fleet_documents` es polimórfico por valor (`owner_type` + `owner_id`), así que no hay una
 * relación Eloquent limpia sobre la que montar un RelationManager. En su lugar se ofrece:
 *  - una sección de infolist que LISTA los documentos del dueño (filtrados por owner_type/owner_id), y
 *  - una acción que REGISTRA un documento prellenando el dueño, despachando RegisterDocument por el bus.
 *
 * Se comparte entre VehicleResource, DriverResource y HelperResource para no duplicar el flujo. Todo
 * texto visible va en inglés (regla 5 de CLAUDE.md).
 */
final class OwnerDocuments
{
    /**
     * Sección de infolist que lista los documentos del dueño (más próximos a vencer primero).
     *
     * Cada documento muestra su tipo, nº de referencia (póliza/certificado), vencimiento, estado
     * (vigente/por vencer/vencido como badge), su imagen opcional (con ImageFallback) y el enlace al
     * archivo.
     *
     * @param string $ownerType uno de vehicle|driver|helper (valor del enum DocumentOwnerType)
     */
    public static function infolistSection(string $ownerType): Section
    {
        return Section::make(__('Documents'))
            ->schema([
                RepeatableEntry::make('fleet_documents')
                    ->label(__('Documents'))
                    ->placeholder('—')
                    // Estado cargado a demanda: documentos de ESTE dueño, no un atributo del modelo.
                    ->state(static fn (Model $record): array => self::documentsOf($ownerType, (string) $record->getKey()))
                    ->schema([
                        // Imagen del documento si el archivo es una foto; si no hay/falla, muestra el placeholder.
                        ImageEntry::make('image_url')
                            ->label(__('Image'))
                            ->square()
                            ->imageSize(56)
                            ->checkFileExistence(false)
                            ->defaultImageUrl(ImageFallback::placeholder('box'))
                            ->extraImgAttributes(ImageFallback::onError('box')),
                        TextEntry::make('document_type')->label(__('Document type')),
                        TextEntry::make('reference')->label(__('Reference'))->placeholder('—'),
                        TextEntry::make('expires_on')->label(__('Expires on'))->date('Y-m-d'),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge()
                            ->color(static fn (string $state): string => self::statusColor($state))
                            ->formatStateUsing(static fn (string $state): string => __(self::statusLabel($state))),
                        TextEntry::make('file_url')
                            ->label(__('File'))
                            ->placeholder('—')
                            ->formatStateUsing(static fn (?string $state): ?string => $state !== null && $state !== '' ? (string) __('Open file') : null)
                            ->url(static fn (?string $state): ?string => $state !== null && $state !== '' ? $state : null, shouldOpenInNewTab: true)
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->color('primary'),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * Acción que registra un documento para el dueño de la fila/página, prellenando owner_type/owner_id.
     *
     * El tipo se elige de un Select del enum FleetDocumentType; la opción `other` habilita un campo libre
     * para tipos no previstos (la columna `document_type` sigue siendo string).
     *
     * @param string $ownerType uno de vehicle|driver|helper (valor del enum DocumentOwnerType)
     */
    public static function registerAction(string $ownerType): Action
    {
        return Action::make('registerDocument')
            ->label(__('Register document'))
            ->icon('heroicon-o-document-plus')
            ->modalHeading(__('Register document'))
            ->schema([
                Select::make('document_type')
                    ->label(__('Document type'))
                    ->required()
                    ->native(false)
                    ->live()
                    ->options(self::documentTypeOptions()),

                TextInput::make('document_type_other')
                    ->label(__('Custom document type'))
                    ->maxLength(50)
                    // Solo aplica cuando el tipo elegido es "Other": permite un valor libre.
                    ->visible(static fn (Get $get): bool => $get('document_type') === FleetDocumentType::Other->value)
                    ->required(static fn (Get $get): bool => $get('document_type') === FleetDocumentType::Other->value),

                DatePicker::make('expires_on')
                    ->label(__('Expires on'))
                    ->native(false)
                    ->required()
                    ->format('Y-m-d'),

                DatePicker::make('issued_on')
                    ->label(__('Issued on'))
                    ->native(false)
                    ->format('Y-m-d'),

                TextInput::make('reference')
                    ->label(__('Reference'))
                    // Nº de póliza/serie (SOAT) o nº de certificado (inspección técnica).
                    ->helperText(__('Policy/serial number (SOAT) or certificate number (inspection).'))
                    ->maxLength(80),

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
            ])
            ->action(static function (Model $record, array $data) use ($ownerType): void {
                try {
                    app(CommandBus::class)->dispatch(new RegisterDocument(
                        ownerType: $ownerType,
                        ownerId: (string) $record->getKey(),
                        documentType: self::resolveDocumentType($data),
                        expiresOn: (string) $data['expires_on'],
                        issuedOn: isset($data['issued_on']) && $data['issued_on'] !== '' ? (string) $data['issued_on'] : null,
                        filePath: self::normalizeFilePath($data['file_path'] ?? null),
                        note: isset($data['note']) && $data['note'] !== '' ? (string) $data['note'] : null,
                        reference: isset($data['reference']) && trim((string) $data['reference']) !== '' ? trim((string) $data['reference']) : null,
                    ));
                } catch (FleetException $exception) {
                    Notification::make()
                        ->title(__('Could not register document'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Document registered'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Documentos del dueño, en forma primitiva para el RepeatableEntry (tipo legible, referencia,
     * vencimiento, estado, imagen y URL del archivo).
     *
     * @return array<int, array{document_type: string, reference: string|null, expires_on: string, status: string, image_url: string|null, file_url: string|null}>
     */
    private static function documentsOf(string $ownerType, string $ownerId): array
    {
        return FleetDocumentModel::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->orderBy('expires_on')
            ->get()
            ->map(static fn (FleetDocumentModel $document): array => [
                'document_type' => self::typeLabel($document->document_type),
                'reference' => $document->reference,
                'expires_on' => $document->expires_on,
                'status' => self::expiryStatus($document->expires_on),
                'image_url' => ImageFallback::diskUrl(self::documentsDisk(), $document->file_path),
                'file_url' => self::fileUrl($document->file_path),
            ])
            ->values()
            ->all();
    }

    /**
     * Estado del documento según su vencimiento: `expired` (vencido), `expiring` (vence dentro de 30
     * días) o `valid` (vigente). La fecha llega como texto 'Y-m-d' (Postgres).
     */
    private static function expiryStatus(string $expiresOn): string
    {
        $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiresOn);
        if ($expiry === false) {
            return 'valid';
        }

        $today = new \DateTimeImmutable('today');
        if ($expiry < $today) {
            return 'expired';
        }

        $daysLeft = (int) $today->diff($expiry)->format('%a');

        return $daysLeft <= 30 ? 'expiring' : 'valid';
    }

    /**
     * Color semántico del badge de estado del documento.
     */
    private static function statusColor(string $status): string
    {
        return match ($status) {
            'expired' => 'danger',
            'expiring' => 'warning',
            default => 'success',
        };
    }

    /**
     * Etiqueta en inglés del estado del documento (regla 5 de CLAUDE.md).
     */
    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'expired' => 'Expired',
            'expiring' => 'Expiring soon',
            default => 'Valid',
        };
    }

    /**
     * Opciones del tipo de documento con etiqueta en inglés (desde el enum del dominio).
     *
     * @return array<string, string>
     */
    private static function documentTypeOptions(): array
    {
        $options = [];
        foreach (FleetDocumentType::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Tipo de documento a persistir: el valor libre si se eligió "Other" y se escribió uno, si no el enum.
     *
     * @param array<string, mixed> $data
     */
    private static function resolveDocumentType(array $data): string
    {
        $type = (string) ($data['document_type'] ?? '');
        $custom = isset($data['document_type_other']) ? trim((string) $data['document_type_other']) : '';

        if ($type === FleetDocumentType::Other->value && $custom !== '') {
            return $custom;
        }

        return $type;
    }

    /**
     * Etiqueta legible del tipo conocido; si no está en el enum, muestra el valor crudo (tipo libre).
     */
    private static function typeLabel(string $type): string
    {
        $known = FleetDocumentType::tryFrom($type);

        return $known !== null ? (string) __($known->label()) : $type;
    }

    /**
     * Normaliza lo que entrega FileUpload (string o lista de un elemento) a un path o null.
     */
    private static function normalizeFilePath(mixed $filePath): ?string
    {
        if (is_array($filePath)) {
            $filePath = $filePath[array_key_first($filePath)] ?? null;
        }

        return is_string($filePath) && $filePath !== '' ? $filePath : null;
    }

    /**
     * Disco donde se guardan los archivos de documentos (configurable por env).
     */
    private static function documentsDisk(): string
    {
        $disk = config('fleet.documents_disk');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }

    /**
     * URL para ver/descargar el archivo del documento. Prefiere una URL temporal firmada (disco privado);
     * si el disco no la soporta, cae a la URL pública. Null si no hay archivo o no se puede construir una.
     */
    private static function fileUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk(self::documentsDisk());

        try {
            return $disk->temporaryUrl($path, now()->addMinutes(10));
        } catch (Throwable) {
            // El disco no soporta URLs temporales: intenta una URL normal.
        }

        try {
            return $disk->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
