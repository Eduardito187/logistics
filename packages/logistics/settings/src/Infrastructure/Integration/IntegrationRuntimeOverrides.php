<?php

declare(strict_types=1);

namespace Logistics\Settings\Infrastructure\Integration;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Aplica, en el boot, la configuración de integraciones guardada desde el panel ENCIMA de la config por
 * env (wiring aditivo con fallback). Como todos los lectores —providers, controladores y vistas Blade—
 * leen de config(), overlayar ahí propaga el valor del admin a TODO sin tocar su lógica.
 *
 * Solo overlaya cuando la integración está habilitada y el parámetro trae valor; si no, se deja la
 * config por env tal cual. Los secretos se descifran acá (IntegrationConfig::secret) justo antes de
 * ponerlos en config(). Nunca falla: el llamador lo invoca tras comprobar que la tabla existe y dentro
 * de un try/catch, para que la plataforma arranque igual sin DB.
 */
final class IntegrationRuntimeOverrides
{
    public function __construct(
        private readonly IntegrationConfig $integrations,
        private readonly Config $config,
    ) {}

    public function apply(): void
    {
        $this->applyRouting();
        $this->applySap();
        $this->applyStorage();
    }

    /**
     * Ruteo: host OSRM, host VROOM, timeout y backend. Overlaya SOLO logistics-planning (el mapa de
     * telemetría ya cae al host de planning por su propio fallback, así que no se toca su config).
     */
    private function applyRouting(): void
    {
        if (!$this->integrations->enabled(IntegrationKeys::ROUTING)) {
            return;
        }

        $host = $this->integrations->value(IntegrationKeys::ROUTING, 'osrm_host');
        if (is_string($host) && $host !== '') {
            $host = rtrim($host, '/');
            $this->config->set('logistics-planning.osrm_host', $host);
        }

        $timeout = $this->integrations->value(IntegrationKeys::ROUTING, 'osrm_timeout');
        if (is_numeric($timeout)) {
            $this->config->set('logistics-planning.osrm_timeout', (int) $timeout);
        }

        $vroomUrl = $this->integrations->value(IntegrationKeys::ROUTING, 'vroom_url');
        if (is_string($vroomUrl) && $vroomUrl !== '') {
            $this->config->set('logistics-planning.routing.vroom_url', $vroomUrl);
        }

        $backend = $this->integrations->value(IntegrationKeys::ROUTING, 'backend');
        if (is_string($backend) && $backend !== '') {
            $this->config->set('logistics-planning.routing.backend', $backend);
        }
    }

    /**
     * Conector SAP: endpoint, credenciales (contraseña cifrada), rutas y timeout. Al overlayar base_url
     * el singleton del conector resuelve al cliente HTTP real; sin él, sigue el stub Null.
     */
    private function applySap(): void
    {
        if (!$this->integrations->enabled(IntegrationKeys::SAP)) {
            return;
        }

        $baseUrl = $this->integrations->value(IntegrationKeys::SAP, 'base_url');
        if (is_string($baseUrl) && $baseUrl !== '') {
            $this->config->set('logistics-sap-connector.sap.base_url', $baseUrl);
        }

        $username = $this->integrations->value(IntegrationKeys::SAP, 'username');
        if (is_string($username) && $username !== '') {
            $this->config->set('logistics-sap-connector.sap.username', $username);
        }

        $password = $this->integrations->secret(IntegrationKeys::SAP, 'password');
        if (is_string($password) && $password !== '') {
            $this->config->set('logistics-sap-connector.sap.password', $password);
        }

        $ordersPath = $this->integrations->value(IntegrationKeys::SAP, 'orders_path');
        if (is_string($ordersPath) && $ordersPath !== '') {
            $this->config->set('logistics-sap-connector.sap.orders_path', $ordersPath);
        }

        $transfersPath = $this->integrations->value(IntegrationKeys::SAP, 'stock_transfers_path');
        if (is_string($transfersPath) && $transfersPath !== '') {
            $this->config->set('logistics-sap-connector.sap.stock_transfers_path', $transfersPath);
        }

        $timeout = $this->integrations->value(IntegrationKeys::SAP, 'timeout');
        if (is_numeric($timeout)) {
            $this->config->set('logistics-sap-connector.sap.timeout', (int) $timeout);
        }
    }

    /**
     * Almacenamiento: credenciales S3 compartidas (llave y secreto cifrados) aplicadas a TODOS los discos
     * con driver s3 (s3, pod, checklist, damage, collages, documents), preservando el bucket propio de
     * cada disco; el bucket general solo rellena los discos que no fijan el suyo (modo bucket único).
     */
    private function applyStorage(): void
    {
        if (!$this->integrations->enabled(IntegrationKeys::STORAGE)) {
            return;
        }

        $key = $this->integrations->secret(IntegrationKeys::STORAGE, 'key');
        $secret = $this->integrations->secret(IntegrationKeys::STORAGE, 'secret');
        $region = $this->integrations->value(IntegrationKeys::STORAGE, 'region');
        $bucket = $this->integrations->value(IntegrationKeys::STORAGE, 'bucket');
        $endpoint = $this->integrations->value(IntegrationKeys::STORAGE, 'endpoint');
        $url = $this->integrations->value(IntegrationKeys::STORAGE, 'url');
        $pathStyle = $this->integrations->value(IntegrationKeys::STORAGE, 'use_path_style_endpoint');

        /** @var array<string, mixed> $disks */
        $disks = (array) $this->config->get('filesystems.disks', []);

        foreach ($disks as $name => $disk) {
            if (!is_array($disk) || ($disk['driver'] ?? null) !== 's3') {
                continue;
            }

            /** @var array<string, mixed> $disk */
            if (is_string($key) && $key !== '') {
                $disk['key'] = $key;
            }
            if (is_string($secret) && $secret !== '') {
                $disk['secret'] = $secret;
            }
            if (is_string($region) && $region !== '') {
                $disk['region'] = $region;
            }
            if (is_string($endpoint) && $endpoint !== '') {
                $disk['endpoint'] = $endpoint;
            }
            if (is_string($url) && $url !== '') {
                $disk['url'] = $url;
            }
            if (is_bool($pathStyle)) {
                $disk['use_path_style_endpoint'] = $pathStyle;
            }
            // El bucket general solo rellena discos sin bucket propio (los discos por tipo usan 'root'
            // como prefijo dentro del bucket general cuando no se les define uno).
            if (is_string($bucket) && $bucket !== '' && ($disk['bucket'] ?? null) === null) {
                $disk['bucket'] = $bucket;
            }

            $this->config->set("filesystems.disks.{$name}", $disk);
        }
    }
}
