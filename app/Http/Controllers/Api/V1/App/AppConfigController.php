<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Middleware\EnsureAppVersion;
use App\Http\Support\AppClientConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración dictada por el servidor que la app interna necesita al arrancar (`GET /api/v1/app/app-config`,
 * auditoría app #8 + pedido «versión mínima»): versión mínima/última por plataforma y URL de la tienda,
 * feature flags, teselas del mapa, contacto de soporte, intervalos de sondeo y ajustes de terreno.
 *
 * Público (sin token, con throttle): la app lo consulta ANTES del login para saber si debe actualizarse.
 * No expone secretos. Nunca se rechaza por versión (ver EnsureAppVersion) para que una app vieja sepa a
 * dónde ir a actualizarse.
 */
final class AppConfigController
{
    public function __invoke(Request $request, AppClientConfig $config): JsonResponse
    {
        $platform = AppClientConfig::normalizePlatform($request->header(EnsureAppVersion::PLATFORM_HEADER));

        $response = new JsonResponse(['data' => [
            'platform' => $platform,
            'version' => [
                'min_app_version' => $config->minVersion($platform),
                'latest_app_version' => $config->latestVersion($platform),
                'update_url' => $config->storeUrl($platform),
                'enforced' => $config->enforceMinVersion(),
                'min_app_versions' => $config->minVersions(),
            ],
            // Objeto JSON aunque no haya flags (un array PHP vacío se serializaría como []).
            'feature_flags' => $config->featureFlags() === [] ? (object) [] : $config->featureFlags(),
            'map' => $config->map(),
            'support' => $config->support(),
            'polling' => $config->polling(),
            'field' => $config->field(),
        ]]);

        // Cacheable un rato en el cliente/proxy: son valores que cambian poco.
        $response->headers->set('Cache-Control', 'public, max-age=300');
        $response->headers->set('Vary', EnsureAppVersion::PLATFORM_HEADER);

        return $response;
    }
}
