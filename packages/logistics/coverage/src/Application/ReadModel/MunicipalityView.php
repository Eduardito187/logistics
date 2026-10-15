<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\ReadModel;

/**
 * Modelo de lectura de un municipio para poblar selects buscables del admin y respuestas de API.
 *
 * Trae el id del municipio, su nombre y el departamento al que pertenece (resuelto subiendo por la
 * jerarquía). El `label` es la etiqueta legible lista para mostrar ("Municipio — Departamento"), de modo
 * que otros contextos (p. ej. capacity, que elige municipio al activar cupos) no tengan que recomponerla.
 */
final readonly class MunicipalityView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $departmentId,
        public ?string $departmentName,
    ) {}

    /**
     * Etiqueta legible para selects: "Municipio — Departamento" (o solo el nombre si el departamento
     * no se pudo resolver en la cadena).
     */
    public function label(): string
    {
        return $this->departmentName === null
            ? $this->name
            : "{$this->name} — {$this->departmentName}";
    }

    /**
     * @return array{id: string, name: string, departmentId: string|null, departmentName: string|null, label: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'departmentId' => $this->departmentId,
            'departmentName' => $this->departmentName,
            'label' => $this->label(),
        ];
    }
}
