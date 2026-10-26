<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Metrics\MetricsCollector;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint de métricas Prometheus (auditoría M-6).
 *
 * Expone las señales RED/USE de la plataforma en el formato de texto de Prometheus para que un
 * scraper (Prometheus/Grafana Agent/OpenTelemetry Collector) las recolecte. NO es un endpoint público:
 * revela profundidad de colas, backlog del outbox y conteos de la infraestructura, así que se protege
 * con token y/o lista de IPs permitidas. Sin ninguno de los dos configurado, el endpoint se niega
 * (fail-closed): preferimos no exponer nada a exponerlo abierto por un despliegue sin env.
 *
 * Protección (cualquiera de las dos habilita el acceso):
 *   - METRICS_TOKEN               token secreto; el scraper lo envía en `Authorization: Bearer <token>`
 *                                 o en la cabecera `X-Metrics-Token`. Comparación en tiempo constante.
 *   - METRICS_IP_ALLOWLIST        lista de IPs separadas por coma (p. ej. la red interna del clúster).
 *
 * No usa las puertas de API (app/channels/integrations): el monitoreo es un consumidor de infra, no un
 * cliente de negocio, y no debe depender de Sanctum ni de credenciales de canal.
 */
final class MetricsController
{
    public function __invoke(Request $request, MetricsCollector $collector): Response
    {
        if (!$this->authorized($request)) {
            // 404 en vez de 401/403: no delatamos la existencia del endpoint a un escaneo anónimo.
            return new Response('Not Found', Response::HTTP_NOT_FOUND, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        return new Response($collector->render(), Response::HTTP_OK, [
            // Content-Type exacto del formato de exposición de Prometheus (versión 0.0.4).
            'Content-Type' => 'text/plain; version=0.0.4; charset=UTF-8',
            // Las métricas son un snapshot instantáneo: nunca deben servirse cacheadas.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * El acceso se concede por token válido O por IP en la lista blanca. Si no hay ni token ni lista
     * configurados, se niega el acceso por completo (fail-closed) para no exponer métricas sin protección.
     */
    private function authorized(Request $request): bool
    {
        $token = trim((string) config('observability.metrics.token', ''));
        $allowlist = $this->allowlist();

        if ($token === '' && $allowlist === []) {
            return false;
        }

        if ($token !== '' && $this->tokenMatches($request, $token)) {
            return true;
        }

        return $allowlist !== [] && in_array((string) $request->ip(), $allowlist, true);
    }

    private function tokenMatches(Request $request, string $expected): bool
    {
        $presented = $request->bearerToken() ?? (string) $request->header('X-Metrics-Token', '');

        return $presented !== '' && hash_equals($expected, $presented);
    }

    /**
     * @return list<string>
     */
    private function allowlist(): array
    {
        /** @var list<string> */
        return config('observability.metrics.ip_allowlist', []);
    }
}
