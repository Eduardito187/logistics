<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

/**
 * Estado de un aviso a SAP (notificación fire-and-forget de un hecho de despacho).
 *
 * - Pending: registrado y a la espera de envío (el job ya va en la cola `sap`).
 * - Sent: SAP respondió 2xx; se guardó su respuesta cruda. Terminal.
 * - Failed: el último intento falló (error de red, breaker abierto o respuesta no 2xx). Se reintenta
 *   con backoff mientras no se agoten los intentos; agotados, queda Failed para siempre (sin dead-letter
 *   ni acción manual obligatoria: a nuestra plataforma no le importa).
 * - Skipped: el conector estaba apagado o sin URL base al registrarlo/enviarlo; no se envió nada.
 */
enum SapNotificationStatus: string
{
    case Pending = 'pending';

    case Sent = 'sent';

    case Failed = 'failed';

    case Skipped = 'skipped';

    /**
     * ¿Puede intentarse el envío? Solo Pending y Failed (el tope de intentos lo valida el agregado).
     */
    public function isDeliverable(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }
}
