<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\DeletePlan;

use Logistics\Shared\Application\Command\Command;

/**
 * Borra un plan de reparto con todas sus paradas. Solo sobre un plan SIN publicar (borrador o propuesta):
 * una hoja de ruta publicada ya no se toca desde planificación (para cambiarla existe el flujo de
 * ejecución). Los pedidos de sus paradas quedan libres para armar otro viaje.
 */
final readonly class DeletePlan implements Command
{
    public function __construct(
        public string $planId,
    ) {}
}
