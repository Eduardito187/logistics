<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Habilidades (abilities) de un token Sanctum, con granularidad por contexto/acción.
 *
 * Son las "scopes" que se graban en el token al emitirlo (derivadas de los roles del
 * usuario, ver {@see Role::abilities()}) y que luego cada endpoint exige mediante el
 * middleware `ability`. El comodín '*' concede todas (solo para admin).
 *
 * Convención: "<contexto>:<accion>" en minúsculas. Un endpoint de solo lectura pide
 * ":read"; uno que muta estado pide ":write".
 */
final class Ability
{
    public const WILDCARD = '*';

    // Red y cobertura
    public const NETWORK_READ = 'network:read';

    public const NETWORK_WRITE = 'network:write';

    // Flota: vehículos, choferes, ayudantes, tripulaciones
    public const FLEET_READ = 'fleet:read';

    public const FLEET_WRITE = 'fleet:write';

    // Envíos / pedidos recibidos
    public const SHIPMENTS_READ = 'shipments:read';

    public const SHIPMENTS_WRITE = 'shipments:write';

    // Planificación (tablero, rutas, consolidación)
    public const PLANNING_READ = 'planning:read';

    public const PLANNING_WRITE = 'planning:write';

    // Ejecución: escaneos de preparación/carga, POD, fallidas, recepción
    public const EXECUTION_READ = 'execution:read';

    public const EXECUTION_WRITE = 'execution:write';

    // Visibilidad / tracking (incluye GPS en vivo del chofer)
    public const TRACKING_READ = 'tracking:read';

    public const TRACKING_WRITE = 'tracking:write';

    // Notificaciones (cascada de canales)
    public const NOTIFICATIONS_READ = 'notifications:read';

    public const NOTIFICATIONS_WRITE = 'notifications:write';

    // Cupos de capacidad
    public const CAPACITY_READ = 'capacity:read';

    public const CAPACITY_WRITE = 'capacity:write';

    // Promesa de entrega
    public const PROMISE_READ = 'promise:read';

    public const PROMISE_WRITE = 'promise:write';

    // Transportistas terceros
    public const CARRIERS_READ = 'carriers:read';

    public const CARRIERS_WRITE = 'carriers:write';

    /**
     * Catálogo completo de habilidades concretas (sin el comodín).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::NETWORK_READ, self::NETWORK_WRITE,
            self::FLEET_READ, self::FLEET_WRITE,
            self::SHIPMENTS_READ, self::SHIPMENTS_WRITE,
            self::PLANNING_READ, self::PLANNING_WRITE,
            self::EXECUTION_READ, self::EXECUTION_WRITE,
            self::TRACKING_READ, self::TRACKING_WRITE,
            self::NOTIFICATIONS_READ, self::NOTIFICATIONS_WRITE,
            self::CAPACITY_READ, self::CAPACITY_WRITE,
            self::PROMISE_READ, self::PROMISE_WRITE,
            self::CARRIERS_READ, self::CARRIERS_WRITE,
        ];
    }
}
