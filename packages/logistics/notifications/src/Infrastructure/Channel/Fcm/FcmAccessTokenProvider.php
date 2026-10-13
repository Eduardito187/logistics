<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel\Fcm;

/**
 * Proveedor del access token OAuth2 para la API FCM HTTP v1. Se aísla en un contrato para poder
 * cachearlo y para que los tests inyecten un token fijo sin tocar red.
 */
interface FcmAccessTokenProvider
{
    /**
     * Devuelve un bearer token válido para publicar en FCM, o null si el canal no está configurado
     * (sin service account): el sender decide si falla o loguea.
     */
    public function token(): ?string;
}
