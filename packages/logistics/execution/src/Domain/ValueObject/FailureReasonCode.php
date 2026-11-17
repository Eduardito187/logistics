<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Código estable de un motivo de entrega fallida del catálogo (slug en MAYÚSCULAS, p. ej. CUSTOMER_ABSENT).
 *
 * Es inmutable: identifica al motivo de forma estable para el contrato con la app y con tracking/
 * notifications, independiente de la etiqueta visible (que el admin puede renombrar). Se normaliza a
 * MAYÚSCULAS con guion bajo; nunca vacío.
 */
final readonly class FailureReasonCode
{
    private function __construct(public string $value) {}

    /**
     * Construye el código a partir de un slug ya conocido (lo normaliza igual que fromLabel para tolerar
     * minúsculas o espacios): MAYÚSCULAS, caracteres no alfanuméricos → guion bajo, sin guiones de borde.
     */
    public static function fromString(string $value): self
    {
        $normalized = self::normalize($value);
        if ($normalized === '') {
            throw ExecutionException::because('el código del motivo de entrega fallida no puede ser vacío');
        }

        return new self($normalized);
    }

    /**
     * Deriva un código a partir de una etiqueta legible (p. ej. "Customer absent" → "CUSTOMER_ABSENT").
     */
    public static function fromLabel(string $label): self
    {
        return self::fromString($label);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Normaliza a slug en MAYÚSCULAS: pasa a mayúsculas, reemplaza todo lo que no sea A-Z o 0-9 por guion
     * bajo, colapsa guiones repetidos y recorta los de los extremos.
     */
    private static function normalize(string $value): string
    {
        $upper = strtoupper(trim($value));
        $slug = (string) preg_replace('/[^A-Z0-9]+/', '_', $upper);

        return trim($slug, '_');
    }
}
