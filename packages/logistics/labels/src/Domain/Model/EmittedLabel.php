<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Model;

use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Una etiqueta ya emitida: la firma más su representación impresa (ZPL), lista para persistir.
 *
 * El `id` de almacenamiento (uuid pk de la fila) es un detalle de infraestructura y NO vive acá: la
 * identidad de negocio es `labelId()` (el campo `id` del payload), único por bulto físico. Ese campo
 * es el que lleva índice único en la base para detectar duplicados (ADR 004).
 */
final readonly class EmittedLabel
{
    private function __construct(
        public string $orderNumber,
        public SignedLabel $label,
        public string $zpl,
        public int $emittedAt,
    ) {}

    public static function of(string $orderNumber, SignedLabel $label, string $zpl, int $emittedAt): self
    {
        return new self($orderNumber, $label, $zpl, $emittedAt);
    }

    /** Identidad de negocio: el `id` del payload, único por bulto físico. */
    public function labelId(): string
    {
        return $this->label->payload->id;
    }

    /** Clave con la que se firmó (campo `k`): permite rotación sin invalidar lo ya impreso. */
    public function keyId(): int
    {
        return $this->label->payload->keyId;
    }

    public function sku(): string
    {
        return $this->label->payload->sku;
    }

    public function lineNumber(): int
    {
        return $this->label->payload->lineNumber;
    }

    /** El JSON firmado exacto del QR: lo que se guarda y lo que escanea la app. */
    public function signedJson(): string
    {
        return $this->label->toQrContent();
    }
}
