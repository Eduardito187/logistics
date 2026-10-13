<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Checklist pre-salida: lista de ítems a verificar antes de que la tripulación salga a ruta.
 *
 * Cada ítem es un par nombre => verificado. Es un VO inmutable: marcar un ítem devuelve una copia
 * nueva. Está completo cuando no quedan ítems sin verificar (y hay al menos uno).
 */
final readonly class PreTripChecklist
{
    /**
     * @param array<string, bool> $items
     */
    private function __construct(
        public array $items,
    ) {}

    /**
     * @param array<string, bool> $items
     */
    public static function of(array $items): self
    {
        if ($items === []) {
            throw FleetException::because('El checklist pre-salida no puede estar vacío.');
        }
        foreach ($items as $name => $checked) {
            if (trim((string) $name) === '') {
                throw FleetException::because('Un ítem del checklist no puede tener nombre vacío.');
            }
        }

        return new self($items);
    }

    /**
     * Marca un ítem como verificado y devuelve una copia nueva (inmutable).
     */
    public function check(string $item): self
    {
        if (!array_key_exists($item, $this->items)) {
            throw FleetException::because("El ítem no existe en el checklist: {$item}.");
        }
        $items = $this->items;
        $items[$item] = true;

        return new self($items);
    }

    public function isComplete(): bool
    {
        foreach ($this->items as $checked) {
            if (!$checked) {
                return false;
            }
        }

        return true;
    }

    /**
     * Nombres de los ítems que todavía faltan verificar.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $pending = [];
        foreach ($this->items as $name => $checked) {
            if (!$checked) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->items;
    }
}
