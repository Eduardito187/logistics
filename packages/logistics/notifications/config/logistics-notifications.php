<?php

declare(strict_types=1);

// Configuración del contexto Notificaciones al cliente (ADR 006). El push de la app de clientes va por
// Firebase Cloud Messaging (FCM), por topic del envío, vía la API HTTP v1 (decisión del dueño,
// 2026-10-16); Telegram, email y SMS (por gateway open-source) son los canales de respaldo. Los
// secretos (JSON del service account de FCM, token del bot de Telegram, credenciales del gateway SMS)
// van en .env / gestor de secretos, nunca en el repo ni en settings editables. Sin configuración, cada
// canal cae a su modo "no configurado" (loguea y falla con mensaje claro para que el relay
// reintente/escale), salvo email, que sale por el sendmail nativo del host.
return [
    // Locale por defecto de las plantillas cuando el envío no especifica uno.
    'default_locale' => env('NOTIFICATIONS_DEFAULT_LOCALE', 'es'),

    // Contacto del SUPERVISOR de operaciones para los avisos internos (p. ej. incidente de camión en
    // ruta). El canal primario es el topic del envío anclado; estos son respaldos opcionales.
    'supervisor' => [
        'email' => env('NOTIFICATIONS_SUPERVISOR_EMAIL'),
        'phone' => env('NOTIFICATIONS_SUPERVISOR_PHONE'),
    ],

    // Canal push de la app de clientes sobre Firebase Cloud Messaging (FCM), por topic del envío
    // (shipment_<id>), vía la API HTTP v1. La plataforma publica al topic sin tokens de dispositivo. El
    // access token OAuth2 se obtiene del service account (FcmAccessToken) sin dependencias de Google.
    'fcm' => [
        // Id del proyecto de Firebase/GCP. Sin él (o sin credenciales), el canal no envía.
        'project_id' => env('FCM_PROJECT_ID'),

        // Ruta al JSON del service account (service-account.json). El FcmAccessToken lo lee con
        // file_get_contents + json_decode y arma/firma el JWT RS256 para el intercambio OAuth2.
        'credentials' => env('FCM_CREDENTIALS'),

        // Timeout por request en segundos (token y messages:send): nunca debe colgar un worker del relay.
        'timeout' => (int) env('FCM_TIMEOUT', 10),
    ],

    // Canal email transaccional. Sale por el mailer de Laravel (sendmail nativo del host).
    'email' => [
        'mailer' => env('NOTIFICATIONS_EMAIL_MAILER'),
        'from_address' => env('NOTIFICATIONS_EMAIL_FROM', 'no-reply@dismac.test'),
        'from_name' => env('NOTIFICATIONS_EMAIL_FROM_NAME', 'DisTrack'),
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

    // Contenido rico: collage de imágenes de producto para push (FCM) y Telegram, y listado
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
