# logistics/outbox — Transactional Outbox

## Responsabilidad

Persistir los mensajes salientes (eventos de dominio a despachar hacia SAP, FCM, email, SMS, webhooks)
en la MISMA transacción que el cambio de negocio que los produjo, y despacharlos después de forma
asíncrona, idempotente y con reintentos. Es la pieza que hace cumplir el principio 4 de la arquitectura
y el ADR 002: nada de I/O externo HTTP síncrono dentro del request del usuario.

- `RecordDomainEvents`: puente que toma los eventos liberados por `AggregateRoot::releaseEvents()` y los
  encola (`append`) en el store, dentro de la transacción de negocio.
- `RelayOutboxBatch`: worker que toma un lote bloqueado y despacha cada mensaje, con backoff exponencial
  y dead letter al agotar los intentos.
- `php artisan logistics:outbox:relay --limit=100`: corre un lote (scheduler o a mano).

Idempotencia por `event_id` (índice único): reencolar el mismo evento es inocuo. Concurrencia por
`FOR UPDATE SKIP LOCKED` + lease sobre `available_at`: dos workers nunca toman el mismo mensaje.

## No es responsable de

Enviar de verdad a cada canal. El contrato `MessageDispatcher` lo implementa cada contexto/conector
(SAP, FCM, ...). Acá solo va `NullMessageDispatcher` (log) como binding por defecto, reemplazable.

## Estado

| Pieza | Estado |
| --- | --- |
| `OutboxMessage`, `OutboxStatus`, `OutboxMessageId` (dominio puro) | Implementado |
| `OutboxStore` + `EloquentOutboxStore` (insertOrIgnore, SKIP LOCKED, lease) | Implementado |
| `RecordDomainEvents`, `RelayOutboxBatch` (backoff 1/5/30/120/360 min, 6 intentos) | Implementado |
| `MessageDispatcher` + `NullMessageDispatcher` | Implementado |
| Migración `outbox_messages`, comando `logistics:outbox:relay` | Implementado |
| Dispatchers reales por canal (SAP, FCM, email, SMS, webhooks) | Pendiente (otros contextos) |

## Uso

```php
// Dentro del handler de un comando, en la misma transacción que el repositorio:
$events = $aggregate->releaseEvents();
app(RecordDomainEvents::class)->record($events, 'network.node');

// En el worker/scheduler:
$result = app(RelayOutboxBatch::class)->handle(limit: 100);
```
