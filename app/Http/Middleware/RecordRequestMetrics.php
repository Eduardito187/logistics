<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Metrics\MetricsStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ola 5 — Métricas RED del tráfico HTTP (tasa, errores, duración), sin dependencia nueva.
 *
 * Es un middleware TERMINABLE: `handle()` solo toma dos marcas de reloj y la escritura a Valkey ocurre en
 * `terminate()`, que con PHP-FPM corre después de enviar la respuesta. El usuario no paga la métrica.
 *
 * Etiquetas de cardinalidad ACOTADA:
 *   - `route`   patrón URI de la ruta (`api/v1/app/orders/{order}`), NUNCA el path crudo con ids. Si
 *               ninguna ruta coincidió (404 por URL inexistente) es `unmatched`.
 *   - `method`  verbo HTTP.
 *   - `status`  código de estado de la respuesta (solo en el contador).
 *   - `gate`    puerta derivada del prefijo: app / channels / integrations / admin / public / other.
 *
 * Costo por request: UNA ida y vuelta a Valkey (pipeline de 3 comandos). Tolerante: si Valkey falla se
 * ignora, jamás rompe el request. Apagable (`observability.metrics.http.enabled`) y con muestreo opcional
 * (`sample_rate`): una muestra tomada pesa `1/sample_rate`, así tasas y promedios no se sesgan.
 */
final class RecordRequestMetrics
{
    /** Atributo del request donde `handle()` deja la duración medida para `terminate()`. */
    private const DURATION_ATTRIBUTE = 'observability.duration_seconds';

    /** Etiqueta `route` cuando ninguna ruta coincidió. */
    private const UNMATCHED = 'unmatched';

    public function __construct(private readonly MetricsStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        /** @var Response $response */
        $response = $next($request);

        // La duración se fija acá (no en terminate) para no contar el envío de la respuesta al cliente.
        // Se guarda en el request porque el kernel puede resolver OTRA instancia del middleware al terminar.
        $request->attributes->set(self::DURATION_ATTRIBUTE, (hrtime(true) - $startedAt) / 1_000_000_000);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $seconds = $request->attributes->get(self::DURATION_ATTRIBUTE);

            if (!is_float($seconds) || !(bool) config('observability.metrics.http.enabled', true)) {
                return;
            }

            /** @var list<string> $except */
            $except = (array) config('observability.metrics.http.except', []);
            if ($except !== [] && $request->is(...$except)) {
                return;
            }

            $weight = $this->sampleWeight();
            if ($weight === 0) {
                return;
            }

            $route = $request->route();
            $pattern = $route instanceof Route ? $route->uri() : self::UNMATCHED;
            $method = $request->getMethod();
            $gate = $this->gate($request->path());

            $this->store->recordRequest(
                MetricsStore::series($pattern, $method, (string) $response->getStatusCode(), $gate),
                MetricsStore::series($pattern, $method, $gate),
                $seconds,
                $weight,
            );
        } catch (Throwable) {
            // La métrica es accesoria: jamás rompe el cierre del request.
        }
    }

    /**
     * Peso de la muestra según `sample_rate`: 1 sin muestreo; `round(1/rate)` si el request salió
     * sorteado; 0 si no se registra (no sorteado, o tasa <= 0).
     */
    private function sampleWeight(): int
    {
        $rate = (float) config('observability.metrics.http.sample_rate', 1.0);

        if ($rate >= 1.0) {
            return 1;
        }

        if ($rate <= 0.0 || random_int(0, 999_999) >= $rate * 1_000_000) {
            return 0;
        }

        return max(1, (int) round(1 / $rate));
    }

    /**
     * Puerta del request derivada del prefijo del path (sin barra inicial).
     */
    private function gate(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'api/v1/app') => 'app',
            str_starts_with($path, 'api/v1/channels') => 'channels',
            str_starts_with($path, 'api/v1/integrations') => 'integrations',
            str_starts_with($path, 'admin'), str_starts_with($path, 'livewire') => 'admin',
            str_starts_with($path, 'api/') => 'public',
            default => 'other',
        };
    }
}
