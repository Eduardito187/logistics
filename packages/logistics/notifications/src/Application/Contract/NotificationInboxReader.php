<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Contract;

use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Puerta de lectura de la bandeja de notificaciones de la app (lado consulta del CQRS): filtro de no
 * leídas, conteo de no leídas y estado de lectura de una página, cada uno por usuario; y el log acotado
 * al alcance por rol de quien consulta.
 *
 * La implementación baja el filtro y el conteo a SQL (NOT EXISTS sobre la PK compuesta + marca de agua
 * de «leer todas»), sin materializar la colección en memoria.
 *
 * ALCANCE: una notificación está anclada a un envío y hereda su alcance por rol. Con un `$scope`
 * restringido solo entran las notificaciones de envíos que ese actor puede ver (EXISTS contra `shipments`
 * con el predicado único de Envíos, resuelto en la consulta: la página y los totales cuentan solo lo
 * visible). `null` o un actor sin restricción no agregan ningún predicado.
 */
interface NotificationInboxReader
{
    /**
     * Notificaciones NO leídas por el usuario, de la más reciente a la más antigua, paginadas.
     *
     * @return list<Notification>
     */
    public function unreadFor(string $userId, int $limit, int $offset, ?ShipmentScopeActor $scope = null): array;

    /**
     * Cantidad de notificaciones no leídas por el usuario (badge de la campana).
     */
    public function countUnread(string $userId, ?ShipmentScopeActor $scope = null): int;

    /**
     * Últimas notificaciones VISIBLES para el actor, de la más reciente a la más antigua, paginadas.
     *
     * @return list<Notification>
     */
    public function latestVisible(ShipmentScopeActor $scope, int $limit, int $offset): array;

    /**
     * Total de notificaciones visibles para el actor (total de la paginación del log acotado).
     */
    public function countVisible(ShipmentScopeActor $scope): int;

    /**
     * Estado de lectura de un lote de notificaciones (una página): fecha de creación y, si hay usuario,
     * cuándo la leyó (null = no leída). Fechas en ISO 8601. Sin usuario, read_at es siempre null.
     *
     * @param  list<string>                                                        $notificationIds
     * @return array<string, array{created_at: string|null, read_at: string|null}>
     */
    public function readStates(?string $userId, array $notificationIds): array;
}
