<?php

declare(strict_types=1);

namespace App\Observability;

use App\Metrics\MetricsStore;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tracing liviano sin SDK (Ola 5): cronometra un bloque, lo registra como log estructurado y alimenta el
 * histograma `logistics_span_duration_seconds{span}`.
 *
 *     $plan = Span::measure('planning.daily_plan', fn () => $runner->run(...));
 *
 * Reglas:
 *   - Transparente: devuelve lo que devuelve el bloque y deja pasar sus excepciones tal cual. Medir no
 *     cambia la lógica del camino instrumentado.
 *   - Tolerante: si falla el log o Valkey, se ignora; la medición jamás rompe al bloque medido.
 *   - Cardinalidad baja: el nombre del span es un literal fijo del código (`contexto.operacion`), nunca
 *     un id ni un dato del request.
 *   - Correlación: el log lleva el `request_id` del Context, que Laravel propaga solo a los jobs
 *     encolados; así un span de un worker se ata al request que lo originó.
 *   - Solo se usa desde Application/Infrastructure/Presentation o `app/`. El Domain es PHP puro y no lo
 *     conoce.
 *
 * PUNTO DE EXTENSIÓN ÚNICO para tracing real (OpenTelemetry u otro): {@see traceWith()}. El trazador
 * recibe el nombre y el bloque, abre su span, ejecuta el bloque y lo cierra. Ver docs/observability.md.
 */
final class Span
{
    /** Niveles de log admitidos para la línea del span. */
    private const LOG_LEVELS = ['debug', 'info', 'notice', 'warning'];

    /**
     * Trazador externo opcional. Es cableado de arranque (no un dato de request), así que es seguro que
     * persista entre requests en un worker de larga vida.
     *
     * @var (Closure(string, Closure(): mixed): mixed)|null
     */
    private static ?Closure $tracer = null;

    /**
     * Enchufa (o quita, con null) un trazador externo que envuelve cada bloque medido. Debe ejecutar el
     * bloque recibido y devolver su resultado.
     *
     * @param (Closure(string, Closure(): mixed): mixed)|null $tracer
     */
    public static function traceWith(?Closure $tracer): void
    {
        self::$tracer = $tracer;
    }

    /**
     * Ejecuta el bloque cronometrándolo.
     *
     * @template TValue
     *
     * @param  Closure(): TValue $callback
     * @return TValue
     */
    public static function measure(string $name, Closure $callback): mixed
    {
        if (!self::enabled()) {
            return $callback();
        }

        $startedAt = hrtime(true);
        $failed = true;

        try {
            $result = self::$tracer !== null ? (self::$tracer)($name, $callback) : $callback();
            $failed = false;

            return $result;
        } finally {
            self::record($name, (hrtime(true) - $startedAt) / 1_000_000_000, $failed);
        }
    }

    /**
     * Alimenta el histograma y, si el span superó el umbral, deja la línea de log estructurado.
     */
    private static function record(string $name, float $seconds, bool $failed): void
    {
        try {
            app(MetricsStore::class)->observe(MetricsStore::SPAN_DURATION, MetricsStore::series($name), $seconds);

            $durationMs = round($seconds * 1000, 2);

            // El umbral evita inundar el log con spans frecuentes y rápidos (p. ej. agregados del cockpit);
            // los que fallan se registran siempre.
            if (!$failed && $durationMs < (float) config('observability.spans.log_threshold_ms', 100)) {
                return;
            }

            $channel = config('observability.spans.log_channel');
            $level = (string) config('observability.spans.log_level', 'debug');
            $requestId = Context::get('request_id');

            $level = in_array($level, self::LOG_LEVELS, true) ? $level : 'debug';
            $context = [
                'span' => $name,
                'duration_ms' => $durationMs,
                'outcome' => $failed ? 'error' : 'ok',
                'request_id' => is_string($requestId) ? $requestId : null,
            ];

            if (is_string($channel) && $channel !== '') {
                Log::channel($channel)->log($level, 'span', $context);

                return;
            }

            Log::log($level, 'span', $context);
        } catch (Throwable) {
            // La instrumentación es accesoria: jamás rompe al bloque medido.
        }
    }

    /**
     * Encendido por config. Si no hay aplicación arrancada (p. ej. un test unitario puro), se considera
     * apagado y el bloque corre sin medir.
     */
    private static function enabled(): bool
    {
        try {
            return (bool) config('observability.spans.enabled', true);
        } catch (Throwable) {
            return false;
        }
    }
}
