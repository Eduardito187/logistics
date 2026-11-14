<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\ReadSide;

use Logistics\Tracking\Application\Contract\PublicTrackingReader;
use Logistics\Tracking\Application\ReadModel\PublicTrackingSnapshot;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Throwable;

/**
 * Decorador cacheado del lector del enlace público: sirve la foto desde Valkey y solo delega en el
 * lector en vivo (la base) cuando la entrada no existe o toca refrescarla.
 *
 * Seguro por defecto:
 * - Un token con forma inválida no se cachea (no consulta la base y no debe ocupar claves).
 * - Si el caché falla (Valkey caído, entrada corrupta) NO se cae el seguimiento: se lee en vivo.
 * - La invalidación por cambio de estado la hace el decorador del repositorio, no este lector.
 */
final readonly class CachedPublicTrackingReader implements PublicTrackingReader
{
    public function __construct(
        private PublicTrackingReader $inner,
        private PublicTrackingCache $cache,
    ) {}

    public function read(string $token): PublicTrackingSnapshot
    {
        if (!$this->cache->enabled() || !$this->hasTokenShape($token)) {
            return $this->inner->read($token);
        }

        try {
            $primitive = $this->cache->remember(
                $token,
                fn (): array => $this->inner->read($token)->toArray(),
            );
        } catch (Throwable $e) {
            // Caché no disponible: se degrada a lectura en vivo. Si lo que falló fue la base, esta
            // segunda lectura vuelve a lanzar y el error sigue su curso normal.
            report($e);

            return $this->inner->read($token);
        }

        $snapshot = PublicTrackingSnapshot::fromArray($primitive);
        if ($snapshot !== null) {
            return $snapshot;
        }

        // Entrada con forma inesperada (otra versión, corrupción): se descarta y se lee en vivo.
        $this->forgetQuietly($token);

        return $this->inner->read($token);
    }

    private function hasTokenShape(string $token): bool
    {
        try {
            TrackingToken::fromString($token);
        } catch (TrackingException) {
            return false;
        }

        return true;
    }

    private function forgetQuietly(string $token): void
    {
        try {
            $this->cache->forget($token);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
