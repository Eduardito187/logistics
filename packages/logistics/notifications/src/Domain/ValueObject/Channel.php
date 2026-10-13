<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

/**
 * Canal de entrega de una notificación. La cascada del ADR 006 los recorre en orden de costo:
 * primero los gratuitos (push y web push por topic FCM), después email y, solo como respaldo crítico
 * con tope, SMS. Sin WhatsApp.
 */
enum Channel: string
{
    /** Push de la app de clientes, publicado al topic FCM del envío (gratis). */
    case PushFcm = 'PUSH_FCM';

    /** Web push en la página de tracking, mismo topic FCM (gratis). */
    case WebPush = 'WEB_PUSH';

    /** Email transaccional (casi gratis). */
    case Email = 'EMAIL';

    /** SMS: solo respaldo crítico cuando no hubo apertura, con tope configurable. */
    case Sms = 'SMS';
}
