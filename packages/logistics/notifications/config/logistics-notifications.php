<?php

declare(strict_types=1);

// Configuración del contexto Notificaciones al cliente (ADR 006). Los secretos (credenciales FCM,
// token del proveedor de SMS) van en .env / gestor de secretos, nunca en el repo ni en settings
// editables. Sin credenciales, cada canal cae a su modo "no configurado" (loguea y falla con mensaje
// claro para que el relay reintente/escale), salvo email, que en dev sale por Mailpit.
return [
    // Locale por defecto de las plantillas cuando el envío no especifica uno.
    'default_locale' => env('NOTIFICATIONS_DEFAULT_LOCALE', 'es'),

    // Contacto del SUPERVISOR de operaciones para los avisos internos (p. ej. incidente de camión en
    // ruta). El canal primario es el topic FCM del envío anclado; estos son respaldos opcionales.
    'supervisor' => [
        'email' => env('NOTIFICATIONS_SUPERVISOR_EMAIL'),
        'phone' => env('NOTIFICATIONS_SUPERVISOR_PHONE'),
    ],

    // Canal push de la app de clientes y web push, por topic FCM del envío (shipment_<id>).
    'fcm' => [
        // Project id del proyecto Firebase de la app de clientes (F0-FIRE-01).
        'project_id' => env('NOTIFICATIONS_FCM_PROJECT_ID'),

        // Ruta al JSON de la service account con permiso de FCM (OAuth2). Sin ella, el canal no envía.
        'credentials' => env('NOTIFICATIONS_FCM_CREDENTIALS'),

        // Endpoint HTTP v1 de envío; {project} se reemplaza por project_id.
        'endpoint' => env(
            'NOTIFICATIONS_FCM_ENDPOINT',
            'https://fcm.googleapis.com/v1/projects/{project}/messages:send',
        ),

        // Scope OAuth2 para pedir el token de la service account.
        'scope' => env('NOTIFICATIONS_FCM_SCOPE', 'https://www.googleapis.com/auth/firebase.messaging'),

        // Timeout por request en segundos: publicar al topic nunca debe colgar un worker del relay.
        'timeout' => (int) env('NOTIFICATIONS_FCM_TIMEOUT', 5),
    ],

    // Canal email transaccional. En dev sale por Mailpit (mailer por defecto de Laravel).
    'email' => [
        'mailer' => env('NOTIFICATIONS_EMAIL_MAILER'),
        'from_address' => env('NOTIFICATIONS_EMAIL_FROM', 'no-reply@dismac.test'),
        'from_name' => env('NOTIFICATIONS_EMAIL_FROM_NAME', 'Dismac Logistics'),
    ],

    // Canal WhatsApp sobre la Meta WhatsApp Cloud API (Graph API). DECISIÓN DEL DUEÑO: el ADR 006
    // original excluía WhatsApp por costo; el dueño pidió habilitarlo como un canal más (ver README).
    'whatsapp' => [
        // Endpoint base de la Graph API de Meta.
        'endpoint' => env('WHATSAPP_ENDPOINT', 'https://graph.facebook.com'),

        // Token permanente de la app de WhatsApp Business. Sin él, el canal no envía.
        'token' => env('WHATSAPP_TOKEN'),

        // Id del número de teléfono de WhatsApp Business (phone number id).
        'phone_id' => env('WHATSAPP_PHONE_ID'),

        // Versión de la Graph API.
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),

        'timeout' => (int) env('WHATSAPP_TIMEOUT', 5),
    ],

    // Canal Telegram sobre la Bot API (sendMessage/sendPhoto).
    'telegram' => [
        'endpoint' => env('TELEGRAM_ENDPOINT', 'https://api.telegram.org'),

        // Token del bot (de @BotFather). Sin él, el canal no envía.
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),

        'timeout' => (int) env('TELEGRAM_TIMEOUT', 5),
    ],

    // Canal SMS de respaldo crítico, con tope mensual (P1-NOT-05 / F0-FIRE-04).
    'sms' => [
        // Endpoint del proveedor de SMS (HTTP). Sin él, el canal no envía.
        'endpoint' => env('NOTIFICATIONS_SMS_ENDPOINT'),

        // Credencial del proveedor (bearer token / api key).
        'token' => env('NOTIFICATIONS_SMS_TOKEN'),

        // Remitente/sender id configurado con el proveedor.
        'sender' => env('NOTIFICATIONS_SMS_SENDER', 'Dismac'),

        // Tope de SMS por mes: protege el presupuesto. 0 = SMS deshabilitado.
        'monthly_cap' => (int) env('NOTIFICATIONS_SMS_MONTHLY_CAP', 2000),

        'timeout' => (int) env('NOTIFICATIONS_SMS_TIMEOUT', 5),
    ],

    // Contenido rico: collage de imágenes de producto para push/WhatsApp/Telegram y listado con
    // imágenes para el email.
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
