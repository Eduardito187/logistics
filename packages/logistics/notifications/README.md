# logistics/notifications — Notificaciones al cliente

## Responsabilidad

Capa de notificaciones con cascada de canales y reglas configurables por tipo de mensaje: push de la
app de clientes y web push por **topic FCM por envío** (`shipment_<id>`), WhatsApp, Telegram, email y
SMS (respaldo crítico con tope). La plataforma publica al topic y NO guarda tokens de la app de
clientes.

## Cambio sobre el ADR 006 (decisión del dueño, 2026-10)

El ADR 006 original fijaba la cascada **"PushFcm → WebPush → Email → Sms, sin WhatsApp por costo"**.
El dueño del producto pidió habilitar **WhatsApp** (y sumar **Telegram**) como canales más. Se agregan
ambos al enum `Channel`, a los adapters, a la cascada por defecto y al admin. Mientras no se actualice
el ADR formal en `docs/adr/006`, este README es el registro del cambio (este contexto no toca los ADR).
Nueva cascada por defecto: `PushFcm → WebPush → Telegram → Email → WhatsApp → Sms`.

El envío real es asíncrono (por outbox): este contexto define el dominio, el estado de la cascada y los
contratos de canal; el despacho a FCM/email/SMS corre en el relay.

## No es responsable de

Decidir el contenido del tracking. Tampoco envía HTTP síncrono dentro de un request de usuario.

## Estructura

Cuatro capas (Clean Architecture), igual que el resto de los packages:

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
  - `ValueObject`: `NotificationId`, `Channel`, `MessageType`, `NotificationStatus`,
    `EscalateCondition`, `CascadePolicy`, `FcmTopic`, `Recipient`.
  - `Model`: agregado `Notification` (named ctor `queue()`; `markSentVia()`, `escalate()`, `suppress()`).
  - `Event`: `NotificationQueued`, `NotificationSent`.
  - `Contract`: `NotificationRepository`, `ChannelSender`.
  - `Service`: `CascadeResolver` (elige el siguiente canal por la política).
  - `Exception`: `NotificationsException`.
- `src/Application`: CQRS.
  - `Command/QueueNotification`, `Command/AdvanceCascade`.
  - `Query/ListNotificationsByShipment`, `ReadModel/NotificationView`.
  - `Contract/NotificationRulesProvider` (reglas de cascada como configuración).
- `src/Infrastructure`: Eloquent (`NotificationRecord`, `NotificationMapper`,
  `EloquentNotificationRepository`), `DefaultNotificationRulesProvider`, `NullChannelSender`,
  migración y `NotificationsServiceProvider`.
- `src/Presentation`: sin UI por ahora.

## Cascada por defecto (ADR 006)

`PushFcm → WebPush → Email → Sms`, escalando cuando no hubo apertura (`EscalateCondition::NoOpen`).
La resuelve `DefaultNotificationRulesProvider`; se puede reemplazar por reglas en base o panel sin
tocar el dominio.

## Canales reales (detrás de contrato)

El despacho real corre en el relay del outbox por el `NotificationChannelDispatcher`, que al consumir
`notifications.notification_queued` reconstruye la notificación, renderiza el contenido del canal actual
(`NotificationContentRenderer`: plantilla o default por tipo) y lo envía por el `RoutingChannelSender`,
que enruta a un sender real por canal:

- `FcmChannelSender` (P1-NOT-02): publica en el topic `shipment_<id>` vía FCM HTTP v1; token OAuth2 de
  la service account (`ServiceAccountFcmAccessTokenProvider`, cacheado).
- `WebPushChannelSender` (P1-NOT-03): mismo topic, sección `webpush` del mensaje FCM.
- `WhatsAppChannelSender`: Meta WhatsApp Cloud API (Graph `/{phone_id}/messages`); `sendText`/imagen con
  caption. Config `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_ID`. Usa el teléfono del destinatario.
- `TelegramChannelSender`: Bot API (`sendMessage`/`sendPhoto`). Config `TELEGRAM_BOT_TOKEN`. Resuelve el
  `chat_id` del destinatario (columna `telegram_chat_id`).
