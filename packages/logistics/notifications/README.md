# logistics/notifications — Notificaciones al cliente

## Responsabilidad

Capa de notificaciones con cascada de canales y reglas configurables por tipo de mensaje (ADR 006,
sin WhatsApp): push de la app de clientes y web push por **topic FCM por envío** (`shipment_<id>`),
email y SMS solo como respaldo crítico con tope. La plataforma publica al topic y NO guarda tokens de
la app de clientes.

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

## Integración pendiente (outbox)

Los handlers de escritura llaman a `releaseEvents()` con `// TODO(outbox)`: al integrar el package
`logistics/outbox`, esos eventos se despachan en la misma transacción y el relay hace el envío real
por cada `ChannelSender` de canal (hoy `NullChannelSender`, que solo loguea).

## Estado

Dominio, aplicación (CQRS) e infraestructura (Eloquent + provider) implementados. Verificación en
containers (pest/phpstan/pint) pendiente: Docker no estuvo disponible al construir. Solo se validó
`php -l` sobre todos los archivos.
