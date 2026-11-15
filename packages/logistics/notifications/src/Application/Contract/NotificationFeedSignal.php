<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Contract;

/**
 * Puerto de la SEÑAL de tiempo real de la bandeja de notificaciones de la app.
 *
 * Los casos de uso lo llaman cuando cambia lo que alguien ve en `/app/notifications` y
 * `/app/notifications/all`. Es solo el aviso de «volvé a pedir la lista»: el contenido nunca viaja.
 *
 * Contrato para toda implementación: nunca lanza y nunca hace I/O externo en el momento (a lo sumo
 * encola). Si el aviso se pierde, la lista sigue siendo la fuente de verdad.
 */
interface NotificationFeedSignal
{
    /**
     * Cambió el LOG de notificaciones (una nueva, una enviada, escalada o suprimida). El log no tiene
     * destinatario usuario: lo ven por igual quienes entran a la pantalla, así que se avisa a la audiencia.
     */
    public function feedChanged(): void;

    /**
     * Cambió el estado de lectura de UN usuario (marcó una leída o «leer todas»): solo se le avisa a él,
     * para que sus otros dispositivos se pongan al día.
     */
    public function readStateChanged(string $userId): void;
}