- `EmailChannelSender` (P1-NOT-04): mailer de Laravel (Mailpit en dev). Arma el HTML con la vista
  `notifications::email.order`, que muestra el **listado de productos con imagen, nombre y cantidad**.
- `SmsChannelSender` (P1-NOT-05): proveedor HTTP, con **tope mensual** atómico (`SmsBudgetRepository`).

Sin credenciales, cada canal queda "no configurado" y lanza (el relay reintenta/escala); el email de
dev sí sale por Mailpit. La configuración vive en `config/logistics-notifications.php` (env).

## Contenido rico (email + push)

- **Email**: vista Blade `resources/views/email/order.blade.php` con el listado de productos del pedido
  (thumbnail + nombre + cantidad). Los productos los da el contrato `ProductImageResolver`
  (`LineItemProductImageResolver` lee `shipment_lines.product_image_url`; placeholder si no hay imagen).
- **Push (FCM y web push), WhatsApp y Telegram**: imagen **collage** de los productos. `CollageImageBuilder`
  (`GdCollageImageBuilder`, extensión GD) arma una grilla de **hasta 4 productos**; con más de 4 muestra
  3 + una celda **"+N"**. `PushImageResolver` compone resolutor + armador + `CollageImageStore`
  (`FilesystemCollageImageStore`, disco `public`/S3) y pone la URL en el campo `image` del payload. Si GD
  no está o no hay imágenes, **degrada a envío sin imagen** (no rompe).

## Wiring por eventos de dominio

`DomainEventNotificationDispatcher` (un `MessageDispatcher` del outbox) mapea hechos de otros contextos a
la notificación al cliente y encola `QueueNotification`, de forma **idempotente**
(`NotificationRepository::existsForShipmentAndType`):

| Evento | Tipo de mensaje |
| --- | --- |
| `shipments.shipment_received` | `ORDER_RECEIVED` |
| `execution.shipment_prepared` | `IN_PREPARATION` |
| `execution.node_reception_recorded` | `AT_DISTRIBUTION_CENTER` |
| `execution.shipment_loaded` | `LOADED` |
| `execution.shipment_delivered` | `DELIVERED` |
| `execution.delivery_failed` | `FAILED` |
| `tracking.status_changed` | `EN_ROUTE` |

**Integración (fuera de este package):** enrutar los prefijos `shipments.`, `execution.` y `tracking.` a
`DomainEventNotificationDispatcher::class` en `config/logistics-outbox.php` → `routes`. Como ese archivo
es compartido, no se modifica desde este contexto; el integrador agrega las entradas.

## Reglas por tipo y plantillas

- Reglas de cascada **configurables por tipo de mensaje** (P1-NOT-01): `MessageTypeRuleRepository` +
  `PersistedNotificationRulesProvider` (regla del panel con fallback al default del ADR 006). Comando
  `SetMessageTypeRule`, query `ListMessageTypeRules`, Filament `Cascade Rules` (editable) y la página
  de solo lectura `Message Type Rules` (regla efectiva).
- **Plantillas** por tipo, canal y locale (P1-NOT-08): agregado `MessageTemplate` con variables
  `{{clave}}`, comando `UpsertMessageTemplate`, queries `ListMessageTemplates`/`GetMessageTemplate`,
  Filament `Message Templates` (CRUD).

## CQRS del contexto

Comandos: `QueueNotification`, `AdvanceCascade`, `MarkNotificationSent`, `SuppressNotification`,
`SetMessageTypeRule`, `UpsertMessageTemplate`. Queries: `ListNotificationsByShipment`,
`ListNotifications`, `GetNotification`, `ListMessageTypeRules`, `ListMessageTemplates`,
`GetMessageTemplate`, `GetSmsBudget`. Todos registrados en el `HandlerRegistry`.

## Estado

Dominio, aplicación (CQRS), infraestructura (Eloquent + canales reales + outbox dispatcher),
factories, seeder, Filament (log + reglas + plantillas) y fragmentos de rutas/OpenAPI implementados.
Verificación en containers (pest/phpstan/pint) **pendiente**: no se corrió ningún comando de
verificación en esta sesión (regla del workflow).
