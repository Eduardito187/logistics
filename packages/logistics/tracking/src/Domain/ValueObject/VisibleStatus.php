<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

/**
 * Estado visible del pedido tal como lo ve el cliente en la página pública de rastreo: un ciclo de vida
 * único y ordenado, desde que ingresa la orden hasta que se entrega (o se devuelve).
 *
 * Es un vocabulario propio y simplificado del contexto de seguimiento: NO es el estado interno del envío
 * ni el de ejecución, sino su proyección legible. Se alimenta de los hechos reales del sistema (pago,
 * etiqueta, carga, salida, entrega, cancelación, devolución) vía los reactores del outbox.
 *
 * Define además la MÁQUINA DE ESTADOS: `allowedNext()`/`canTransitionTo()` fijan las transiciones válidas
 * para que `ShipmentTracking::applyStatus()` rechace saltos no admitidos en el flujo.
 */
enum VisibleStatus: string
{
    /** Orden ingresada, esperando el pago. Todavía sin etiqueta. INICIAL. */
    case Pending = 'PENDING';

    /** Pago confirmado; en preparación. Todavía sin etiqueta. */
    case Processing = 'PROCESSING';

    /** En curso: se generó la etiqueta del pedido. */
    case InProgress = 'IN_PROGRESS';

    /** Cargada: el bulto ya está en el camión. */
    case Loaded = 'LOADED';

    /** En camino: el camión salió hacia el destino (absorbe "llegando"). */
    case OnTheWay = 'ON_THE_WAY';

    /** Entregado al cliente. */
    case Delivered = 'DELIVERED';

    /** Intento de entrega fallido (ausente, rechazo, dirección incorrecta…); admite reintento. */
    case Failed = 'FAILED';

    /** Anulada (sin pago, o anulada tras el pago antes de entregar). Terminal. */
    case Cancelled = 'CANCELLED';

    /** Reprogramada: cambió la fecha/lugar de entrega; vuelve al flujo por «procesando». */
    case Rescheduled = 'RESCHEDULED';

    /** Devuelta por el cliente tras la entrega. Terminal. */
    case Returned = 'RETURNED';

    /**
     * Transiciones admitidas desde este estado (la máquina de estados del ciclo de vida del pedido).
     * Regla del negocio: tras el pago y antes de entregar se puede reprogramar o anular; tras entregar,
     * devolver; una entrega fallida puede reintentarse (vuelve a «en camino») o reprogramarse.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Cancelled],
            self::Processing => [self::InProgress, self::Cancelled, self::Rescheduled],
            self::InProgress => [self::Loaded, self::Cancelled, self::Rescheduled],
            self::Loaded => [self::OnTheWay, self::Cancelled, self::Rescheduled],
            self::OnTheWay => [self::Delivered, self::Failed, self::Cancelled, self::Rescheduled],
            self::Failed => [self::OnTheWay, self::Rescheduled, self::Cancelled],
            self::Rescheduled => [self::Processing],
            self::Delivered => [self::Returned],
            self::Cancelled, self::Returned => [],
        };
    }

    /** ¿Es válido pasar de este estado al dado? (idempotencia: igual→igual la decide el agregado). */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /**
     * Traduce el tipo de un evento de ejecución normalizado al estado visible correspondiente.
     *
     * Devuelve null cuando el evento no afecta el estado visible: la proyección simplemente lo ignora,
     * en vez de fallar, porque el read model solo refleja los hechos que le interesan.
     */
    public static function fromExecutionEvent(string $executionEventType): ?self
    {
        return match ($executionEventType) {
            'execution.shipment_prepared', 'execution.prepared' => self::InProgress,
            'execution.shipment_loaded' => self::Loaded,
            // El camión salió: hecho propio (lo dispara el chofer al iniciar la ruta) ⇒ en camino.
            // "arriving" se fusiona en en-camino (no es un estado separado del ciclo).
            'execution.shipment_departed', 'execution.shipment_dispatched', 'execution.dispatched', 'execution.on_the_way', 'execution.shipment_arriving', 'execution.arriving' => self::OnTheWay,
            'execution.shipment_delivered', 'execution.delivered' => self::Delivered,
            'execution.delivery_failed', 'execution.failed' => self::Failed,
            default => null,
        };
    }

    /**
     * Un estado terminal ya no cambia: el pedido llegó a su fin (anulado o devuelto). Entregado NO es
     * terminal (puede pasar a devuelto) ni fallido (puede reintentarse).
     */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Returned;
    }
}
