<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\ApiChannel;
use App\Models\ServiceClient;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Deduplicación idempotente de requests de escritura para la app offline (frente S-API).
 *
 * La app, al recuperar conectividad, reintenta escrituras que quizá ya se aplicaron. Para
 * que un reintento no duplique el efecto, el cliente envía una cabecera `Idempotency-Key`
 * estable por operación. La PRIMERA ejecución guarda su respuesta en Valkey; cualquier
 * reintento con la MISMA clave y el MISMO cuerpo recibe la respuesta guardada (replay),
 * sin volver a ejecutar el handler.
 *
 * Contrato:
 *   - Solo afecta métodos de escritura (config `api.idempotency.methods`).
 *   - Sin cabecera `Idempotency-Key` el request pasa de largo (la deduplicación es opt-in).
 *   - Clave mal formada → 400 `invalid_idempotency_key`.
 *   - Misma clave con cuerpo distinto → 409 `idempotency_key_reused`.
 *   - Otra petición con la misma clave en vuelo → 409 `idempotency_key_in_progress`.
 *   - El replay añade la cabecera `Idempotency-Replayed: true`.
 *
 * Todo el I/O de deduplicación es contra el store de caché (Valkey): no toca SAP ni la BD.
 */
final class IdempotentRequests
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $method = strtoupper($request->getMethod());

        if (!in_array($method, $this->writeMethods(), true)) {
            return $next($request);
        }

        $key = $request->header($this->headerName());

        // La deduplicación es opt-in: sin clave, no se intercepta nada.
        if (!is_string($key) || $key === '') {
            return $next($request);
        }

        if (!$this->isValidKey($key)) {
            return ProblemDetails::fromCode(
                ErrorCode::InvalidIdempotencyKey,
                'The Idempotency-Key header is malformed or too long.',
            )->toResponse($request);
        }

        $cache = $this->cache();
        $cacheKey = $this->cacheKey($request, $key);
        $fingerprint = $this->fingerprint($request);

        // Respuesta ya guardada: replay (o conflicto si la clave se reusó con otro cuerpo).
        $stored = $cache->get($cacheKey);
        if (is_array($stored)) {
            return $this->resolveStored($stored, $fingerprint, $request);
        }

        $lock = $this->lock($cache, $cacheKey);

        // Otro request con la misma clave está en vuelo: no ejecutamos en paralelo.
        if ($lock !== null && !$lock->get()) {
            return ProblemDetails::fromCode(
                ErrorCode::IdempotencyKeyInProgress,
                'A request with this Idempotency-Key is already being processed.',
            )->toResponse($request);
        }

        try {
            // Revisión tras el lock: el request que lo tenía pudo terminar mientras tanto.
            $stored = $cache->get($cacheKey);
            if (is_array($stored)) {
                return $this->resolveStored($stored, $fingerprint, $request);
            }

            $response = $next($request);

            if ($this->isStorable($response)) {
                $cache->put($cacheKey, $this->serialize($response, $fingerprint), $this->ttl());
            }

            return $response;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Replay de la respuesta guardada, o conflicto si la clave se reusó con otro cuerpo.
     *
     * @param array<string, mixed> $stored
     */
    private function resolveStored(array $stored, string $fingerprint, Request $request): SymfonyResponse
    {
        if (($stored['fingerprint'] ?? null) !== $fingerprint) {
            return ProblemDetails::fromCode(
                ErrorCode::IdempotencyKeyReused,
                'The Idempotency-Key was already used for a different request payload.',
            )->toResponse($request);
        }

        $status = is_int($stored['status'] ?? null) ? $stored['status'] : 200;
        $content = is_string($stored['content'] ?? null) ? $stored['content'] : '';
        $headers = is_array($stored['headers'] ?? null) ? $stored['headers'] : [];

        $response = new Response($content, $status);

        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $response->headers->set($name, $value);
            }
        }

        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }

    /**
     * Serializa la respuesta para guardarla (estado, cuerpo, cabeceras seguras y huella).
     *
     * @return array<string, mixed>
     */
    private function serialize(SymfonyResponse $response, string $fingerprint): array
    {
        $content = $response->getContent();

        $headers = [];
        foreach (['Content-Type', 'Location'] as $name) {
            $value = $response->headers->get($name);
            if (is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'content' => is_string($content) ? $content : '',
            'headers' => $headers,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Solo se guardan respuestas definitivas (no errores 5xx, que suelen ser transitorios
     * y deben poder reintentarse).
     */
    private function isStorable(SymfonyResponse $response): bool
    {
        return $response->getStatusCode() < 500 && is_string($response->getContent());
    }

    /**
     * Huella del request: cualquier cambio de método, ruta o cuerpo la altera, de modo que
     * reusar la clave con otro payload se detecta como conflicto.
     */
    private function fingerprint(Request $request): string
    {
        return hash('sha256', implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $request->getContent() ?: '',
        ]));
    }

    /**
     * Clave de caché acotada por principal (canal / cliente de servicio / usuario): dos
     * emisores distintos nunca comparten el espacio de claves de idempotencia.
     */
    private function cacheKey(Request $request, string $idempotencyKey): string
    {
        return 'idem:'.hash('sha256', $this->principal($request).'|'.$idempotencyKey);
    }

    private function principal(Request $request): string
    {
        $channel = $request->attributes->get(AuthenticateChannel::ATTRIBUTE);
        if ($channel instanceof ApiChannel) {
            return 'channel:'.(string) $channel->getKey();
        }

        $client = $request->attributes->get(AuthenticateServiceClient::ATTRIBUTE);
        if ($client instanceof ServiceClient) {
            return 'service:'.(string) $client->getKey();
        }

        $user = $request->user();
        if ($user !== null) {
            $id = $user->getAuthIdentifier();

            return 'user:'.(is_scalar($id) ? (string) $id : 'unknown');
        }

        return 'anon:'.(string) $request->ip();
    }

    private function lock(CacheRepository $cache, string $cacheKey): ?Lock
    {
        $store = $cache->getStore();

        if (!$store instanceof LockProvider) {
            return null;
        }

        return $store->lock($cacheKey.':lock', $this->lockSeconds());
    }

    private function cache(): CacheRepository
    {
        $store = config('api.idempotency.store');

        // Un string vacío (p.ej. API_IDEMPOTENCY_STORE= sin valor en el entorno/CI) NO es un store válido:
        // se trata como null para caer al store por defecto (Valkey en prod, array en tests).
        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    private function isValidKey(string $key): bool
    {
        return strlen($key) <= $this->maxKeyLength()
            && preg_match('/^[\x21-\x7E]+$/', $key) === 1;
    }

    /**
     * @return list<string>
     */
    private function writeMethods(): array
    {
        $methods = config('api.idempotency.methods', ['POST', 'PUT', 'PATCH', 'DELETE']);

        if (!is_array($methods)) {
            return ['POST', 'PUT', 'PATCH', 'DELETE'];
        }

        return array_values(array_map(static fn (mixed $m): string => strtoupper((string) $m), $methods));
    }

    private function headerName(): string
    {
        $header = config('api.idempotency.header', 'Idempotency-Key');

        return is_string($header) && $header !== '' ? $header : 'Idempotency-Key';
    }

    private function ttl(): int
    {
        $ttl = config('api.idempotency.ttl', 86400);

        return is_int($ttl) && $ttl > 0 ? $ttl : 86400;
    }

    private function maxKeyLength(): int
    {
        $max = config('api.idempotency.max_key_length', 255);

        return is_int($max) && $max > 0 ? $max : 255;
    }

    private function lockSeconds(): int
    {
        $seconds = config('api.idempotency.lock_seconds', 15);

        return is_int($seconds) && $seconds > 0 ? $seconds : 15;
    }
}
