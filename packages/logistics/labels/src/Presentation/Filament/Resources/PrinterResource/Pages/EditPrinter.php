<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Resources\PrinterResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Labels\Application\Command\UpdatePrinter\UpdatePrinter;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\PrinterRecord;
use Logistics\Labels\Presentation\Filament\Resources\PrinterResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de una impresora: despacha UpdatePrinter (cambiar host/puerto se refleja en la app al instante).
 */
final class EditPrinter extends EditRecord
{
    protected static string $resource = PrinterResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $printerId = (string) $record->getKey();

        app(CommandBus::class)->dispatch(new UpdatePrinter(
            printerId: $printerId,
            name: (string) $data['name'],
            model: PrinterFormData::optional($data['model'] ?? null),
            host: (string) $data['host'],
            port: (int) $data['port'],
            language: (string) $data['language'],
            nodeId: PrinterFormData::optional($data['node_id'] ?? null),
            location: PrinterFormData::optional($data['location'] ?? null),
            active: (bool) ($data['active'] ?? false),
        ));

        return PrinterRecord::query()->findOrFail($printerId);
    }
}
