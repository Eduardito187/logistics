<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

/**
 * Estado visible de un BULTO (unidad física) dentro del seguimiento.
 *
 * Es un vocabulario propio y más fino que el del envío (VisibleStatus): mientras el envío proyecta el
 * estado "macro" que ve el cliente, el bulto proyecta su propio recorrido físico (se preparó, se cargó,
 * se bajó en una parada, se recibió en un nodo), que alimenta la reconciliación de bultos descargados
 * por error o faltantes (KPI 4 del piloto). Se deriva de los eventos per-bulto de execution
 * (ver UnitStatus::fromExecutionEvent).
 */
enum UnitStatus: string
{
    /** El bulto existe en el seguimiento pero todavía no tiene ningún escaneo. */
    case Registered = 'REGISTERED';

    /** Preparado/alistado en bodega (EAN → etiqueta → carril). */
    case Prepared = 'PREPARED';

    /** Cargado al vehículo y validado. */
    case Loaded = 'LOADED';

    /** Descargado en una parada/nodo de la ruta (no es el desenlace: deja constancia de qué bajó y dónde). */
    case Unloaded = 'UNLOADED';

    /** Recibido formalmente en un nodo logístico. */
    case Received = 'RECEIVED';

    /** Entregado al cliente (hereda el desenlace del envío). */
    case Delivered = 'DELIVERED';

    /** El intento de entrega del envío que lo llevaba falló. */
    case Failed = 'FAILED';

    /**
     * Traduce el tipo de un evento de ejecución per-bulto al estado visible del bulto.
     *
     * Devuelve null cuando el evento no afecta al bulto: la proyección lo ignora en vez de fallar, igual
     * que la proyección del envío. Los desenlaces del envío (entregado/fallido) no traen unit_id pero sí
     * aplican a todos sus bultos: el handler decide la cascada.
     */
    public static function fromExecutionEvent(string $executionEventType): ?self
    {
        return match ($executionEventType) {
            'execution.shipment_prepared', 'execution.prepared' => self::Prepared,
            'execution.shipment_loaded', 'execution.loaded' => self::Loaded,
            'execution.shipment_unloaded', 'execution.unloaded' => self::Unloaded,
            'execution.node_reception_recorded', 'execution.node_reception' => self::Received,
            'execution.shipment_delivered', 'execution.delivered' => self::Delivered,
            'execution.delivery_failed', 'execution.failed' => self::Failed,
            default => null,
        };
    }

    /**
     * Un estado terminal ya no cambia: el bulto llegó al desenlace de su envío (entregado o fallido).
     */
    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }
}
