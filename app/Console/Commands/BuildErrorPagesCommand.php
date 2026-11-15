<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Http\StaticErrorPages;
use Illuminate\Console\Command;

/**
 * Regenera las páginas de error estáticas que sirve el servidor web (nginx) cuando la aplicación no llega
 * a responder. Salen de las mismas vistas `errors.*` de Laravel, así el visitante ve siempre la misma
 * página de marca, la responda quien la responda.
 *
 * Correrlo después de editar una vista de `resources/views/errors/` y versionar el resultado
 * (`.docker/nginx/errors/`). Con `--check` no escribe: falla si hay archivos desactualizados.
 */
final class BuildErrorPagesCommand extends Command
{
    protected $signature = 'logistics:error-pages:build
        {--check : No escribe; falla si los archivos están desactualizados}';

    protected $description = 'Genera las páginas de error estáticas del servidor web a partir de las vistas del proyecto';

    public function handle(StaticErrorPages $pages): int
    {
        $directory = base_path(StaticErrorPages::DIRECTORY);

        if ($this->option('check') === true) {
            $outdated = $pages->outdated($directory);
            if ($outdated === []) {
                $this->info('Las páginas de error estáticas están al día.');

                return self::SUCCESS;
            }

            $this->error('Desactualizadas: '.implode(', ', $outdated).'. Correr logistics:error-pages:build.');

            return self::FAILURE;
        }

        $this->info(sprintf('Se escribieron %d archivos en %s.', $pages->write($directory), StaticErrorPages::DIRECTORY));

        return self::SUCCESS;
    }
}
