<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Autorización de canales de broadcasting (seguimiento en tiempo real — ADR 016).
|
| Este archivo define QUIÉN puede suscribirse a cada canal. El orquestador lo registra en bootstrap/app.php
| (withBroadcasting / channels) — NO se registra aquí. El transporte real (Reverb + Echo) lo habilita el
| orquestador; mientras tanto, con BROADCAST_CONNECTION=log estas reglas quedan latentes pero correctas.
*/

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
 * STUB preparado para la Fase 4 (ADR 016): el cliente final no tiene cuenta, se autoriza con un token de
 * corta vida del tracking público (sin PII), coherente con el guard de PublicTracking. Por ahora la
 * autorización es un placeholder laxo; SE AFINA EN FASE 4 antes de exponer datos reales a clientes.
 */
Broadcast::channel('shipment.{shipmentId}', static function (?User $user, string $shipmentId): bool {
    // TODO(Fase 4): validar token de tracking público de corta vida en vez de autorizar abiertamente.
    return true;
});
