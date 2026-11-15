<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Support\Staff\StaffInbox;
use Logistics\Outbox\Application\Contract\OutboxObserver;
use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Observador del outbox: cuando pasa un hecho que cambia la bandeja interna de la OFICINA, avisa en tiempo
 * real (`inbox.updated`) a admin y supervisor.
 *
 * Cubre las fuentes de `/app/inbox` que no tienen destinatario propio:
 *  - `telemetry.alert`  : una alerta de flota se levantó o se reconoció;
 *  - `fleet.document`   : se cargó o renovó un documento de flota (cambia lo "por vencer / vencido").
 *
 * Es un observador (no un reactor): no participa del desenlace del mensaje y el relay traga cualquier error.
 * Solo encola, con ventana anti-ráfaga ({@see StaffInbox::officeChanged()}); nada de I/O externo acá. Verlo
 * más de una vez (reintentos) es inocuo: el aviso es "volvé a mirar tu bandeja", con el no-leído vigente.
 *
 * Un documento que vence por el paso del tiempo no genera ningún hecho: ese cambio lo ve la app en su
 * próximo refresco de la bandeja.
 */
final readonly class InboxRealtimeObserver implements OutboxObserver
{
    /**
     * Tipos de agregado cuyos hechos cambian la bandeja de la oficina.
     *
     * @var list<string>
     */
    private const AGGREGATE_TYPES = ['telemetry.alert', 'fleet.document'];

    public function __construct(
        private StaffInbox $inbox,
    ) {}

    public function observe(OutboxMessage $message): void
    {
        if (!StaffInbox::realtimeEnabled() || !in_array($message->aggregateType(), self::AGGREGATE_TYPES, true)) {
            return;
        }

        $this->inbox->officeChanged();
    }
}
