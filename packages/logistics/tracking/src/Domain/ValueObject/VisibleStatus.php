<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

/**
 * Estado visible del envío tal como lo ve el cliente en la página pública de rastreo.
 *
 * Es un vocabulario propio y simplificado del contexto de seguimiento: NO es el estado interno del
 * envío ni el de ejecución, sino su proyección legible. Se alimenta de los eventos de execution
 * (ver VisibleStatus::fromExecutionEvent).
 */
enum VisibleStatus: string
{
    /** El envío fue registrado para seguimiento; todavía sin preparación. */
    case Registered = 'REGISTERED';

    /** Preparado/alistado en bodega, listo para salir. */
    case Prepared = 'PREPARED';

    /** En camino: el camión salió hacia el destino. */
    case OnTheWay = 'ON_THE_WAY';

    /** Llegando: el camión está en las últimas paradas antes del cliente. */
    case Arriving = 'ARRIVING';

    /** Entregado al cliente. */
    case Delivered = 'DELIVERED';

    /** Intento de entrega fallido (ausente, rechazo, dirección incorrecta…). */
    case Failed = 'FAILED';

    /**
     * Traduce el tipo de un evento de ejecución normalizado al estado visible correspondiente.
     *
     * Devuelve null cuando el evento no afecta el estado visible: la proyección simplemente lo ignora,
     * en vez de fallar, porque el read model solo refleja los hechos que le interesan.
     */
    public static function fromExecutionEvent(string $executionEventType): ?self
    {
        return match ($executionEventType) {
            'execution.shipment_prepared', 'execution.prepared' => self::Prepared,
            // execution.shipment_loaded es el hecho real que emite execution al cargar el camión:
            // cargado ⇒ en camino. Se conservan los alias por compatibilidad con relays normalizados.
            'execution.shipment_loaded', 'execution.shipment_dispatched', 'execution.dispatched', 'execution.on_the_way' => self::OnTheWay,
            'execution.shipment_arriving', 'execution.arriving' => self::Arriving,
            'execution.shipment_delivered', 'execution.delivered' => self::Delivered,
            'execution.delivery_failed', 'execution.failed' => self::Failed,
            default => null,
        };
    }

    /**
     * Un estado terminal ya no cambia: el envío llegó a su fin (entregado o fallido).
     */
    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }
}
