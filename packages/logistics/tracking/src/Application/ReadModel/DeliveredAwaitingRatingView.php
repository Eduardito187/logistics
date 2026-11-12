<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

/**
 * Envío entregado que todavía espera la invitación a calificar (insumo del cron horario de la encuesta).
 *
 * Lleva el email del cliente: es un read model INTERNO (proceso de invitación), jamás se expone en la
 * página pública ni en la API de canales. `trackingToken` arma el enlace `/t/{token}`; `trackingPath` es
 * ese camino relativo, para que el emisor solo le anteponga el host. Si se cachea, se cachea toArray().
 */
final readonly class DeliveredAwaitingRatingView
{
    public function __construct(
        public string $shipmentId,
        public ?string $orderReference,
        public ?string $customerName,
        public string $customerEmail,
        public string $deliveredAt,
        public string $trackingToken,
    ) {}

    /** Camino relativo de la página pública de seguimiento (y encuesta) del envío. */
    public function trackingPath(): string
    {
        return '/t/'.$this->trackingToken;
    }

    /**
     * @return array{shipment_id: string, order_reference: string|null, customer_name: string|null, customer_email: string, delivered_at: string, tracking_token: string, tracking_path: string}
     */
    public function toArray(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'order_reference' => $this->orderReference,
            'customer_name' => $this->customerName,
            'customer_email' => $this->customerEmail,
            'delivered_at' => $this->deliveredAt,
            'tracking_token' => $this->trackingToken,
            'tracking_path' => $this->trackingPath(),
        ];
    }
}
