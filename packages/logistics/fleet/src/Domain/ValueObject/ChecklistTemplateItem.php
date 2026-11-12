<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Ítem de la plantilla del checklist pre-salida: clave estable (la que viaja en el POST del checklist),
 * texto visible para el chofer, categoría, criticidad y si exige foto de evidencia.
 *
 * La clave se normaliza a minúsculas sin espacios en los extremos: es el contrato con la app, que envía
 * el checklist ejecutado como mapa clave => verificado.
 */
final readonly class ChecklistTemplateItem
{
    private function __construct(
        public string $key,
        public string $label,
        public string $category,
        public ChecklistItemCriticality $criticality,
        public bool $requiresPhoto,
    ) {}

    public static function of(
        string $key,
        string $label,
        string $category,
        ChecklistItemCriticality $criticality,
        bool $requiresPhoto = false,
    ): self {
        $key = self::normalizeKey($key);
        if ($key === '' || preg_match('/^[a-z0-9_\-.]{1,64}$/', $key) !== 1) {
            throw FleetException::because('La clave del ítem del checklist debe tener 1-64 caracteres [a-z0-9_-.].');
        }
        $label = trim($label);
        if ($label === '') {
            throw FleetException::because("El ítem {$key} del checklist necesita un texto visible.");
        }
        $category = trim($category);
        if ($category === '') {
            throw FleetException::because("El ítem {$key} del checklist necesita una categoría.");
        }

        return new self($key, $label, $category, $criticality, $requiresPhoto);
    }

    /**
     * Normaliza una clave de ítem (la que manda la app) para compararla con la plantilla.
     */
    public static function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    /**
     * Reconstruye un ítem desde su forma primitiva (persistencia).
     *
     * @param array{key: string, label: string, category: string, criticality: string, requires_photo?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return self::of(
            $data['key'],
            $data['label'],
            $data['category'],
            ChecklistItemCriticality::from($data['criticality']),
            (bool) ($data['requires_photo'] ?? false),
        );
    }

    /**
     * @return array{key: string, label: string, category: string, criticality: string, requires_photo: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'category' => $this->category,
            'criticality' => $this->criticality->value,
            'requires_photo' => $this->requiresPhoto,
        ];
    }
}
