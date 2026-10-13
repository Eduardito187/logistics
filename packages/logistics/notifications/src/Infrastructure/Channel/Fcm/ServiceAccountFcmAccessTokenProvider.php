<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel\Fcm;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;

/**
 * Obtiene el access token de FCM firmando un JWT con la service account del proyecto Firebase y
 * canjeándolo en el endpoint OAuth2 de Google (flujo "JWT Bearer"). Cachea el token hasta poco antes
 * de su expiración para no firmar en cada despacho.
 *
 * Si no hay ruta de credenciales configurada (o el archivo no existe / no es válido), devuelve null:
 * el canal FCM queda "no configurado" y el sender decide el comportamiento.
 */
final class ServiceAccountFcmAccessTokenProvider implements FcmAccessTokenProvider
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const CACHE_KEY = 'notifications:fcm:access_token';

    public function __construct(
        private readonly ?string $credentialsPath,
        private readonly string $scope,
        private readonly int $timeout,
        private readonly Cache $cache,
    ) {}

    public function token(): ?string
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->readCredentials();
        if ($credentials === null) {
            return null;
        }

        $assertion = $this->buildAssertion($credentials);
        if ($assertion === null) {
            return null;
        }

        $response = Http::asForm()
            ->timeout($this->timeout)
            ->post(self::TOKEN_ENDPOINT, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if ($response->failed()) {
            return null;
        }

        /** @var array{access_token?: string, expires_in?: int} $body */
        $body = $response->json();
        $token = $body['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        // Cachea restando un margen de seguridad a la expiración declarada por Google.
        $ttl = max(60, (int) ($body['expires_in'] ?? 3600) - 60);
        $this->cache->put(self::CACHE_KEY, $token, $ttl);

        return $token;
    }

    /**
     * Lee el JSON de la service account desde disco.
     *
     * @return array{client_email?: string, private_key?: string, token_uri?: string}|null
     */
    private function readCredentials(): ?array
    {
        if ($this->credentialsPath === null || $this->credentialsPath === '' || !is_file($this->credentialsPath)) {
            return null;
        }

        $raw = file_get_contents($this->credentialsPath);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null; // @phpstan-ignore-line forma validada al usarse
    }

    /**
     * Arma y firma (RS256) el JWT de la service account.
     *
     * @param array{client_email?: string, private_key?: string, token_uri?: string} $credentials
     */
    private function buildAssertion(array $credentials): ?string
    {
        $clientEmail = $credentials['client_email'] ?? null;
        $privateKey = $credentials['private_key'] ?? null;
        if (!is_string($clientEmail) || !is_string($privateKey)) {
            return null;
        }

        $now = time();
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode([
            'iss' => $clientEmail,
            'scope' => $this->scope,
            'aud' => self::TOKEN_ENDPOINT,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signingInput = $header.'.'.$claims;
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $signingInput.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
