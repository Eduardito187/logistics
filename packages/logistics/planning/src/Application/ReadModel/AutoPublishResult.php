<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

/**
 * Resumen de una corrida de AUTO-PUBLICACIÓN (Fase 3.2): cuántos planes propuestos se evaluaron, cuántos
 * pasaron el gate y se publicaron, y cuáles quedaron RETENIDOS con sus motivos (para alertar al
 * planificador y mostrar en el cockpit). Forma primitiva, sin objetos de dominio.
 *
 * @phpstan-type HeldPlan array{plan_id: string, vehicle_id: string, reasons: list<string>}
 */
final readonly class AutoPublishResult
{
    /**
     * @param list<HeldPlan> $held
     */
    public function __construct(
        public int $evaluated,
        public int $published,
        public array $held,
    ) {}
}
