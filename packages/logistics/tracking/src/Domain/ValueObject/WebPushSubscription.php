<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Suscripción estándar del navegador al Web Push (la que devuelve `PushManager.subscribe`): el endpoint
 * del push service y las claves públicas p256dh/auth de cifrado del mensaje.
 *
 * Es un value object inmutable (PHP puro). Valida forma mínima: endpoint https no vacío y claves
 * base64url presentes. No conoce VAPID ni el transporte: solo es el dato que el navegador entrega y que
 * la plataforma guarda. El endpoint es la clave natural (idempotencia de la suscripción).
 */
final readonly class WebPushSubscription
{
    private const int MAX_ENDPOINT_LENGTH = 1024;

    private const int MAX_KEY_LENGTH = 255;

    private const string KEY_PATTERN = '/^[A-Za-z0-9_-]+$/';

    private function __construct(
        public string $endpoint,
        public string $p256dh,
        public string $auth,
    ) {}

    public static function of(string $endpoint, string $p256dh, string $auth): self
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw TrackingException::because('el endpoint de la suscripción no puede estar vacío');
        }
        if (strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
            throw TrackingException::because('el endpoint de la suscripción es demasiado largo');
        }
        if (!str_starts_with($endpoint, 'https://')) {
            throw TrackingException::because('el endpoint de la suscripción debe ser https');
        }

        $p256dh = self::validateKey($p256dh, 'p256dh');
        $auth = self::validateKey($auth, 'auth');

        return new self($endpoint, $p256dh, $auth);
    }

    /**
     * Valida una clave de la suscripción: base64url no vacío y dentro de longitud.
     */
    private static function validateKey(string $key, string $name): string
    {
        $key = trim($key);
        if ($key === '') {
            throw TrackingException::because("la clave {$name} de la suscripción no puede estar vacía");
        }
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw TrackingException::because("la clave {$name} de la suscripción es demasiado larga");
        }
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw TrackingException::because("la clave {$name} de la suscripción debe ser base64url");
        }

        return $key;
    }
}
