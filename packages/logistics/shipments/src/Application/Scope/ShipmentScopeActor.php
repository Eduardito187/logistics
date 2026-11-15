<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Scope;

use Logistics\Shipments\Application\Contract\ShipmentScopeReader;
use Logistics\Shipments\Domain\ValueObject\DepartmentCode;

/**
 * QUIÉN opera, visto desde el alcance de los envíos: qué habilita a un usuario a listar y operar un envío.
 * Primitivos puros (la puerta HTTP lo arma desde el usuario autenticado); la regla de qué envíos entran
 * vive en un solo lugar, el lector de alcance ({@see ShipmentScopeReader}).
 *
 * Cada rol aporta una habilitación, y alcanza con que UNA cubra el envío:
 *  - supervisor / admin → sin restricción;
 *  - preparador → `department`: envíos cuyo departamento de ORIGEN o de DESTINO es su ciudad;
 *  - tienda → `storeNodeId`: envíos que SALEN de su nodo o LLEGAN a él;
 *  - chofer → `driverId`: envíos con parada en una ruta suya o en el vehículo de una ruta suya vigente;
 *  - transportista → `carrierId`: envíos de sus viajes.
 *
 * Decisiones deliberadas (para no dejar al personal existente sin pedidos el día del despliegue):
 *  - un preparador SIN ciudad asignada (`users.city` vacío) NO queda restringido por ciudad;
 *  - un chofer o transportista SIN vínculo tampoco queda restringido, salvo que se pida el modo estricto;
 *  - una cuenta sin ningún rol operativo no la acota este servicio (la autorización por rol es otra capa).
 * Una tienda sin nodo asignado no ve ni opera nada (regla previa, se conserva).
 */
final readonly class ShipmentScopeActor
{
    public const string ROLE_ADMIN = 'admin';

    public const string ROLE_SUPERVISOR = 'supervisor';

    public const string ROLE_PICKER = 'preparador';

    public const string ROLE_STORE = 'tienda';

    public const string ROLE_DRIVER = 'chofer';

    public const string ROLE_CARRIER = 'transportista';

    /** El envío no es de la ciudad del preparador. */
    public const string DENIED_CITY = 'out_of_scope_city';

    /** El envío no sale ni llega a la tienda del usuario. */
    public const string DENIED_NODE = 'out_of_scope_node';

    /** El envío no está asignado al chofer / transportista. */
    public const string DENIED_ASSIGNMENT = 'out_of_scope_assignment';

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct(
        public bool $unrestricted,
        public ?string $department = null,
        public ?string $storeNodeId = null,
        public ?string $driverId = null,
        public ?string $carrierId = null,
        public string $denialCode = self::DENIED_ASSIGNMENT,
    ) {}

    /** Sin restricción (supervisor, admin, procesos internos). */
    public static function unrestricted(): self
    {
        return new self(true);
    }

    /**
     * Arma el actor desde la identidad del usuario. Cada vínculo solo cuenta si el usuario tiene el rol que
     * lo usa; los ids que no tienen forma de UUID se descartan (nunca se comparan contra una columna uuid).
     *
     * @param list<string> $roles          valores de rol del usuario (`admin`, `preparador`, …)
     * @param bool         $strictUnlinked true = un chofer / transportista sin vínculo no ve ni opera nada
     */
    public static function fromIdentity(
        array $roles,
        ?string $city = null,
        ?string $nodeId = null,
        ?string $driverId = null,
        ?string $carrierId = null,
        bool $strictUnlinked = false,
    ): self {
        $roles = array_map(static fn (string $role): string => strtolower(trim($role)), $roles);
        $has = static fn (string $role): bool => in_array($role, $roles, true);

        if ($roles === [] || $has(self::ROLE_ADMIN) || $has(self::ROLE_SUPERVISOR)) {
            return self::unrestricted();
        }

        // Preparador sin ciudad asignada: sin restricción de ciudad (decisión deliberada).
        $department = $has(self::ROLE_PICKER) ? DepartmentCode::of($city) : null;
        if ($has(self::ROLE_PICKER) && $department === null) {
            return self::unrestricted();
        }

        $driver = $has(self::ROLE_DRIVER) ? self::uuidOrNull($driverId) : null;
        $carrier = $has(self::ROLE_CARRIER) ? self::uuidOrNull($carrierId) : null;
        $unlinked = ($has(self::ROLE_DRIVER) && $driver === null) || ($has(self::ROLE_CARRIER) && $carrier === null);
        if ($unlinked && !$strictUnlinked) {
            return self::unrestricted();
        }

        return new self(
            unrestricted: false,
            department: $department,
            storeNodeId: $has(self::ROLE_STORE) ? self::uuidOrNull($nodeId) : null,
            driverId: $driver,
            carrierId: $carrier,
            denialCode: match (true) {
                $has(self::ROLE_PICKER) => self::DENIED_CITY,
                $has(self::ROLE_STORE) => self::DENIED_NODE,
                default => self::DENIED_ASSIGNMENT,
            },
        );
    }

    /** Restringido y sin ninguna habilitación (p. ej. tienda sin nodo): no ve ni opera ningún envío. */
    public function seesNothing(): bool
    {
        return !$this->unrestricted
            && $this->department === null
            && $this->storeNodeId === null
            && $this->driverId === null
            && $this->carrierId === null;
    }

    /** Motivo legible del rechazo (viaja en `detail` y en `error.message`). */
    public function denialMessage(): string
    {
        return match ($this->denialCode) {
            self::DENIED_CITY => 'Este pedido no es de tu ciudad: puedes consultarlo, pero no operarlo.',
            self::DENIED_NODE => 'Este pedido no sale de tu tienda ni llega a ella: puedes consultarlo, pero no operarlo.',
            default => 'Este pedido no está asignado a ti: puedes consultarlo, pero no operarlo.',
        };
    }

    /**
     * Forma primitiva y estable del actor, para variar la clave de una caché de lectura por alcance.
     *
     * @return array<string, bool|string|null>
     */
    public function toArray(): array
    {
        return [
            'unrestricted' => $this->unrestricted,
            'department' => $this->department,
            'store_node_id' => $this->storeNodeId,
            'driver_id' => $this->driverId,
            'carrier_id' => $this->carrierId,
        ];
    }

    private static function uuidOrNull(?string $value): ?string
    {
        return $value !== null && preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }
}
