<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * CÓMO se entregó un envío: qué evidencia quedó de la entrega (firma del cliente, foto de la entrega,
 * documento de identidad) y si se cerró con faltante. Solo tiene sentido para un envío ENTREGADO; para los
 * demás se serializa null.
 *
 * Forma primitiva: tres booleanos de evidencia, el indicador de faltante y, opcional, cuántos productos
 * faltaron. No trae archivos ni enlaces (para verlos está la galería de evidencia de la entrega) ni la lista
 * de productos faltantes (esos se ven por el reporte de discrepancias).
 */
final readonly class ShipmentDeliveryEvidenceView
{
    private function __construct(
        public bool $delivered,
        public bool $signature = false,
        public bool $photo = false,
        public bool $document = false,
        public bool $withShortage = false,
        public ?int $missingCount = null,
    ) {}

    /** El envío todavía no se entregó: no hay evidencia de entrega que informar. */
    public static function notDelivered(): self
    {
        return new self(false);
    }

    /**
     * Envío entregado, con la evidencia que quedó (puede no haber ninguna: los tres indicadores en false) y,
     * si el cierre fue con faltante, `$withShortage` en true y `$missingCount` con cuántos productos faltaron
     * (null cuando no hubo faltante o no se conoce la cantidad).
     */
    public static function delivered(
        bool $signature = false,
        bool $photo = false,
        bool $document = false,
        bool $withShortage = false,
        ?int $missingCount = null,
    ): self {
        return new self(true, $signature, $photo, $document, $withShortage, $missingCount);
    }

    /**
     * Forma primitiva de la evidencia, o null si el envío no está entregado.
     *
     * @return array{signature: bool, photo: bool, document: bool, with_shortage: bool, missing_count: int | null}|null
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
            // `with_shortage` SIEMPRE presente (false en entregas normales); `missing_count` opcional (null si
            // no hubo faltante o no se conoce). Los productos faltantes se ven por el reporte de discrepancias.
            'with_shortage' => $this->withShortage,
            'missing_count' => $this->missingCount,
        ];
    }
}
