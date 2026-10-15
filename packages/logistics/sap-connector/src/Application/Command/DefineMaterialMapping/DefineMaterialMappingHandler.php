<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\DefineMaterialMapping;

use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\MaterialMapping;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Define o actualiza el mapeo de un sku propio a su coordenada SAP. Idempotente por sku: si ya existe
 * un mapeo para ese sku, conserva su identidad y actualiza la coordenada; si no, crea uno nuevo.
 *
 * El mapa es configuración pura (no es raíz de agregado), así que no graba eventos de dominio.
 *
 * @implements CommandHandler<DefineMaterialMapping>
 */
final class DefineMaterialMappingHandler implements CommandHandler
{
    private ?MaterialMappingId $lastMappingId = null;

    public function __construct(
        private readonly MaterialMappingRepository $mappings,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof DefineMaterialMapping) {
            throw SapConnectorException::because('DefineMaterialMappingHandler solo maneja DefineMaterialMapping');
        }

        // Idempotencia por sku: reusar la identidad existente hace que save() actualice la misma fila.
        $existing = $this->mappings->findBySku($command->sku);
        $id = $existing !== null ? $existing->id() : $this->mappings->nextIdentity();

        $mapping = MaterialMapping::define(
            $id,
            $command->sku,
            SapMaterial::of($command->material),
            SapCenter::of($command->center),
            SapWarehouse::of($command->warehouse),
        );

        $this->mappings->save($mapping);
        $this->lastMappingId = $id;
    }

    /**
     * Id del mapeo definido (o del existente actualizado) tras el último handle().
     */
    public function lastMappingId(): MaterialMappingId
    {
        if ($this->lastMappingId === null) {
            throw SapConnectorException::because('todavía no se definió ningún mapeo en este handler');
        }

        return $this->lastMappingId;
    }
}
