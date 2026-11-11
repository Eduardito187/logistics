<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Resources\PrinterResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Logistics\Labels\Application\Command\RegisterPrinter\RegisterPrinter;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\PrinterRecord;
use Logistics\Labels\Presentation\Filament\Resources\PrinterResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de una impresora: despacha RegisterPrinter (el id UUID v7 se genera aquí para poder redirigir).
 */
final class CreatePrinter extends CreateRecord
{
    protected static string $resource = PrinterResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $printerId = Str::uuid7()->toString();

        app(CommandBus::class)->dispatch(new RegisterPrinter(
            printerId: $printerId,
            name: (string) $data['name'],
            model: PrinterFormData::optional($data['model'] ?? null),
            host: (string) $data['host'],
            port: (int) $data['port'],
            language: (string) $data['language'],
            nodeId: PrinterFormData::optional($data['node_id'] ?? null),
            location: PrinterFormData::optional($data['location'] ?? null),
            active: (bool) ($data['active'] ?? true),
        ));

        return PrinterRecord::query()->findOrFail($printerId);
    }
}
