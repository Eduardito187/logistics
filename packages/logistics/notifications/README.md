# logistics/notifications — Notificaciones al cliente

## Responsabilidad

Capa de notificaciones con cascada de canales y reglas configurables por tipo de mensaje: push de la
app de clientes por **ntfy/UnifiedPush** y web push por **VAPID**, ambos por **topic por envío**
(`shipment_<id>`), más Telegram, email y SMS (respaldo crítico con tope). La plataforma publica al topic
y NO guarda tokens ni endpoints de los dispositivos.

## Todo open-source y gratis (decisión del dueño, 2026-10)

El stack de notificaciones es **100% open-source y sin servicios de pago ni de Google**:

- **Push de la app** → **ntfy** self-host (compatible con **UnifiedPush**). Reemplaza a Firebase/FCM,
  que exigía cuenta y proyecto de Google. Es un simple `HTTP POST` al topic del envío (`NTFY_URL`).
- **Web push** → protocolo estándar **Web Push (VAPID)**, entregado por el mismo servidor ntfy (soporte
  nativo de Web Push con claves VAPID, `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY`). Open-source.
- **WhatsApp eliminado**: la Meta WhatsApp Cloud API (y Twilio) son de pago; se quitó el canal del enum,
  los adapters, la cascada y el admin.
- **Telegram** (Bot API, gratis/open) **queda** como canal de mensajería del cliente.
- **SMS** → por un **gateway open-source self-host** (android-sms-gateway / Jasmin / Kannel, vía
  `SMS_GATEWAY_URL`), no un SaaS de pago. Sigue siendo el último recurso por su costo de operador (tope
  mensual atómico).

Nueva cascada por defecto: `PushNtfy → WebPush → Telegram → Email → Sms`. Mientras no se actualice el
ADR formal en `docs/adr/006`, este README es el registro del cambio (este contexto no toca los ADR).

El envío real es asíncrono (por outbox): este contexto define el dominio, el estado de la cascada y los
contratos de canal; el despacho a ntfy/email/SMS corre en el relay.

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

## Cascada por defecto

`PushNtfy → WebPush → Telegram → Email → Sms`, escalando cuando no hubo apertura
(`EscalateCondition::NoOpen`). La resuelve `DefaultNotificationRulesProvider`; se puede reemplazar por
reglas en base o panel sin tocar el dominio.

## Canales reales (detrás de contrato)

El despacho real corre en el relay del outbox por el `NotificationChannelDispatcher`, que al consumir
`notifications.notification_queued` reconstruye la notificación, renderiza el contenido del canal actual
(`NotificationContentRenderer`: plantilla o default por tipo) y lo envía por el `RoutingChannelSender`,
que enruta a un sender real por canal (todos open-source):

- `NtfyChannelSender` (P1-NOT-02): push de la app al topic `shipment_<id>` del servidor **ntfy**
  self-host (`HTTP POST` a `{NTFY_URL}/{topic}`), compatible con **UnifiedPush**. Sin Google.
- `WebPushChannelSender` (P1-NOT-03): **Web Push (VAPID)** sobre el mismo topic, entregado por el soporte
  nativo de Web Push de ntfy. Requiere `NTFY_URL` + `VAPID_PUBLIC_KEY`.
- `TelegramChannelSender`: Bot API (`sendMessage`/`sendPhoto`). Config `TELEGRAM_BOT_TOKEN`. Resuelve el
  `chat_id` del destinatario (columna `telegram_chat_id`).
- `EmailChannelSender` (P1-NOT-04): mailer de Laravel (Mailpit en dev). Arma el HTML con la vista
  `notifications::email.order`, que muestra el **listado de productos con imagen, nombre y cantidad**.
- `SmsChannelSender` (P1-NOT-05): gateway de SMS **open-source** vía `SMS_GATEWAY_URL`, con **tope
  mensual** atómico (`SmsBudgetRepository`). Opciones recomendadas:
  - **android-sms-gateway** (REST con Basic auth; envía por un teléfono Android propio) — define
    `SMS_GATEWAY_USERNAME`/`SMS_GATEWAY_PASSWORD`; el payload por defecto (`message` + `phoneNumbers`)
    es el suyo.
  - **Jasmin** o **Kannel** (SMPP/SMS gateways clásicos) expuestos por su endpoint HTTP de envío; usar
    `SMS_GATEWAY_TOKEN` (Bearer) o el shim HTTP correspondiente.

Sin configuración, cada canal queda "no configurado" y lanza (el relay reintenta/escala); el email de
dev sí sale por Mailpit. La configuración vive en `config/logistics-notifications.php` (env).

## Contenido rico (email + push)

- **Email**: vista Blade `resources/views/email/order.blade.php` con el listado de productos del pedido
  (thumbnail + nombre + cantidad). Los productos los da el contrato `ProductImageResolver`
  (`LineItemProductImageResolver` lee `shipment_lines.product_image_url`; placeholder si no hay imagen).
- **Push (ntfy y web push) y Telegram**: imagen **collage** de los productos. `CollageImageBuilder`
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

- Reglas de cascada **configurables por tipo de mensaje** (P1-NOT-01), **versionadas con historial**:
  la fuente de verdad es el contexto **Configuración** (clave `notifications.cascade_rules`). El puerto
  `CascadeRuleSettings` (impl. `SettingsCascadeRuleGateway`) traduce a/desde Configuración por los buses
  CQRS; el motor LEE sus overrides con `SettingsNotificationRulesProvider` (fallback al default del ADR
  006 por tipo), **no** desde código ni desde una tabla propia. Cada cambio publica una **versión nueva**
  (nunca pisa el historial). Comandos `SetMessageTypeRule` (upsert) y `ResetMessageTypeRule` (vuelve al
  default), query `ListMessageTypeRules`, Filament `Cascade Rules` (editable, con acción `Reset to
  default` y modal `Version history`) y la página de solo lectura `Message Type Rules` (regla efectiva).
  La tabla `MessageTypeRuleRepository` queda como **proyección de solo-estado** para el listado del admin.
- **Plantillas** por tipo, canal y locale (P1-NOT-08): agregado `MessageTemplate` con variables
  `{{clave}}`, comando `UpsertMessageTemplate`, queries `ListMessageTemplates`/`GetMessageTemplate`,
  Filament `Message Templates` (CRUD).

## CQRS del contexto

Comandos: `QueueNotification`, `AdvanceCascade`, `MarkNotificationSent`, `SuppressNotification`,
`SetMessageTypeRule`, `ResetMessageTypeRule`, `UpsertMessageTemplate`. Queries: `ListNotificationsByShipment`,
`ListNotifications`, `GetNotification`, `ListMessageTypeRules`, `ListMessageTemplates`,
`GetMessageTemplate`, `GetSmsBudget`. Todos registrados en el `HandlerRegistry`.

## Estado

Dominio, aplicación (CQRS), infraestructura (Eloquent + canales reales + outbox dispatcher),
factories, seeder, Filament (log + reglas + plantillas) y fragmentos de rutas/OpenAPI implementados.
Verificación en containers (pest/phpstan/pint) **pendiente**: no se corrió ningún comando de
verificación en esta sesión (regla del workflow).
