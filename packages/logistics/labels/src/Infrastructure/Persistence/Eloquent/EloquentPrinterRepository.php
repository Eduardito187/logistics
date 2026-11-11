<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Persistence\Eloquent;

use Logistics\Labels\Domain\Contract\PrinterRepository;
use Logistics\Labels\Domain\Model\Printer;
use Logistics\Labels\Domain\ValueObject\PrinterEndpoint;
use Logistics\Labels\Domain\ValueObject\PrinterId;
use Logistics\Labels\Domain\ValueObject\PrinterLanguage;

/**
 * Persistencia Eloquent del agregado Printer sobre `label_printers`.
 */
final class EloquentPrinterRepository implements PrinterRepository
{
    public function find(PrinterId $id): ?Printer
    {
        $record = PrinterRecord::query()->find($id->toString());

        if ($record === null) {
            return null;
        }

        return Printer::reconstitute(
            id: PrinterId::fromString($record->id),
            name: $record->name,
            model: $record->model,
            endpoint: PrinterEndpoint::of($record->host, $record->port),
            language: PrinterLanguage::tryFrom($record->language) ?? PrinterLanguage::Other,
            nodeId: $record->node_id,
            location: $record->location,
            active: $record->active,
        );
    }

    public function save(Printer $printer): void
    {
        PrinterRecord::query()->updateOrCreate(
            ['id' => $printer->id()->toString()],
            [
                'name' => $printer->name(),
                'model' => $printer->model(),
                'host' => $printer->endpoint()->host,
                'port' => $printer->endpoint()->port,
                'language' => $printer->language()->value,
                'node_id' => $printer->nodeId(),
                'location' => $printer->location(),
                'active' => $printer->isActive(),
            ],
        );
    }

    public function remove(PrinterId $id): void
    {
        PrinterRecord::query()->whereKey($id->toString())->delete();
    }
}
