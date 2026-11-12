<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Logistics\Fleet\Domain\Contract\ShiftRulesPolicy;
use Logistics\Planning\Domain\Contract\CrewShiftRulesProvider;
use Logistics\Planning\Domain\ValueObject\CrewShiftWindow;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Throwable;

/**
 * Adaptador de solo lectura que traduce las reglas de turnos de la tripulación del contexto de Flota a la
 * ventana de turno propia de Planificación (CrewShiftWindow). Usa el CONTRATO público de Flota
 * (ShiftRulesPolicy: settings versionado `fleet.crew_shift_rules` con respaldo en config `fleet.shift`),
 * el mismo que Flota aplica al armar una tripulación, de modo que ambos contextos respetan las mismas
 * reglas. El contrato se resuelve PEREZOSO desde el contenedor: si Flota no está registrado, no hay
 * reglas y el planificador se comporta como siempre. Así Planificación no acopla su dominio a Flota y,
 * el día que Flota se extraiga a un servicio, solo cambia este adaptador.
 *
 * Limitaciones: hoy las reglas son globales (iguales para todas las tripulaciones y todos los días), por
 * eso la fecha no cambia el resultado; el turno explícito de cada tripulación (fleet_crews.shift_*) no se
 * usa todavía. Se puede desactivar con `logistics-planning.auto_planner.respect_crew_shifts = false`.
 * Tolerante: cualquier error de lectura o regla inválida devuelve null (sin acotar).
 */
final readonly class FleetCrewShiftRulesProvider implements CrewShiftRulesProvider
{
    public function __construct(
        private Container $container,
        private Config $config,
    ) {}

    public function windowFor(PlanDate $date): ?CrewShiftWindow
    {
        if ($this->config->get('logistics-planning.auto_planner.respect_crew_shifts', true) === false) {
            return null;
        }

        return $this->readFleetWindow();
    }

    /**
     * Lee las reglas de Flota por su contrato público; null si Flota no está registrado o si falla la lectura.
     */
    private function readFleetWindow(): ?CrewShiftWindow
    {
        try {
            if (!$this->container->bound(ShiftRulesPolicy::class)) {
                return null;
            }

            $rules = $this->container->make(ShiftRulesPolicy::class)->rules();

            return CrewShiftWindow::of(
                $rules->earliestStartMinute,
                $rules->latestEndMinute,
                $rules->maxDailyMinutes,
            );
        } catch (Throwable) {
            return null;
        }
    }
}
