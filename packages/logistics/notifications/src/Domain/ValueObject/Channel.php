<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

/**
 * Canal de entrega de una notificación. La cascada los recorre en orden de costo: primero los canales
 * gratuitos y self-host (push vía ntfy/UnifiedPush y web push vía VAPID, ambos por el topic del envío),
 * después la mensajería gratuita del cliente (Telegram), luego email y, solo como respaldo crítico con
 * tope, SMS.
 *
 * NOTA (decisión del dueño, 2026-10): TODO el stack de notificaciones es open-source y gratis. Se quitó
 * el push de Firebase/FCM (requería cuenta y proyecto de Google) y se reemplazó por **ntfy/UnifiedPush**
 * self-host; se quitó WhatsApp (Meta/Twilio, de pago). El detalle del cambio queda registrado en el
 * README del package (no se tocan los ADR desde acá).
 */
enum Channel: string
{
    /**
     * Push de la app de clientes, publicado al topic del envío en el servidor ntfy self-host
     * (compatible con UnifiedPush). Gratis y sin Google.
     */
    case PushNtfy = 'PUSH_NTFY';

    /** Web push en la página de tracking vía el protocolo Web Push (VAPID), mismo topic del envío (gratis). */
    case WebPush = 'WEB_PUSH';

    /** Telegram del cliente vía Bot API (sendMessage/sendPhoto); gratis y open. */
    case Telegram = 'TELEGRAM';

    /** Email transaccional (casi gratis). */
    case Email = 'EMAIL';

    /** SMS: solo respaldo crítico cuando no hubo apertura, con tope configurable; por gateway open-source. */
    case Sms = 'SMS';

    /**
     * ¿El canal admite una imagen (collage de productos / thumbnail)? Push (ntfy/web push) y Telegram sí;
     * SMS no; email arma su propio HTML con el listado de productos (no una sola imagen).
     */
    public function supportsImage(): bool
    {
        return match ($this) {
            self::PushNtfy, self::WebPush, self::Telegram => true,
            self::Email, self::Sms => false,
        };
    }
}
