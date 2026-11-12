<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

use Logistics\Fleet\Domain\Model\ChecklistTemplate;

/**
 * Proyección de lectura de la plantilla del checklist pre-salida que consume la app. Solo primitivos.
 *
 * Forma ESTABLE (schema OpenAPI `ChecklistTemplate`): la app arma la pantalla del checklist con esto, así
 * que renombrar una clave rompe el contrato.
 */
final readonly class ChecklistTemplateView
{
    /**
     * @param list<array{key: string, label: string, category: string, criticality: string, requires_photo: bool, position: int}> $items
     */
    private function __construct(
        public string $id,
        public string $name,
        public ?string $vehicleType,
        public bool $active,
        public array $items,
    ) {}

    public static function fromDomain(ChecklistTemplate $template): self
    {
        $items = [];
        foreach ($template->items() as $position => $item) {
            $items[] =$item->toArray() + ['position' => $position + 1];
        }

        return new self(
            $template->id()->toString(),
            $template->name(),
            $template->vehicleType(),
            $template->isActive(),
            $items,
        );
    }

    /**
     * Claves de los ítems críticos (atajo para la app: si alguno falla, no apto).
     *
     * @return list<string>
     */
    public function criticalKeys(): array
    {
        $keys = [];
        foreach ($this->items as $item) {
            if ($item['criticality'] === 'critical') {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    /**
     * @return array{id: string, name: string, vehicle_type: string|null, active: bool, items: list<array{key: string, label: string, category: string, criticality: string, requires_photo: bool, position: int}>, critical_keys: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'vehicle_type' => $this->vehicleType,
            'active' => $this->active,
            'items' => $this->items,
            'critical_keys' => $this->criticalKeys(),
        ];
    }
}
