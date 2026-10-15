<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use Logistics\Telemetry\Domain\Model\VehiclePing;

/**
 * Puerto del FAN-OUT en vivo: tras ingerir un lote, publica la ÚLTIMA posición del vehículo a los
 * espectadores (torre de control, tracking del cliente) por WebSockets, y refresca el estado caliente.
 *
 * Es un contrato de dominio PURO (no conoce Reverb, Valkey ni Laravel): el adaptador de Infrastructure
 * decide el transporte real (broadcast + cache) con coalescing/throttle. La ingesta solo expresa la
 * INTENCIÓN de "hay una posición nueva para difundir"; el cómo y el a-qué-ritmo son detalle de infra.
 *
 * Aditivo: una falla de difusión NUNCA debe romper la ingesta (el adaptador degrada con gracia).
 */
interface LivePositionBroadcaster
{
    /**
     * Difunde la última posición del vehículo. `moving` lo decide el llamador (según el umbral de
     * movimiento vigente), igual que en el repositorio de posición en vivo, para no acoplar el umbral.
     */
    public function publish(VehiclePing $ping, bool $moving): void;
}
