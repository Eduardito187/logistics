<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Cache;

/**
 * Claves de cache (Valkey) de los datos de referencia de notificaciones: plantillas de mensaje y reglas
 * de cascada por tipo (frente escala).
 *
 * Centraliza la convención para que la pueblen los repositorios cacheantes y la invaliden al
 * guardar/remover. El prefijo lleva el nombre del contexto para que no colisione con otros contextos.
 */
final class NotificationsCacheKey
{
    private const string PREFIX = 'logistics:notifications:';

    /** Lista completa de plantillas de mensaje (EloquentMessageTemplateRepository::all()). */
    public static function templatesAll(): string
    {
        return self::PREFIX.'templates:all';
    }

    /** Todas las reglas de cascada por tipo (EloquentMessageTypeRuleRepository::all()). */
    public static function typeRulesAll(): string
    {
        return self::PREFIX.'type_rules:all';
    }
}
