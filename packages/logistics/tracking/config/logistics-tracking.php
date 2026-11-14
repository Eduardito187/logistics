<?php

declare(strict_types=1);

// Read-side del seguimiento PÚBLICO (ola 6 del roadmap de alta disponibilidad): caché de lectura en
// Valkey con invalidación por evento + cabeceras para el caché de borde. Los defaults son funcionales en
// un docker nuevo (regla "funciona en docker nuevo"): no hace falta definir ninguna variable.
// Diseño completo en docs/perf/tracking-read-side.md.
return [
    'public_cache' => [
        // Caché de la vista pública por token. Con false, cada consulta va a la base (réplica si existe).
        'enabled' => (bool) env('TRACKING_PUBLIC_CACHE_ENABLED', true),

        // Store de caché a usar. Null = el store por defecto de la app (Valkey en producción).
        'store' => env('TRACKING_PUBLIC_CACHE_STORE'),

        // Ventana FRESCA (segundos): dentro de ella la vista sale de Valkey sin tocar la base. Es la
        // antigüedad máxima de la POSICIÓN y la ETA; el cambio de ESTADO no espera: se invalida por evento.
        'ttl_fresh' => (int) env('TRACKING_PUBLIC_CACHE_TTL_FRESH', 20),

        // Ventana STALE (segundos, contada desde que se guardó): entre fresh y stale se sirve el valor
        // guardado y UN solo request lo refresca en segundo plano (anti-stampede, Cache::flexible).
        'ttl_stale' => (int) env('TRACKING_PUBLIC_CACHE_TTL_STALE', 120),

        // Caché NEGATIVO (segundos) de tokens inexistentes: un barrido de tokens no golpea la base. Nunca
        // supera a ttl_fresh (se recorta) y se invalida al crearse el seguimiento.
        'ttl_negative' => (int) env('TRACKING_PUBLIC_CACHE_TTL_NEGATIVE', 15),
    ],

    'public_http' => [
        // Cabeceras `Cache-Control: public` + `ETag` en las respuestas públicas por token (JSON y página
        // HTML). Solo se emiten para visitantes anónimos; nunca en respuestas autenticadas.
        'enabled' => (bool) env('TRACKING_PUBLIC_HTTP_CACHE_ENABLED', true),

        // max-age (segundos) para el navegador y el caché de borde (CDN / micro-caché de nginx).
        'max_age' => (int) env('TRACKING_PUBLIC_HTTP_MAX_AGE', 20),

        // stale-while-revalidate (segundos): el borde puede servir el valor vencido mientras revalida.
        'stale_while_revalidate' => (int) env('TRACKING_PUBLIC_HTTP_SWR', 30),
    ],
];
