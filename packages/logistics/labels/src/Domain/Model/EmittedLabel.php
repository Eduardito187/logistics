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
        public ?int $revokedAt,
        public ?string $revokedReason,
    ) {}

    public static function of(
        string $orderNumber,
        SignedLabel $label,
        string $zpl,
        int $emittedAt,
        ?int $revokedAt = null,
        ?string $revokedReason = null,
    ): self {
        return new self($orderNumber, $label, $zpl, $emittedAt, $revokedAt, $revokedReason);
    }

    /** Una etiqueta revocada (p. ej. por reimpresión) sigue almacenada pero ya no es válida (ADR 004). */
    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
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

    /** Código de ORIGEN embebido en el payload del QR (campo `org`); free-form, suele ser un code de nodo. */
    public function originCode(): string
    {
        return $this->label->payload->origin;
    }

    /** Código de DESTINO embebido en el payload del QR (campo `dst`); free-form (code de nodo o zona). */
    public function destinationCode(): string
    {
        return $this->label->payload->destination;
    }

    /** Índice de UNIDAD del bulto dentro de su línea (1-based). */
    public function unitIndex(): int
    {
        return $this->label->payload->unit->index;
    }

    /** Total de unidades de la línea. */
    public function unitTotal(): int
    {
        return $this->label->payload->unit->total;
    }

    /** Índice de PIEZA del bulto dentro de su unidad (1-based). */
    public function pieceIndex(): int
    {
        return $this->label->payload->piece->index;
    }

    /** Total de piezas por unidad. */
    public function pieceTotal(): int
    {
        return $this->label->payload->piece->total;
    }

    /** El JSON firmado exacto del QR: lo que se guarda y lo que escanea la app. */
    public function signedJson(): string
    {
        return $this->label->toQrContent();
    }
}
