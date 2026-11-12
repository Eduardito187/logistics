<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Notification;

use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Contract\SapNotificationRepository;
use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationStatus;
use Logistics\Shared\Domain\Clock\Clock;
use Throwable;

/**
 * Caso de uso: enviar UN aviso a SAP y dejar asentado el resultado. Lo invoca SOLO el job de la cola
 * `sap`, fuera de cualquier transacción (el HTTP nunca retiene una transacción abierta).
 *
 * - 2xx ⇒ Sent, con el código y el cuerpo crudo (truncado) de la respuesta.
 * - No 2xx, timeout, red caída o breaker abierto ⇒ Failed, intento sumado, motivo y respuesta (si la
 *   hubo) guardados, y próximo intento con backoff; agotados los intentos (3 por defecto) se deja de
 *   insistir. Sin dead-letter ni acción manual obligatoria.
 * - Conector apagado o sin URL al momento de enviar ⇒ Skipped.
 *
 * NUNCA lanza hacia arriba: devuelve el estado final para el log. Nada de lo que haga SAP afecta a
 * nuestra plataforma.
 */
final readonly class DeliverSapNotification
{
    public function __construct(
        private SapNotificationRepository $notifications,
        private SapClient $sap,
        private SapNotificationPolicy $policy,
        private Clock $clock,
    ) {}

    public function deliver(SapNotificationId $id): ?SapNotificationStatus
    {
        $notification = $this->notifications->findById($id);
        if ($notification === null || !$notification->canAttempt($this->policy->attempts())) {
            // Ya enviado, saltado, agotado o inexistente: nada que hacer (idempotente ante reentregas).
            return $notification?->status();
        }

        if ($this->policy->deliveryEnabled) {
            $this->attempt($notification);
        } else {
            $notification->skip();
        }

        $this->notifications->save($notification);

        return $notification->status();
    }

    /**
     * Un intento de envío: asienta éxito o fallo (excepción de transporte o respuesta no 2xx) sobre el
     * aviso, sin guardarlo (lo guarda quien llama). Nunca lanza.
     */
    private function attempt(SapNotification $notification): void
    {
        try {
            $response = $this->sap->notifyEvent($notification);
        } catch (Throwable $exception) {
            $notification->recordFailure(
                $exception->getMessage(),
                null,
                $this->clock->now(),
                $this->policy->attempts(),
                $this->policy->backoffMinutes,
            );

            return;
        }

        if ($response->successful()) {
            $notification->recordSuccess($response, $this->clock->now());

            return;
        }

        $notification->recordFailure(
            "SAP respondió {$response->status}",
            $response,
            $this->clock->now(),
            $this->policy->attempts(),
            $this->policy->backoffMinutes,
        );
    }
}
