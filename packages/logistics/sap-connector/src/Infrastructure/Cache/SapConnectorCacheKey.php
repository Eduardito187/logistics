<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Cache;

/**
 * Claves de cache (Valkey) de los datos de referencia del conector SAP: el mapa sku ↔ material SAP
 * (frente escala).
 *
 * Centraliza la convención para que la pueble el repositorio cacheante y la invalide al guardar. El
 * prefijo lleva el nombre del contexto para que no colisione con otros bounded contexts.
 */
final class SapConnectorCacheKey
{
    private const string PREFIX = 'logistics:sap_connector:';

    /** Lista completa de mapeos de material (EloquentMaterialMappingRepository::all()). */
    public static function materialMappingsAll(): string
    {
        return self::PREFIX.'material_mappings:all';
    }
}
