<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Ingestion;

use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Contract\ShipmentSpreadsheetParser;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\SourceReference;
use Throwable;

/**
 * Coordinador de la ingesta masiva de pedidos desde una planilla (carga manual/Excel, SHP-06).
 *
 * No es un comando CQRS ni abre transacción propia: parsea el archivo y, por CADA fila válida, despacha
 * el comando ÚNICO de ingreso (ReceiveShipment) por el CommandBus. Así cada pedido entra en su propia
 * transacción + outbox e, idempotente por (source, external_id), un reproceso del mismo archivo no
 * duplica nada. El resultado vuelve como un ImportReport fila por fila (importados, duplicados, fallidos),
 * sin que un error en una fila tumbe a las demás.
 */
final readonly class ImportShipments
{
    public function __construct(
        private ShipmentSpreadsheetParser $parser,
        private CommandBus $commandBus,
        private ShipmentRepository $shipments,
    ) {}

    public function import(string $contents): ImportReport
    {
        $rows = [];
        foreach ($this->parser->parse($contents) as $parsed) {
            $rows[] = $this->handleRow($parsed);
        }

        return ImportReport::of($rows);
    }

    /**
     * @return array{row: int, outcome: string, external_id: string|null, shipment_id: string|null, error: string|null}
     */
    private function handleRow(ParsedShipmentRow $parsed): array
    {
        if (!$parsed->isValid()) {
            return $this->row($parsed->rowNumber, ImportRowOutcome::Failed, null, null, $parsed->error);
        }

        /** @var ReceiveShipment $command */
        $command = $parsed->command;

        $reference = SourceReference::of(OrderSource::from($command->source), $command->externalId);
        $existing = $this->shipments->findBySourceReference($reference);

        if ($existing !== null) {
            return $this->row(
                $parsed->rowNumber,
                ImportRowOutcome::Duplicate,
                $command->externalId,
                $existing->id()->toString(),
                null,
            );
        }

        try {
            $this->commandBus->dispatch($command);
        } catch (Throwable $exception) {
            return $this->row(
                $parsed->rowNumber,
                ImportRowOutcome::Failed,
                $command->externalId,
                null,
                $exception->getMessage(),
            );
        }

        $saved = $this->shipments->findBySourceReference($reference);

        return $this->row(
            $parsed->rowNumber,
            ImportRowOutcome::Imported,
            $command->externalId,
            $saved?->id()->toString(),
            null,
        );
    }

    /**
     * @return array{row: int, outcome: string, external_id: string|null, shipment_id: string|null, error: string|null}
     */
    private function row(int $number, ImportRowOutcome $outcome, ?string $externalId, ?string $shipmentId, ?string $error): array
    {
        return [
            'row' => $number,
            'outcome' => $outcome->value,
            'external_id' => $externalId,
            'shipment_id' => $shipmentId,
            'error' => $error,
        ];
    }
}
