<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Fcm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Logistics\Notifications\Domain\Exception\NotificationsException;

/**
 * Obtiene el access token OAuth2 del service account de Firebase para hablar con la API HTTP v1 de FCM,
 * SIN depender del SDK de Google ni de ninguna librería composer nueva.
 *
 * Flujo (JWT bearer, estándar de Google):
 *  1. Lee el JSON del service account desde la ruta configurada (client_email, private_key, token_uri).
 *  2. Arma un JWT RS256 con claims iss=client_email, scope=firebase.messaging, aud=token_uri, iat, exp
 *     (+3600s) y lo firma con la private_key vía openssl_sign(..., OPENSSL_ALGO_SHA256).
 *  3. Intercambia el JWT en token_uri (grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer) por el
 *     access_token de corta duración.
 *
 * El token se cachea ~55 minutos (el de Google dura 3600s) con una clave por proyecto/service account,
 * para no renegociarlo en cada push del relay. Si faltan credenciales o el intercambio falla, lanza
 * NotificationsException y el relay aplica backoff / escala (igual que hacía el canal push anterior).
 */
final readonly class FcmAccessToken
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_TTL_SECONDS = 3300;

    /**
     * @param array{project_id: ?string, credentials: ?string, timeout: int} $config
     */
    public function __construct(
        private array $config,
    ) {}

    /**
     * Devuelve un access token OAuth2 válido para FCM (del caché si todavía sirve, o renegociado).
     */
    public function value(): string
    {
        $serviceAccount = $this->readServiceAccount();

        $cacheKey = sprintf(
            'logistics-notifications:fcm:access-token:%s',
            sha1(($this->config['project_id'] ?? '').'|'.$serviceAccount['client_email']),
        );

        $token = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): string => $this->requestAccessToken($serviceAccount),
        );

        if (!is_string($token) || $token === '') {
            throw NotificationsException::because('no se pudo obtener el access token de FCM');
        }

        return $token;
    }

    /**
     * Lee y valida el JSON del service account desde la ruta 'credentials'.
     *
     * @return array{client_email: string, private_key: string, token_uri: string}
     */
    private function readServiceAccount(): array
    {
        $path = $this->config['credentials'] ?? null;
        if ($path === null || trim($path) === '') {
            throw NotificationsException::because('el canal push (FCM) no está configurado (falta FCM_CREDENTIALS)');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw NotificationsException::because("no se pudo leer el service account de FCM en {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw NotificationsException::because("no se pudo leer el service account de FCM en {$path}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw NotificationsException::because('el service account de FCM no es un JSON válido');
        }

        $clientEmail = $decoded['client_email'] ?? null;
        $privateKey = $decoded['private_key'] ?? null;
        $tokenUri = $decoded['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        if (!is_string($clientEmail) || $clientEmail === '' || !is_string($privateKey) || $privateKey === '') {
            throw NotificationsException::because('el service account de FCM no trae client_email/private_key');
        }
        if (!is_string($tokenUri) || $tokenUri === '') {
            $tokenUri = 'https://oauth2.googleapis.com/token';
        }

        return [
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
            'token_uri' => $tokenUri,
        ];
    }

    /**
     * Arma y firma el JWT bearer y lo intercambia en token_uri por el access_token.
     *
     * @param array{client_email: string, private_key: string, token_uri: string} $serviceAccount
     */
    private function requestAccessToken(array $serviceAccount): string
    {
        $jwt = $this->buildSignedJwt($serviceAccount);

        $response = Http::asForm()
            ->timeout($this->config['timeout'])
            ->post($serviceAccount['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        if ($response->failed()) {
            throw NotificationsException::because(
                "el intercambio OAuth2 de FCM falló: HTTP {$response->status()}",
            );
        }

        $accessToken = $response->json('access_token');
        if (!is_string($accessToken) || $accessToken === '') {
            throw NotificationsException::because('la respuesta OAuth2 de FCM no trajo access_token');
        }

        return $accessToken;
    }

    /**
     * Construye el JWT RS256 (header.payload.signature) firmado con la private_key del service account.
     *
     * @param array{client_email: string, private_key: string, token_uri: string} $serviceAccount
     */
    private function buildSignedJwt(array $serviceAccount): string
    {
        $issuedAt = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $serviceAccount['client_email'],
            'scope' => self::SCOPE,
            'aud' => $serviceAccount['token_uri'],
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ];

        $signingInput = $this->base64UrlEncode($this->encodeJson($header))
            .'.'.$this->base64UrlEncode($this->encodeJson($claims));

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
        if ($signed === false || $signature === '') {
            throw NotificationsException::because('no se pudo firmar el JWT del service account de FCM');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * Codifica un arreglo a JSON compacto, fallando explícito si no se puede.
     *
     * @param array<string, mixed> $data
     */
    private function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw NotificationsException::because('no se pudo serializar el JWT del service account de FCM');
        }

        return $json;
    }

    /**
     * Base64 URL-safe sin padding (como exige JWS).
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
