<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\ReadModel;

use Logistics\Labels\Domain\Model\EmittedLabel;

/**
 * Modelo de lectura de una etiqueta emitida: forma plana y primitiva, lista para la API o la app.
 *
 * `qrContent` es el JSON firmado exacto (lo que lleva el QR); `zpl` es lo que se manda a la impresora.
 * Si se cachea, se cachea su toArray() (regla 7 del proyecto), nunca el objeto de dominio.
 */
final readonly class EmittedLabelView
{
    public function __construct(
        public string $orderNumber,
        public string $labelId,
        public int $keyId,
        public string $sku,
        public int $lineNumber,
        public string $qrContent,
        public string $zpl,
        public int $emittedAt,
        public bool $revoked,
        public ?int $revokedAt,
        public ?string $revokedReason,
    ) {}

    public static function fromEmittedLabel(EmittedLabel $label): self
    {
        return new self(
            $label->orderNumber,
            $label->labelId(),
            $label->keyId(),
            $label->sku(),
            $label->lineNumber(),
            $label->signedJson(),
            $label->zpl,
            $label->emittedAt,
            $label->isRevoked(),
            $label->revokedAt,
            $label->revokedReason,
        );
    }

    /**
     * @return array{order_number: string, label_id: string, key_id: int, sku: string, line_number: int, qr_content: string, zpl: string, emitted_at: int, revoked: bool, revoked_at: int|null, revoked_reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'order_number' => $this->orderNumber,
            'label_id' => $this->labelId,
            'key_id' => $this->keyId,
            'sku' => $this->sku,
            'line_number' => $this->lineNumber,
            'qr_content' => $this->qrContent,
            'zpl' => $this->zpl,
            'emitted_at' => $this->emittedAt,
            'revoked' => $this->revoked,
            'revoked_at' => $this->revokedAt,
            'revoked_reason' => $this->revokedReason,
        ];
    }
}
