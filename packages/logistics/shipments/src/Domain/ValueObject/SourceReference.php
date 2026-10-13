<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Referencia al pedido en su fuente de origen: el par (fuente, id externo).
 *
 * Es la clave de idempotencia del ingreso: dos veces el mismo pedido de la misma fuente no debe crear
 * dos envíos. En la base se respalda con un índice único (source, external_id).
 */
final readonly class SourceReference
{
    private const MAX_LENGTH = 120;

    private function __construct(
        public OrderSource $source,
        public string $externalId,
    ) {}

    public static function of(OrderSource $source, string $externalId): self
    {
        $trimmed = trim($externalId);
        if ($trimmed === '') {
            throw ShipmentsException::because('el id externo de la fuente no puede estar vacío');
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw ShipmentsException::because('el id externo excede '.self::MAX_LENGTH.' caracteres');
        }

        return new self($source, $trimmed);
    }

    public function equals(self $other): bool
    {
        return $this->source === $other->source && $this->externalId === $other->externalId;
    }

    /**
     * Representación estable del par, útil para logs y claves de caché.
     */
    public function toCanonical(): string
    {
        return "{$this->source->value}:{$this->externalId}";
    }
}
