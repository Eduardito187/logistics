<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Support\Realtime\UserRealtimeChannels;
use Illuminate\Support\Facades\Broadcast;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;
use Logistics\Shipments\Application\Contract\ShipmentScopeReader;
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;

/*
| Autorización de canales de broadcasting (seguimiento en tiempo real — ADR 016).
|
| Este archivo define QUIÉN puede suscribirse a cada canal. El orquestador lo registra en bootstrap/app.php
| (withBroadcasting / channels) — NO se registra aquí. El transporte real (Soketi, protocolo Pusher, + Echo) lo habilita el
| orquestador; mientras tanto, con BROADCAST_CONNECTION=log estas reglas quedan latentes pero correctas.
*/

/**
 * Canal PERSONAL de cada usuario de la app (`private-user.{id}`): por acá llega `inbox.updated` con su
 * no-leído cada vez que cambia su bandeja interna (ver App\Events\InboxUpdated).
 *
 * Solo el propio usuario: nadie escucha la bandeja de otro, sea del rol que sea. La app lo autoriza con su
 * Bearer en POST /api/v1/app/broadcasting/auth; el admin web, con su sesión en /broadcasting/auth.
 */
Broadcast::channel('user.{id}', static function (User $user, string $id): bool {
    return ctype_digit($id) && (int) $user->getAuthIdentifier() === (int) $id;
});

/**
 * Canales por TEMA de la app (`fleet` = flota en vivo, `planning` = planes y corrida automática), con
 * alcance por ciudad: `<tema>.all` para quien ve todas las ciudades y `<tema>.city.<código>` para el personal
 * de una ciudad. Quién entra a cuál lo decide UserRealtimeChannels, la misma regla con la que
 * GET /api/v1/app/broadcasting/config le dice a la app a qué suscribirse.
 */
foreach ([RealtimeChannels::TOPIC_FLEET, RealtimeChannels::TOPIC_PLANNING, RealtimeChannels::TOPIC_TRACKING] as $topic) {
    Broadcast::channel($topic.'.all', static function (User $user) use ($topic): bool {
        return UserRealtimeChannels::canJoinAll($user, $topic);
    });

    Broadcast::channel($topic.'.city.{city}', static function (User $user, string $city) use ($topic): bool {
        return UserRealtimeChannels::canJoinCity($user, $topic, $city);
    });
}

/**
 * Canal del PANEL de la torre de control: toda la flota en vivo.
 *
 * Gated igual que el acceso al admin (User::canAccessPanel): solo Admin/Supervisor. Los roles de campo
 * (chofer, preparador, tienda, transportista) no espían la flota completa.
 */
Broadcast::channel('fleet', static function (User $user): bool {
    return $user->hasAnyRole(Role::Admin, Role::Supervisor);
});

/**
 * Canal ACOTADO por NODO base: el fan-out de posiciones de los vehículos de un nodo (un nodo vive en un
 * solo departamento, coherente con la regla de ruta de un departamento). Permite que un espectador siga
 * solo su nodo sin recibir el stream global de `fleet`.
 *
 * Mismo gating operativo que el panel (Admin/Supervisor): se REUSA el criterio del canal `fleet`, sin
 * introducir un modelo de permisos por zona nuevo. El afinado por rol de nodo (que un encargado vea solo
 * su nodo) se podrá sumar aquí cuando exista ese vínculo rol↔nodo, sin cambiar el broadcast.
 */
Broadcast::channel('fleet.node.{nodeId}', static function (User $user, string $nodeId): bool {
    return $user->hasAnyRole(Role::Admin, Role::Supervisor);
});

/**
 * Canal por VEHÍCULO: seguimiento de una unidad concreta desde la operación.
 *
 * Mismo gating operativo que el panel (Admin/Supervisor). El scoping fino por vehículo asignado a un
 * chofer se podrá afinar cuando exista el vínculo chofer↔vehículo del turno (fuera de alcance hoy).
 */
Broadcast::channel('vehicle.{vehicleId}', static function (User $user, string $vehicleId): bool {
    return $user->hasAnyRole(Role::Admin, Role::Supervisor);
});

/**
 * Canal por ENVÍO: tracking del cliente ("tu pedido se acerca").
 *
 * El cliente final no tiene cuenta: en la Fase 4 (ADR 016) se autorizará con un token de corta vida del
 * seguimiento público (sin datos personales). Mientras tanto solo entra la oficina (admin y supervisor).
 */
Broadcast::channel('shipment.{shipmentId}', static function (?User $user, string $shipmentId): bool {
    // Hasta que exista el token de seguimiento público de corta vida (Fase 4), el canal queda cerrado a la
    // oficina: el servidor de sockets ya es alcanzable desde internet y un canal abierto dejaría a
    // cualquier cuenta de la app seguir cualquier envío.
    return $user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor);
});

/**
 * Canal de la FICHA de un pedido (`private-order.{shipment_id}`): por acá llega `tracking.updated` cada vez
 * que cambia el estado visible del pedido (incluidos cancelado, reprogramado y devuelto), su avance por
 * producto o la ruta que lo transporta.
 *
 * Oficina (admin/supervisor) siempre; el resto del personal solo si ese envío está en su alcance operativo
 * (misma regla que el middleware order.scope y la consulta de pedidos). Así un chofer o una tienda que mira
 * un pedido suyo recibe la señal, y nadie ve pedidos fuera de su alcance.
 */
Broadcast::channel('order.{shipmentId}', static function (?User $user, string $shipmentId): bool {
    if (!$user instanceof User) {
        return false;
    }

    if ($user->hasAnyRole(Role::Admin, Role::Supervisor)) {
        return true;
    }

    return app(ShipmentScopeReader::class)->inScope(ScopeActorResolver::fromUser($user), [$shipmentId]) !== [];
});
