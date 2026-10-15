<?php

declare(strict_types=1);

namespace Logistics\Settings\Infrastructure\Integration;

/**
 * Claves de configuración (namespaced) de las integraciones externas que se administran desde el panel.
 *
 * Cada clave guarda un objeto JSON versionado en `settings_versions` con la forma
 * `{enabled: bool, ...parámetros}`. Los parámetros secretos (contraseñas, llaves de acceso) se guardan
 * CIFRADOS con Crypt::encryptString y se descifran al leerlos (ver IntegrationConfig). Las constantes
 * evitan strings mágicos repartidos entre el panel, el resolver y los overrides de runtime.
 */
final class IntegrationKeys
{
    /** Ruteo por carretera: hosts OSRM y VROOM (contexto Planificación / Telemetría). */
    public const ROUTING = 'integrations.routing';

    /** Conector SAP: endpoint y credenciales del servicio SAP (ADR 002). */
    public const SAP = 'integrations.sap';

    /** Almacenamiento S3-compatible de documentos y evidencias (discos de config/filesystems.php). */
    public const STORAGE = 'integrations.storage';

    /**
     * Nombres de los parámetros SECRETOS por clave: se cifran al guardar y se descifran al leer.
     *
     * @var array<string, list<string>>
     */
    public const SECRET_PARAMS = [
        self::SAP => ['password'],
        self::STORAGE => ['key', 'secret'],
    ];
}
