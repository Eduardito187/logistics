<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\ImportSapOrders;

use Logistics\SapConnector\Application\Dto\ImportedOrderDto;
use Logistics\SapConnector\Application\Dto\ImportedOrderLineDto;
use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapOrderRef;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Lee pedidos de SAP (vía el puerto SapClient) y los traduce al modelo propio como ImportedOrderDto.
 *
 * Es la dirección de lectura de la capa anti-corrupción (ADR 002): cada material SAP se traduce al sku
 * propio con el mapa de traducción. NO importa el package shipments: devuelve DTOs (disponibles en
 * importedOrders()) que quien orqueste convertirá en el comando de recepción de aquel contexto.
 *
 * @implements CommandHandler<ImportSapOrders>
 */
final class ImportSapOrdersHandler implements CommandHandler
{
    /** @var list<ImportedOrderDto> */
    private array $importedOrders = [];

    public function __construct(
        private readonly SapClient $sap,
        private readonly MaterialMappingRepository $mappings,
    ) {}

    public function handle(Command $command): void
    {
        if (! $command instanceof ImportSapOrders) {
            throw SapConnectorException::because('ImportSapOrdersHandler solo maneja ImportSapOrders');
        }

        $orders = [];
        foreach ($this->sap->fetchOrders() as $rawOrder) {
            $orders[] = $this->translateOrder($rawOrder);
        }

        $this->importedOrders = $orders;
    }

    /**
     * Pedidos traducidos tras el último handle(). Vacío si SAP no devolvió pedidos.
     *
     * @return list<ImportedOrderDto>
     */
    public function importedOrders(): array
    {
        return $this->importedOrders;
    }

    /**
     * @param array<string, mixed> $rawOrder
     */
    private function translateOrder(array $rawOrder): ImportedOrderDto
    {
        $orderRef = SapOrderRef::of($this->stringField($rawOrder, 'order_ref'));

        $rawLines = $rawOrder['lines'] ?? [];
        if (! is_array($rawLines)) {
            throw SapConnectorException::because("el pedido SAP {$orderRef->value} no trae líneas válidas");
        }

        $lines = [];
        foreach ($rawLines as $rawLine) {
            if (! is_array($rawLine)) {
                throw SapConnectorException::because("una línea del pedido SAP {$orderRef->value} no es válida");
            }
            /** @var array<string, mixed> $rawLine */
            $lines[] = $this->translateLine($rawLine, $orderRef);
        }

        return new ImportedOrderDto(
            $orderRef->value,
            $this->stringField($rawOrder, 'customer_name'),
            $this->stringField($rawOrder, 'address_text'),
            $this->nullableStringField($rawOrder, 'address_reference'),
            $lines,
        );
    }

    /**
     * Traduce una línea SAP (material) a línea propia (sku), vía el mapa de traducción.
     *
     * @param array<string, mixed> $rawLine
     */
    private function translateLine(array $rawLine, SapOrderRef $orderRef): ImportedOrderLineDto
    {
        $material = SapMaterial::of($this->stringField($rawLine, 'material'));

        $mapping = $this->mappings->findByMaterial($material);
        if ($mapping === null) {
            throw SapConnectorException::because(
                "no hay mapeo para el material SAP {$material->value} del pedido {$orderRef->value}",
            );
        }

        $description = $this->nullableStringField($rawLine, 'description');

        return new ImportedOrderLineDto(
            $mapping->sku(),
            $description ?? $mapping->sku(),
            $this->intField($rawLine, 'quantity'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw SapConnectorException::because("campo SAP '{$key}' ausente o inválido");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStringField(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        throw SapConnectorException::because("campo SAP '{$key}' no es un entero válido");
    }
}
