<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

/**
 * Foto de lo que responde el enlace público por token: el ESTADO del enlace (activo, vencido o
 * inexistente), la vista pública cuando está activo y su huella (`etag`).
 *
 * Es la unidad que se cachea en el read-side público: en caché viaja SIEMPRE su forma primitiva
 * ({@see toArray()}), nunca el objeto serializado (regla 7). Como la vista que contiene, no lleva datos
 * personales ni claves internas: ni id de seguimiento, ni id de envío, ni el token.
 *
 * La huella se calcula UNA vez, al armar la foto, y se guarda junto a ella: así el `ETag` HTTP sale de
 * la versión cacheada sin volver a serializar la respuesta en cada request.
 */
final readonly class PublicTrackingSnapshot
{
    private function __construct(
        public PublicLinkState $state,
        public ?PublicTrackingView $view = null,
        public ?string $etag = null,
    ) {}

    /** Enlace activo: lleva la vista pública y su huella. */
    public static function active(PublicTrackingView $view): self
    {
        return new self(PublicLinkState::Active, $view, self::fingerprint($view));
    }

    /** Enlace vencido (TTL desde la entrega): existe, pero ya no se sirven datos. */
    public static function expired(): self
    {
        return new self(PublicLinkState::Expired);
    }

    /** Token con forma inválida o sin seguimiento: no se revela cuál de los dos. */
    public static function notFound(): self
    {
        return new self(PublicLinkState::NotFound);
    }

    /**
     * Reconstruye la foto desde su forma primitiva (lo que devuelve el caché). Devuelve null si el valor
     * no tiene la forma esperada (entrada corrupta o de otra versión): el llamador la descarta y relee.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $rawState = $data['state'] ?? null;
        $state = is_string($rawState) ? PublicLinkState::tryFrom($rawState) : null;

        if ($state === null) {
            return null;
        }

        if ($state !== PublicLinkState::Active) {
            return new self($state);
        }

        $rawView = $data['view'] ?? null;
        $view = is_array($rawView) ? PublicTrackingView::fromArray($rawView) : null;
        $etag = $data['etag'] ?? null;

        if ($view === null || !is_string($etag) || $etag === '') {
            return null;
        }

        return new self($state, $view, $etag);
    }

    public function isActive(): bool
    {
        return $this->state === PublicLinkState::Active && $this->view !== null;
    }

    /**
     * Forma primitiva para el caché.
     *
     * @return array{state: string, view: array<string, mixed>|null, etag: string|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'view' => $this->view?->toArray(),
            'etag' => $this->etag,
        ];
    }

    /**
     * Huella estable del contenido público. SHA-256 (no MD5): es un validador de caché, no un contexto
     * de seguridad, pero se usa un algoritmo fuerte para no disparar alertas de hash débil.
     */
    private static function fingerprint(PublicTrackingView $view): string
    {
        return hash('sha256', serialize($view->toArray()));
    }
}
