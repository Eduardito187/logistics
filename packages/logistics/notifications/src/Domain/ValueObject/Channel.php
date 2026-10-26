<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

/**
 * Canal de entrega de una notificación. La cascada los recorre en orden de costo: primero el push
 * (Firebase Cloud Messaging, por el topic del envío), después la mensajería gratuita del cliente
 * (Telegram), luego email y, solo como respaldo crítico con tope, SMS.
 *
 * NOTA (decisión del dueño, 2026-10-16): el push de la plataforma vuelve a **Firebase Cloud Messaging
 * (FCM)** por topic (`shipment_<id>`), vía la API HTTP v1. Reemplaza el transporte de push anterior.
 * Se conserva el modelo de topic: la plataforma publica al topic sin tokens de
 * dispositivo. El detalle del cambio queda registrado en el README del package (no se tocan los ADR
 * desde acá).
 */
enum Channel: string
{
    /**
     * Push de la app de clientes, publicado al topic del envío (`shipment_<id>`) por Firebase Cloud
     * Messaging (API HTTP v1). La plataforma publica al topic sin conocer tokens de dispositivo.
     */
    case Fcm = 'FCM';

    /** Telegram del cliente vía Bot API (sendMessage/sendPhoto); gratis y open. */
    case Telegram = 'TELEGRAM';

    /** Email transaccional (casi gratis). */
    case Email = 'EMAIL';

    /** SMS: solo respaldo crítico cuando no hubo apertura, con tope configurable; por gateway open-source. */
    case Sms = 'SMS';

    /**
     * Valores que se persistieron con versiones anteriores del enum y su equivalente actual. El push
     * se llamó `PUSH_FCM` (primera versión) y luego `PUSH_NTFY` (transporte ntfy, ya retirado); hoy es
     * `FCM`. `WEB_PUSH` y `WHATSAPP` no tienen equivalente: se descartan al leer.
     */
    private const LEGACY_ALIASES = [
        'PUSH_FCM' => 'FCM',
        'PUSH_NTFY' => 'FCM',
    ];

    /**
     * Lectura tolerante de un valor PERSISTIDO (filas viejas, jsonb, caché): traduce los alias
     * históricos y devuelve null si el valor no corresponde a ningún canal vigente, para que el que lee
     * decida omitirlo (nunca tumbar un listado por una fila vieja). Para entrada de usuario se usa
     * `from()`/`Rule::enum`, que siguen siendo estrictos.
     */
    public static function fromStored(string $value): ?self
    {
        $normalized = strtoupper(trim($value));

        return self::tryFrom(self::LEGACY_ALIASES[$normalized] ?? $normalized);
    }

    /**
     * ¿El canal admite una imagen (collage de productos / thumbnail)? Push (FCM) y Telegram sí; SMS no;
     * email arma su propio HTML con el listado de productos (no una sola imagen).
     */
    public function supportsImage(): bool
    {
        return match ($this) {
            self::Fcm, self::Telegram => true,
            self::Email, self::Sms => false,
        };
    }
}
