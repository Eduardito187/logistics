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

    /** Separador del nodo de retiro en el id externo de un envío hermano (multishipping). */
    public const NODE_SUFFIX = '#n:';

    /** UUID canónico (8-4-4-4-12 hex), en minúsculas tras normalizar. */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

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
     * Nodo de retiro codificado en el id externo, o null si el envío no lo trae.
     *
     * Regla (multishipping, ADR 018/019): cuando una orden se reparte a varios destinos, cada envío
     * hermano que va a un NODO (retiro en tienda / traspaso a CD) nace con id externo
     * `"{orden}#n:{uuidDelNodo}"`; el envío a domicilio conserva el id de la orden sin sufijo. Este método
     * lee ese sufijo para poblar el nodo destino aunque la fuente no lo haya mandado explícito. Solo
     * acepta un UUID bien formado: cualquier otro sufijo se ignora (null).
     */
    public function pickupNodeId(): ?string
    {
        $position = strrpos($this->externalId, self::NODE_SUFFIX);
        if ($position === false) {
            return null;
        }

        $candidate = strtolower(substr($this->externalId, $position + strlen(self::NODE_SUFFIX)));

        return preg_match(self::UUID_PATTERN, $candidate) === 1 ? $candidate : null;
    }

    /**
     * Representación estable del par, útil para logs y claves de caché.
     */
    public function toCanonical(): string
    {
        return "{$this->source->value}:{$this->externalId}";
    }
}
