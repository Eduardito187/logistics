<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

/**
 * Canal de entrega de una notificación. La cascada del ADR 006 los recorre en orden de costo:
 * primero los gratuitos (push y web push por topic FCM), después los canales de mensajería del
 * cliente (WhatsApp, Telegram), luego email y, solo como respaldo crítico con tope, SMS.
 *
 * NOTA (decisión del dueño, 2026-10): el ADR 006 original decía "sin WhatsApp por costo". El dueño
 * del producto pidió habilitarlo como un canal más; se agrega WhatsApp (y Telegram) al catálogo.
 * El detalle del cambio queda registrado en el README del package (no se tocan los ADR desde acá).
 */
enum Channel: string
{
    /** Push de la app de clientes, publicado al topic FCM del envío (gratis). */
    case PushFcm = 'PUSH_FCM';

    /** Web push en la página de tracking, mismo topic FCM (gratis). */
    case WebPush = 'WEB_PUSH';

    /** WhatsApp del cliente vía Meta WhatsApp Cloud API (decisión del dueño, ver nota arriba). */
    case WhatsApp = 'WHATSAPP';

    /** Telegram del cliente vía Bot API (sendMessage/sendPhoto). */
    case Telegram = 'TELEGRAM';

    /** Email transaccional (casi gratis). */
    case Email = 'EMAIL';

    /** SMS: solo respaldo crítico cuando no hubo apertura, con tope configurable. */
    case Sms = 'SMS';

    /**
     * ¿El canal admite una imagen (collage de productos / thumbnail)? Push, WhatsApp y Telegram sí;
     * SMS no; email arma su propio HTML con el listado de productos (no una sola imagen).
     */
    public function supportsImage(): bool
    {
        return match ($this) {
            self::PushFcm, self::WebPush, self::WhatsApp, self::Telegram => true,
            self::Email, self::Sms => false,
        };
    }
}
