<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Console;

use DateInterval;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Logistics\Planning\Application\Command\GenerateDailyPlan\GenerateDailyPlan;
use Logistics\Planning\Application\Query\GetDailyPlanRun\GetDailyPlanRun;
use Logistics\Planning\Application\ReadModel\AutoPlanRunView;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\Clock\Clock;
use Throwable;

/**
 * Disparo NOCTURNO del auto-planificador (Ola 0: "sin disparo automático"). Lo agenda routes/console.php
 * a la hora configurada (logistics-planning.auto_planner.nightly.at, 21:00 por defecto).
 *
 * Despacha el MISMO comando que usa el admin («Auto-plan the day») y la API (POST /plans/auto):
 * GenerateDailyPlan, que abre una corrida encolada y delega el cálculo pesado al worker. La corrida cubre
 * todos los camiones disponibles de todos los departamentos (el backend respeta "un camión = un
 * departamento"), así que basta una corrida por día y modo.
 *
 * Idempotente: si ya hay una corrida activa o completada para el día y modo, el handler no abre otra (solo
 * reintenta tras una fallida). Dentro de la corrida, el runner reusa el plan PROPUESTO de cada camión y
 * nunca pisa un plan PUBLICADO.
 *
 * Fecha por defecto: mañana en America/La_Paz (la fecha operativa del negocio).
 */
final class AutoPlanNightlyCommand extends Command
{
    /** Zona horaria de la fecha operativa del negocio. */
    private const TIMEZONE = 'America/La_Paz';

    /** @var string */
    protected $signature = 'logistics:planning:auto-plan
        {--date= : Día a planificar (Y-m-d); por defecto, mañana en America/La_Paz}
        {--strategy= : optimization|slots; por defecto, el de la configuración}';

    /** @var string */
    protected $description = 'Dispara el auto-planificador del día (por defecto, mañana) de forma idempotente';

    public function handle(CommandBus $commandBus, QueryBus $queryBus, Clock $clock, Config $config): int
    {
        try {
            $date = $this->planDate($clock);
            $strategy = $this->strategy($config);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $commandBus->dispatch(new GenerateDailyPlan(
                planDate: $date->toString(),
                strategy: $strategy->value,
            ));
        } catch (PlanningException $exception) {
            $this->error("No se pudo disparar el auto-plan del {$date->toString()}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        /** @var AutoPlanRunView|null $run */
        $run = $queryBus->ask(new GetDailyPlanRun($date->toString(), $strategy->value));

        $this->info(sprintf(
            'Auto-plan %s (%s): corrida %s',
            $date->toString(),
            $strategy->value,
            $run instanceof AutoPlanRunView ? $run->status : 'queued',
        ));

        return self::SUCCESS;
    }

    /**
     * Día del plan: el de --date, o mañana en America/La_Paz según el reloj de la plataforma.
     */
    private function planDate(Clock $clock): PlanDate
    {
        $option = $this->option('date');
        if (is_string($option) && trim($option) !== '') {
            return PlanDate::fromString(trim($option));
        }

        $tomorrow = $clock->now()->setTimezone(new DateTimeZone(self::TIMEZONE))->add(new DateInterval('P1D'));

        return PlanDate::fromString($tomorrow->format('Y-m-d'));
    }

    /**
     * Modo de la corrida: el de --strategy, o el configurado (optimization por defecto).
     */
    private function strategy(Config $config): PlanStrategy
    {
        $option = $this->option('strategy');
        $value = is_string($option) && trim($option) !== ''
            ? trim($option)
            : (string) $config->get('logistics-planning.auto_planner.nightly.strategy', PlanStrategy::Optimization->value);

        return PlanStrategy::fromString($value);
    }
}
