<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\ReadModel;

/**
 * Impresora de red tal como la consume la app (contrato GET /api/v1/app/printers).
 *
 * `node_id` es el UUID del nodo de Network (string) y `node_name` su nombre legible, para que la app lo
 * muestre sin una segunda consulta. `language`: zpl | other.
 */
final readonly class PrinterView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $model,
        public string $host,
        public int $port,
        public string $language,
        public ?string $nodeId,
        public ?string $nodeName,
        public ?string $location,
    ) {}

    /**
     * @return array{id: string, name: string, model: string|null, host: string, port: int, language: string, node_id: string|null, node_name: string|null, location: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'model' => $this->model,
            'host' => $this->host,
            'port' => $this->port,
            'language' => $this->language,
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'location' => $this->location,
        ];
    }
}
