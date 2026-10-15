<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Shipments\Application\Ingestion\ImportShipments;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;
use Throwable;

/**
 * Listado de pedidos recibidos en el admin.
 *
 * Expone los FLUJOS de negocio del contexto como acciones de cabecera (no un CRUD): el alta de un
 * pedido manual despacha ReceiveShipment por el CommandBus y la carga masiva pasa por el coordinador
 * de importación (ImportShipments), que a su vez despacha un ReceiveShipment por fila. Presentation
 * nunca escribe el Record directo: toda escritura de negocio va por el caso de uso (invariantes +
 * outbox transaccional). Todo texto visible va en inglés (regla 5 de CLAUDE.md).
 */
final class ListShipments extends ListRecords
{
    protected static string $resource = ShipmentResource::class;

    /**
     * Fuentes de origen seleccionables al recibir un pedido manual. Las claves (valores del enum
     * OrderSource) quedan crudas; solo las etiquetas visibles pasan por __() para poder traducirse.
     *
     * @return array<string, string>
     */
    private static function sources(): array
    {
        return [
            'MANUAL' => __('Manual'),
            'STORE' => __('Store'),
            'EXCEL' => __('Excel'),
            'MAGENTO' => __('Magento'),
            'SAP' => __('SAP'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->receiveOrderAction(),
            $this->importSpreadsheetAction(),
        ];
    }

    /**
     * Flujo "Receive order": alta transaccional de un pedido despachando ReceiveShipment por el
     * CommandBus (idempotente por source + external reference). El id lo genera el dominio.
     */
    private function receiveOrderAction(): Action
    {
        return Action::make('receiveOrder')
            ->label(__('Receive order'))
            ->icon('heroicon-o-inbox-arrow-down')
            ->modalHeading(__('Receive order'))
            ->modalDescription(__('Register an incoming order. The order enters through the single receive use case (transaction + outbox).'))
            ->modalSubmitActionLabel(__('Receive'))
            ->schema([
                Select::make('source')
                    ->label(__('Source'))
                    ->options(self::sources())
                    ->default('MANUAL')
                    ->native(false)
                    ->required(),
                TextInput::make('external_id')
                    ->label(__('External reference'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('customer_name')
                    ->label(__('Customer name'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('address_text')
                    ->label(__('Delivery address'))
                    ->required()
                    ->rows(2)
                    ->maxLength(1000),
                Textarea::make('address_reference')
                    ->label(__('Address reference'))
                    ->rows(2)
                    ->maxLength(1000),
                TextInput::make('lat')
                    ->label(__('Latitude'))
                    ->numeric()
                    ->minValue(-90)
                    ->maxValue(90),
                TextInput::make('lng')
                    ->label(__('Longitude'))
                    ->numeric()
                    ->minValue(-180)
                    ->maxValue(180),
                Repeater::make('lines')
                    ->label(__('Order lines'))
                    ->schema([
                        TextInput::make('sku')
                            ->label(__('SKU'))
                            ->required()
                            ->maxLength(64),
                        TextInput::make('product_name')
                            ->label(__('Product name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('quantity')
                            ->label(__('Quantity'))
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->default(1),
                    ])
                    ->columns(3)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel(__('Add line')),
            ])
            ->action(function (array $data): void {
                /** @var list<array{sku: string, product_name: string, quantity: int|string}> $rawLines */
                $rawLines = array_values($data['lines'] ?? []);

                $lines = [];
                foreach ($rawLines as $line) {
                    $lines[] = new ShipmentLineInput(
                        (string) $line['sku'],
                        (string) $line['product_name'],
                        (int) $line['quantity'],
                    );
                }

                $command = new ReceiveShipment(
                    source: (string) $data['source'],
                    externalId: (string) $data['external_id'],
                    customerName: (string) $data['customer_name'],
                    addressText: (string) $data['address_text'],
                    addressReference: isset($data['address_reference']) && $data['address_reference'] !== ''
                        ? (string) $data['address_reference']
                        : null,
                    lat: isset($data['lat']) && $data['lat'] !== '' ? (float) $data['lat'] : null,
                    lng: isset($data['lng']) && $data['lng'] !== '' ? (float) $data['lng'] : null,
                    lines: $lines,
                );

                try {
                    app(CommandBus::class)->dispatch($command);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title(__('Order could not be received'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Order received'))
                    ->body(__('Reference :reference from :source', [
                        'reference' => $command->externalId,
                        'source' => self::sources()[$command->source] ?? $command->source,
                    ]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Flujo "Import spreadsheet": carga masiva de pedidos desde CSV. Delega en el coordinador
     * ImportShipments (Application), que parsea e ingresa fila por fila por ReceiveShipment —cada
     * pedido en su propia transacción + outbox, idempotente—. Devuelve el reporte importados /
     * duplicados / fallidos sin frenar el resto por una fila mala.
     */
    private function importSpreadsheetAction(): Action
    {
        return Action::make('importSpreadsheet')
            ->label(__('Import spreadsheet'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading(__('Import orders from spreadsheet'))
            ->modalDescription(__('Upload a CSV with columns: source, external_id, customer_name, address_text, address_reference, lat, lng, sku, product_name, quantity.'))
            ->modalSubmitActionLabel(__('Import'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('Spreadsheet (CSV)'))
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $upload = $data['file'] ?? null;
                if (is_array($upload)) {
                    $upload = reset($upload);
                }

                if (!$upload instanceof UploadedFile) {
                    Notification::make()
                        ->title(__('A CSV file is required'))
                        ->danger()
                        ->send();

                    return;
                }

                $contents = (string) file_get_contents($upload->getRealPath());

                $report = app(ImportShipments::class)->import($contents);

                $notification = Notification::make()
                    ->title(__('Import finished'))
                    ->body(__('Imported :imported, duplicates :duplicates, failed :failed of :total rows.', [
                        'imported' => $report->imported,
                        'duplicates' => $report->duplicates,
                        'failed' => $report->failed,
                        'total' => $report->total(),
                    ]));

                ($report->failed > 0 ? $notification->warning() : $notification->success())->send();
            });
    }
}
