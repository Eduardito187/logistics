<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipmentHandler;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Throwable;

/**
 * Ingesta manual de un pedido desde la consola: la fuente "MANUAL" del modelo de recepción.
 *
 * Pensado para pruebas y cargas puntuales. La línea se pasa como "sku:nombre:cantidad" y puede
 * repetirse. Presentation habla con Application (el handler), nunca con Infrastructure ni el dominio.
 */
final class ReceiveManualShipmentCommand extends Command
{
    protected $signature = 'logistics:shipments:receive-manual
        {external-id : External reference of the order in its source}
        {customer : Customer name}
        {address : Delivery address (free text)}
        {--reference= : Extra address reference / directions}
        {--lat= : Latitude in decimal degrees}
        {--lng= : Longitude in decimal degrees}
        {--line=* : Order line as "sku:product name:quantity" (repeatable)}';

    protected $description = 'Receive a shipment manually into the platform (source: MANUAL)';

    public function handle(ReceiveShipmentHandler $handler): int
    {
        $lines = [];
        foreach ($this->parseLines() as $line) {
            $lines[] = $line;
        }

        if ($lines === []) {
            $this->error('At least one --line "sku:product name:quantity" is required.');

            return self::FAILURE;
        }

        $command = new ReceiveShipment(
            source: 'MANUAL',
            externalId: (string) $this->argument('external-id'),
            customerName: (string) $this->argument('customer'),
            addressText: (string) $this->argument('address'),
            addressReference: $this->stringOption('reference'),
            lat: $this->floatOption('lat'),
            lng: $this->floatOption('lng'),
            lines: $lines,
        );

        try {
            $handler->handle($command);
        } catch (Throwable $exception) {
            $this->error('Could not receive the shipment: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Shipment received: '.$handler->lastShipmentId()->toString());

        return self::SUCCESS;
    }

    /**
     * @return list<ShipmentLineInput>
     */
    private function parseLines(): array
    {
        /** @var list<string> $raw */
        $raw = (array) $this->option('line');

        $lines = [];
        foreach ($raw as $entry) {
            $parts = explode(':', $entry, 3);
            if (count($parts) !== 3) {
                $this->warn("Ignored malformed line (expected sku:product name:quantity): {$entry}");

                continue;
            }
            [$sku, $name, $quantity] = $parts;
            $lines[] = new ShipmentLineInput($sku, $name, (int) $quantity);
        }

        return $lines;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function floatOption(string $name): ?float
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? (float) $value : null;
    }
}
