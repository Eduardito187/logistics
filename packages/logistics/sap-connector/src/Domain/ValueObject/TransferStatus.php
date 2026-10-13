<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

/**
 * Estado de un traspaso de stock a lo largo de su despacho asíncrono hacia SAP (ADR 002).
 *
 * - Pending: encolado al recibir en el nodo; todavía sin empujar a SAP. El escaneo nunca lo espera.
 * - Sent: empujado a SAP por el worker de despacho; a la espera de confirmación.
 * - Acknowledged: SAP confirmó el traspaso. Estado terminal feliz.
 * - Failed: el empuje falló; reintentable y visible en el panel de reconciliación.
 */
enum TransferStatus: string
{
    case Pending = 'PENDING';

    case Sent = 'SENT';

    case Acknowledged = 'ACKNOWLEDGED';

    case Failed = 'FAILED';

    /**
     * ¿Está pendiente de empuje a SAP? El worker de despacho toma solo estos (Pending y Failed).
     */
    public function isDispatchable(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    public function isTerminal(): bool
    {
        return $this === self::Acknowledged;
    }
}
