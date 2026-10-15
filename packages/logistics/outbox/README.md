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

## Operación del relay (producción)

El relay (`logistics:outbox:relay` → `RelayOutboxBatch`) es el worker que despacha los mensajes. Está
diseñado para correr en paralelo (varias réplicas) sin duplicar despachos y sin perder progreso:

1. **Toma con bloqueo — `FOR UPDATE SKIP LOCKED`.** `EloquentOutboxStore::pullBatch()` selecciona, dentro
   de una transacción, hasta `limit` mensajes despachables (`pending`/`failed` con `available_at <= now`),
   ordenados por `available_at`, con `lock('for update skip locked')`. Dos workers corriendo a la vez
   nunca toman la misma fila: el que llega segundo salta las filas ya bloqueadas en lugar de esperar.
2. **Lease sobre `available_at`.** Al tomar el lote, el store corre `available_at` hacia el futuro
   (`OUTBOX_LEASE_SECONDS`, 60s por defecto) sobre esas filas. Si el worker muere a mitad del despacho,
   el mensaje no queda tomado para siempre: vuelve a ser elegible recién pasado el lease, evitando que
   otro worker lo reintente de inmediato y lo duplique.
3. **Idempotencia por `event_id`.** `append()` usa `insertOrIgnore` contra el índice único de `event_id`,
   así que reencolar el mismo evento es inocuo. Como el outbox entrega *at-least-once*, cada
   `MessageDispatcher` de canal debe ser idempotente.
4. **Backoff exponencial al fallar.** Si el despacho lanza, `RelayOutboxBatch` suma un intento y reprograma
   `available_at` según `relay.backoff_minutes` (por defecto `1→1min, 2→5min, 3→30min, 4→2h, 5→6h`),
   guardando el `last_error`. El estado se persiste mensaje por mensaje: una falla a mitad del lote no
   pierde lo ya despachado.
5. **Dead-letter tras N intentos.** Al alcanzar `relay.max_attempts` (6 por defecto) sin éxito, el mensaje
   pasa a `dead_lettered` (estado terminal): deja de reintentarse y espera intervención manual.

### Ejecución

Correr un lote a mano o desde el scheduler (p. ej. cada minuto). Es seguro solaparlo (SKIP LOCKED + lease):

```bash
php artisan logistics:outbox:relay --limit=100   # --limit por defecto: config logistics-outbox.relay.limit
```

El comando imprime el resumen de la corrida (`pulled / dispatched / failed / dead-lettered`) para el log
del scheduler y el monitoreo.

### Monitoreo e intervención

- **Panel Filament (solo lectura).** `OutboxMessageResource` lista los mensajes en sus cuatro estados con
  `event_name`, `aggregate`, `attempts`, `available_at` y el `last_error`; filtra por estado y por tipo de
  agregado, y muestra el payload en el detalle. El contador de navegación suma la cola viva
  (`pending` + `failed`) más los `dead_lettered`: rojo si hay dead-letters (alerta), ámbar si solo hay cola.
- **Reintentar.** Acción por fila y masiva: despacha `RetryMessageCommand`, que reencola el mensaje
  (`pending`, `attempts = 0`, `available_at = now`) para que el relay lo retome. Es la única forma de sacar
  un mensaje de dead-letter. No se ofrece sobre mensajes ya despachados.
- **Mover a dead-letter.** Acción por fila sobre mensajes en cola: despacha `DiscardMessageCommand` con un
  motivo, para retirar un evento que nunca va a poder despacharse sin esperar a agotar los reintentos.
- Toda escritura del panel pasa por el CommandBus (Presentation → Application); el detalle Eloquent solo se
  usa para leer/filtrar.

## Estado

| Pieza | Estado |
| --- | --- |
| `OutboxMessage`, `OutboxStatus`, `OutboxMessageId` (dominio puro) | Implementado |
| `OutboxStore` + `EloquentOutboxStore` (insertOrIgnore, SKIP LOCKED, lease) | Implementado |
| `RecordDomainEvents`, `RelayOutboxBatch` (backoff 1/5/30/120/360 min, 6 intentos) | Implementado |
| `MessageDispatcher` + `NullMessageDispatcher` | Implementado |
| `RoutingMessageDispatcher` (ruteo por `event_name` → adapter, config `routes`) | Implementado |
| `OutboxReader` + `EloquentOutboxReader` (lado query: find/list/count/countByStatus) | Implementado |
| CQRS operativo: `RetryMessage`/`DiscardMessage` + queries `ListOutboxMessages`/`GetOutboxMessage`/`GetOutboxStats` (ReadModels) | Implementado |
| Config `config/logistics-outbox.php` (limit, lease, max_attempts, backoff, routes) | Implementado |
| Factory + `OutboxSeeder` (demo en los 4 estados) | Implementado |
| HTTP: controllers por caso de uso + fragmento `routes/api/outbox.php` (puertas app/integrations) | Implementado |
| Fragmento `openapi/paths/outbox.yaml` | Implementado |
| Migración `outbox_messages`, comando `logistics:outbox:relay` | Implementado |
| Filament: panel de mensajes (4 estados, filtros estado/tipo, payload, reintentar + mover a dead-letter, badge pendientes/dead-letter) | Implementado |
| Dispatchers reales por canal (SAP, FCM, email, SMS, webhooks) | Pendiente (otros contextos, enganchan vía config `routes`) |

## Uso

```php
// Dentro del handler de un comando, en la misma transacción que el repositorio:
$events = $aggregate->releaseEvents();
app(RecordDomainEvents::class)->record($events, 'network.node');

// En el worker/scheduler:
$result = app(RelayOutboxBatch::class)->handle(limit: 100);
```
