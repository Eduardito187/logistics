<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * CÓMO se entregó un envío: qué evidencia quedó de la entrega (firma del cliente, foto de la entrega,
 * documento de identidad). Solo tiene sentido para un envío ENTREGADO; para los demás se serializa null.
 *
 * Forma primitiva (tres booleanos): no trae archivos ni enlaces; para verlos está la galería de evidencia
 * de la entrega.
 */
final readonly class ShipmentDeliveryEvidenceView
{
    private function __construct(
        public bool $delivered,
        public bool $signature = false,
        public bool $photo = false,
        public bool $document = false,
    ) {}

    /** El envío todavía no se entregó: no hay evidencia de entrega que informar. */
    public static function notDelivered(): self
    {
        return new self(false);
    }

    /** Envío entregado, con la evidencia que quedó (puede no haber ninguna: los tres en false). */
    public static function delivered(bool $signature = false, bool $photo = false, bool $document = false): self
    {
        return new self(true, $signature, $photo, $document);
    }

    /**
     * @return array{signature: bool, photo: bool, document: bool}|null null si el envío no está entregado
     */
    public function toArray(): ?array
    {
        if (!$this->delivered) {
            return null;
        }

        return [
            'signature' => $this->signature,
            'photo' => $this->photo,
            'document' => $this->document,
        ];
    }
}
