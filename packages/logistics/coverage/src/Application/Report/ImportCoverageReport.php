<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Report;

/**
 * Colector del resultado de una importación de cobertura: qué zonas se crearon, cuáles se
 * actualizaron y qué errores hubo.
 *
 * Por qué un objeto mutable y no el valor de retorno del handler: en CQRS un CommandHandler es void
 * (escribe estado y publica eventos, no devuelve datos de lectura). Pero la acción de admin necesita
 * mostrarle al operador qué pasó. La solución es un colector de alcance de request, inyectado en el
 * handler y leído por Presentation tras el dispatch; se registra como singleton y se limpia con
 * reset() al inicio de cada importación para que cada ejecución parta de cero.
 *
 * No es un ReadModel (no viene de una consulta): es el registro en vivo de lo que hizo la escritura.
 */
final class ImportCoverageReport
{
    /** @var list<string> nombres de zonas dadas de alta */
    private array $created = [];

    /** @var list<string> nombres de zonas actualizadas (ya existían por su clave estable) */
    private array $updated = [];

    /** @var list<array{item: string, reason: string}> errores por ítem (zona o documento) */
    private array $errors = [];

    public function reset(): void
    {
        $this->created = [];
        $this->updated = [];
        $this->errors = [];
    }

    public function recordCreated(string $zoneName): void
    {
        $this->created[] = $zoneName;
    }

    public function recordUpdated(string $zoneName): void
    {
        $this->updated[] = $zoneName;
    }

    public function recordError(string $item, string $reason): void
    {
        $this->errors[] = ['item' => $item, 'reason' => $reason];
    }

    /**
     * @return list<string>
     */
    public function created(): array
    {
        return $this->created;
    }

    /**
     * @return list<string>
     */
    public function updated(): array
    {
        return $this->updated;
    }

    /**
     * @return list<array{item: string, reason: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function createdCount(): int
    {
        return count($this->created);
    }

    public function updatedCount(): int
    {
        return count($this->updated);
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Forma primitiva del reporte (regla 7: se expone el array, no el objeto).
     *
     * @return array{
     *     created: list<string>,
     *     updated: list<string>,
     *     errors: list<array{item: string, reason: string}>,
     *     createdCount: int,
     *     updatedCount: int,
     *     errorCount: int
     * }
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'errors' => $this->errors,
            'createdCount' => $this->createdCount(),
            'updatedCount' => $this->updatedCount(),
            'errorCount' => $this->errorCount(),
        ];
    }
}
