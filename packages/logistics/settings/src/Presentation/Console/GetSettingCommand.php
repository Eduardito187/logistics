<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Imprime el valor vigente de una clave de configuración (opcionalmente a una fecha dada).
 *
 * La capa de Presentation habla con el QueryBus, nunca con el handler ni con el repositorio.
 */
final class GetSettingCommand extends Command
{
    protected $signature = 'logistics:settings:get {key : Namespaced setting key, e.g. coverage.default_service_days} {--at= : ISO 8601 date to read the value as of}';

    protected $description = 'Print the effective value of a setting key as JSON';

    public function handle(QueryBus $queryBus): int
    {
        $key = (string) $this->argument('key');
        $at = $this->option('at');
        $at = is_string($at) && $at !== '' ? $at : null;

        /** @var array<string, mixed>|null $value */
        $value = $queryBus->ask(new GetSettingQuery($key, $at));

        if ($value === null) {
            $this->error("No effective value for '{$key}'.");

            return self::FAILURE;
        }

        $this->line((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
