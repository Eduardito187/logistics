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

    // Seguimiento del PEDIDO en vivo por sockets (señal `tracking.updated`): un observador del outbox encola
    // una señal por pedido ante cada cambio del seguimiento (estado visible, avance por producto, ruta que lo
    // transporta) y el trabajo la emite al canal de la ficha del pedido y al tema tracking por ciudad. Es solo
    // la señal para «volver a pedir el estado»; el detalle nunca viaja por el socket. Defaults funcionales en
    // un docker nuevo: sin transporte de sockets (`broadcasting.default` null/log) no encola ni consulta nada.
    'realtime' => [
        // Interruptor del contexto. Con false no se encola ni se emite, aun con transporte de sockets activo.
        'enabled' => (bool) env('TRACKING_REALTIME_ENABLED', true),

        // Ventana anti-ráfaga (segundos): varios cambios seguidos de un mismo pedido se avisan una sola vez.
        // Es también la demora del trabajo, para que al emitir el estado vigente ya incluya la ráfaga completa.
        'debounce_seconds' => (int) env('TRACKING_REALTIME_DEBOUNCE_SECONDS', 2),
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
