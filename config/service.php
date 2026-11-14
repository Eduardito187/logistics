<?php

declare(strict_types=1);

/*
| Rol del proceso dentro del despliegue (Ola 6 del roadmap de alta disponibilidad).
|
| El código es UN monolito modular; el rol decide qué superficie HTTP expone este proceso. Con `monolith`
| (default) sirve la app completa, igual que siempre. Con un rol acotado (p. ej. `telemetry`) el MISMO código y la
| MISMA imagen sirven solo las rutas de los contextos de ese rol, lo que permite escalar ese contexto
| como unidad de despliegue aparte sin partir el repositorio (ver docs/perf/extraccion-telemetry.md).
|
| El rol NO cambia qué providers o bindings se registran (otros contextos pueden necesitar sus contratos
| de lectura): solo filtra las rutas HTTP. Lo aplica App\Support\ServiceRole.
*/

return [
    // Rol de este proceso. Un rol que no figura en `roles` se trata como `monolith` (sirve la app completa).
    'role' => (string) env('APP_SERVICE_ROLE', 'monolith'),

    // Rutas que sirve cualquier rol: sondas de vida y métricas (las necesita el balanceador y el monitoreo).
    'always' => [
        'up',
        'api/v1/health',
        'api/v1/health/*',
        'api/metrics',
    ],

    // Roles acotados: contextos que sirve cada uno y los patrones de ruta (sin barra inicial, estilo
    // Request::is) que les corresponden. Para extraer otro contexto se agrega su rol acá.
    'roles' => [
        'telemetry' => [
            'contexts' => ['telemetry'],
            'paths' => [
                'api/v1/app/telemetry',
                'api/v1/app/telemetry/*',
                'api/v1/integrations/telemetry',
                'api/v1/integrations/telemetry/*',
            ],
        ],
    ],
];
