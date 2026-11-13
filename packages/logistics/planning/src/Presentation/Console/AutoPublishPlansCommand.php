<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Console;

use DateInterval;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Logistics\Planning\Application\ReadModel\AutoPublishResult;
use Logistics\Planning\Application\Service\AutoPlanPublisher;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PublishThresholds;
use Logistics\Shared\Domain\Clock\Clock;
use Throwable;

/**
 * AUTO-PUBLICACIÓN programada de los planes del día (Fase 3.2). Lo agenda routes/console.php a las 21:45
 * (después del auto-plan de las 21:00), en la zona horaria del negocio.
 *
 * Evalúa cada plan PROPUESTO con la PublishGate (ocupación, aptitud del vehículo) y publica los que pasan;
 * los retenidos quedan propuestos y se informan con sus motivos para que el planificador decida (ADR 012).
 *
 * APAGADO por defecto (PLANNING_AUTO_PUBLISH_ENABLED=false): mientras el flag esté en false el comando no
 * publica nada (el scheduler además no lo agenda). Así el primer mes se opera con publicación manual y se
 * habilita la automática cuando el negocio lo decida.
 *
 * Fecha por defecto: mañana en America/La_Paz (la fecha que planificó el nocturno).
 */
final class AutoPublishPlansCommand extends Command
{
    /** Zona horaria de la fecha operativa del negocio. */
    private const TIMEZONE = 'America/La_Paz';

    /** @var string */
    protected $signature = 'logistics:planning:auto-publish
        {--date= : Día cuyos planes publicar (Y-m-d); por defecto, mañana en America/La_Paz}
        {--force : Publicar aunque el flag PLANNING_AUTO_PUBLISH_ENABLED esté en false}';

    /** @var string */
    protected $description = 'Publica automáticamente los planes propuestos que pasan la PublishGate (idempotente)';

    public function handle(AutoPlanPublisher $publisher, Clock $clock, Config $config): int
    {
        $enabled = (bool) $config->get('logistics-planning.auto_publish.enabled', false);
        if (!$enabled && $this->option('force') !== true) {
            $this->info('Auto-publicación deshabilitada (PLANNING_AUTO_PUBLISH_ENABLED=false); no se publica nada.');

            return self::SUCCESS;
        }

        try {
            $date = $this->planDate($clock);
            $thresholds = $this->thresholds($config);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $result = $publisher->run($date, $thresholds);
        } catch (PlanningException $exception) {
            $this->error("No se pudo auto-publicar los planes del {$date->toString()}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->report($date, $result);

        return self::SUCCESS;
    }

    private function report(PlanDate $date, AutoPublishResult $result): void
    {
        $this->info(sprintf(
            'Auto-publicación %s: evaluados %d, publicados %d, retenidos %d.',
            $date->toString(),
            $result->evaluated,
            $result->published,
            count($result->held),
        ));

        foreach ($result->held as $held) {
            $this->warn(sprintf(
                '  Retenido %s (vehículo %s): %s',
                $held['plan_id'],
                $held['vehicle_id'],
                implode('; ', $held['reasons']),
            ));
        }
    }

    /**
     * Día de los planes: el de --date, o mañana en America/La_Paz según el reloj de la plataforma.
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
     * Umbrales de la PublishGate leídos de la configuración (sección «Planning › Automation»).
     */
    private function thresholds(Config $config): PublishThresholds
    {
        return PublishThresholds::of(
            (float) $config->get('logistics-planning.auto_publish.max_occupancy', 1.0),
            (float) $config->get('logistics-planning.auto_publish.min_occupancy', 0.0),
            (bool) $config->get('logistics-planning.auto_publish.require_vehicle_available', true),
        );
    }
}
