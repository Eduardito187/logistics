<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Throwable;

/**
 * Adapter del relay del outbox hacia SAP (ADR 002): cierra la cadena evento → outbox → SAP.
 *
 * El relay del outbox invoca este dispatcher cuando procesa un hecho del conector (prefijo de evento
 * `sap_connector.`). Es el ÚNICO camino asíncrono por el que un traspaso llega a SAP dentro del flujo
 * normal: el escaneo encola el traspaso Pending y graba el evento `stock_transfer_enqueued`; el relay,
 * fuera del request de usuario, trae ese evento acá y lo empuja a SAP por el puerto SapClient.
 *
 * Idempotente y tolerante a la reentrega at-least-once del outbox:
 *  - solo actúa sobre `stock_transfer_enqueued`; cualquier otro evento del prefijo (p. ej.
 *    `stock_transfer_acknowledged`) es un no-op, para no reenviar a SAP un hecho interno;
 *  - si el traspaso ya fue enviado o confirmado (no es despachable), no reenvía: una reentrega del
 *    mismo mensaje no duplica el movimiento;
 *  - la desduplicación de origen es el `event_id` de la recepción (clave del traspaso) y, del lado SAP,
 *    el header de idempotencia del HttpSapClient.
 *
 * Reintentos: si SAP falla, se marca el traspaso Failed (queda visible en el panel de reconciliación) y
 * se relanza la excepción, para que el relay aplique su backoff y reintente el mismo mensaje. El worker
 * de reconciliación (DispatchPendingTransfers) es la red de seguridad para los que quedan Failed tras
 * agotar los reintentos del outbox.
 */
final readonly class SapMessageDispatcher implements MessageDispatcher
{
    /**
     * Nombre del evento de encolado que dispara el empuje a SAP (ver StockTransferEnqueued::eventName()).
     */
    private const ENQUEUED_EVENT = 'sap_connector.stock_transfer_enqueued';

    public function __construct(
        private StockTransferRepository $transfers,
        private SapClient $sap,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        // Solo el hecho de encolado empuja a SAP; el resto de eventos del conector se ignoran.
        if ($message->eventName() !== self::ENQUEUED_EVENT) {
            return;
        }

        $eventId = $this->receptionEventId($message);
        if ($eventId === null) {
            // Sin clave de recepción no hay a qué traspaso apuntar: nada que empujar (defensivo).
            return;
        }

        $transfer = $this->transfers->findByEventId($eventId);
        if ($transfer === null) {
            // El traspaso pudo descartarse o aún no persistirse; no es un error del despacho.
            return;
        }

        // Idempotencia ante reentregas: si ya se envió o confirmó, no se reenvía.
        if (!$transfer->status()->isDispatchable()) {
            return;
        }

        $this->pushToSap($transfer);
    }

    /**
     * Empuja un traspaso a SAP. Éxito → Sent (a la espera del callback de confirmación). Fallo → Failed
     * con el motivo y se relanza, para que el relay reintente con backoff.
     */
    private function pushToSap(StockTransfer $transfer): void
    {
        try {
            $this->sap->pushStockTransfer($transfer);
        } catch (Throwable $exception) {
            $transfer->markFailed($exception->getMessage());
            $this->transfers->save($transfer);

            throw $exception;
        }

        $transfer->markSent();
        $this->transfers->save($transfer);
    }

    /**
     * Lee la clave de idempotencia de origen (event_id de la recepción) del payload del evento.
     */
    private function receptionEventId(OutboxMessage $message): ?string
    {
        $value = $message->payload()['reception_event_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
