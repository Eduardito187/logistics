<?php

declare(strict_types=1);

// Configuración del contexto Notificaciones al cliente (ADR 006). TODO el stack es open-source y gratis
// (decisión del dueño, 2026-10): push por ntfy/UnifiedPush self-host, web push por VAPID, SMS por un
// gateway open-source; sin Firebase/FCM ni WhatsApp de pago. Los secretos (token de ntfy, claves VAPID,
// credenciales del gateway SMS) van en .env / gestor de secretos, nunca en el repo ni en settings
// editables. Sin configuración, cada canal cae a su modo "no configurado" (loguea y falla con mensaje
// claro para que el relay reintente/escale), salvo email, que en dev sale por Mailpit.
return [
    // Locale por defecto de las plantillas cuando el envío no especifica uno.
    'default_locale' => env('NOTIFICATIONS_DEFAULT_LOCALE', 'es'),

    // Contacto del SUPERVISOR de operaciones para los avisos internos (p. ej. incidente de camión en
    // ruta). El canal primario es el topic del envío anclado; estos son respaldos opcionales.
    'supervisor' => [
        'email' => env('NOTIFICATIONS_SUPERVISOR_EMAIL'),
        'phone' => env('NOTIFICATIONS_SUPERVISOR_PHONE'),
    ],

    // Canal push de la app de clientes sobre ntfy self-host (compatible con UnifiedPush), por topic del
    // envío (shipment_<id>). Open-source y gratis; reemplaza a Firebase/FCM.
    'ntfy' => [
        // URL del servidor ntfy. Por defecto el del docker-compose. Sin ella, el canal no envía.
        'url' => env('NTFY_URL', 'http://ntfy:80'),

        // Token de acceso de ntfy, solo si el servidor exige auth para publicar (opcional en self-host).
        'token' => env('NTFY_TOKEN'),

        // Prioridad por defecto del push (1..5; ntfy: min/low/default/high/max).
        'priority' => env('NTFY_PRIORITY', 'default'),

        // Timeout por request en segundos: publicar al topic nunca debe colgar un worker del relay.
        'timeout' => (int) env('NTFY_TIMEOUT', 5),
    ],

    // Canal web push de la página de tracking sobre el protocolo estándar Web Push (VAPID), open-source.
    // La entrega la hace el mismo servidor ntfy (soporte nativo de Web Push con claves VAPID). La clave
    // pública se expone al service worker de la página de tracking para suscribir al navegador.
    'web_push' => [
        // Reutiliza el servidor ntfy (mismo topic por envío).
        'url' => env('NTFY_URL', 'http://ntfy:80'),

        'token' => env('NTFY_TOKEN'),

        // Par de claves VAPID (generar con `npx web-push generate-vapid-keys` o `ntfy webpush keys`).
        // La pública también la usa el frontend; la privada vive en el servidor ntfy.
        'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
        'vapid_subject' => env('VAPID_SUBJECT', 'mailto:no-reply@dismac.test'),

        'timeout' => (int) env('NTFY_TIMEOUT', 5),
    ],

    // Canal email transaccional. En dev sale por Mailpit (mailer por defecto de Laravel).
    'email' => [
        'mailer' => env('NOTIFICATIONS_EMAIL_MAILER'),
        'from_address' => env('NOTIFICATIONS_EMAIL_FROM', 'no-reply@dismac.test'),
        'from_name' => env('NOTIFICATIONS_EMAIL_FROM_NAME', 'Dismac Logistics'),
    ],

    // Canal Telegram sobre la Bot API (sendMessage/sendPhoto).
    'telegram' => [
        'endpoint' => env('TELEGRAM_ENDPOINT', 'https://api.telegram.org'),

        // Token del bot (de @BotFather). Sin él, el canal no envía.
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),

        'timeout' => (int) env('TELEGRAM_TIMEOUT', 5),
    ],

    // Canal SMS de respaldo crítico, con tope mensual (P1-NOT-05 / F0-FIRE-04). Habla por un gateway de
    // SMS open-source y self-host (android-sms-gateway / Jasmin / Kannel), NO un SaaS de pago.
    'sms' => [
        // URL del endpoint de envío del gateway open-source (HTTP). Sin ella, el canal no envía.
        'url' => env('SMS_GATEWAY_URL'),

        // Auth del gateway: Basic (android-sms-gateway usa usuario/clave) o Bearer token. Opcionales.
        'username' => env('SMS_GATEWAY_USERNAME'),
        'password' => env('SMS_GATEWAY_PASSWORD'),
        'token' => env('SMS_GATEWAY_TOKEN'),

        // Remitente/sender id, cuando el gateway lo respeta.
        'sender' => env('NOTIFICATIONS_SMS_SENDER', 'Dismac'),

        // Tope de SMS por mes: protege el presupuesto. 0 = SMS deshabilitado.
        'monthly_cap' => (int) env('NOTIFICATIONS_SMS_MONTHLY_CAP', 2000),

        'timeout' => (int) env('SMS_GATEWAY_TIMEOUT', 5),
    ],

    // Contenido rico: collage de imágenes de producto para push (ntfy/web push) y Telegram, y listado
    // con imágenes para el email.
    'rich_content' => [
        // Disco de Laravel donde se guarda el PNG del collage (local en dev, s3 en producción).
        'collage_disk' => env('NOTIFICATIONS_COLLAGE_DISK', 'public'),

        // Carpeta dentro del disco.
        'collage_directory' => env('NOTIFICATIONS_COLLAGE_DIR', 'collages'),

        // Timeout por descarga de imagen de producto al armar el collage (segundos).
        'image_timeout' => (int) env('NOTIFICATIONS_IMAGE_TIMEOUT', 5),

        // Imagen placeholder cuando una línea del pedido no trae imagen (null = sin imagen).
        'placeholder_url' => env('NOTIFICATIONS_PRODUCT_PLACEHOLDER'),

        // Habilita el collage con GD en los push. Si GD no está cargado, cae a push sin imagen igual.
        'collage_enabled' => (bool) env('NOTIFICATIONS_COLLAGE_ENABLED', true),
    ],
];
