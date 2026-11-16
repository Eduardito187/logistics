---
name: realtime-socket-topic
description: >-
  Agregar o extender una señal de tiempo real por WebSocket (Soketi/protocolo Pusher) de punta a punta:
  un tema/evento nuevo (picking, store, loading, operations, tracking, fleet, planning…) o una dimensión de
  canal nueva. Cubre canales por dimensión, el mapa rol→canal, el observador del outbox, el registro, la
  config de la app, OpenAPI y el test. Usar cuando el dueño/app pida "avisar por socket X", "tiempo real de
  Y", "que el chofer/preparador/tienda reciba Z en vivo", o migrar un refresco por tiempo a sockets.
---

# Agregar una señal de tiempo real (Dismac logistics)

Arquitectura completa en la memoria `realtime-sockets-architecture`. Transporte: Soketi (protocolo Pusher),
mismo puerto que la web, CERO CDN. **Las señales son LIVIANAS**: solo ids/estados/contadores; el contenido
(mensajes, PII) NUNCA viaja por el socket — la app re-pide por la API autenticada. Regla del dueño:
`owner-runs-full-suite` (NO correr la suite completa; gates acotados + guardar en memoria).

## 0. Decidir antes de tocar código
- **Tema**: ¿existe (`RealtimeChannels::TOPIC_*`) o es nuevo? Temas vivos: fleet, planning, tracking, picking,
  store, operations, loading.
- **Audiencia → dimensión**: office=`.all`, planificador/preparador=`.city.<depto>`, tienda=`.node.<uuid>`,
  chofer=`.driver.<uuid>`, transportista=`.carrier.<uuid>`. Cada rol recibe UN canal por tema; el backend emite
  a TODOS los canales que cubren la entidad y la app filtra por el id del payload (NUNCA arma nombres).
- **Hechos que lo disparan**: buscar los `event_name` REALES del outbox (`packages/logistics/outbox/config/logistics-outbox.php`
  + los eventos de dominio). Si no existe un evento para el disparo que pide el dueño, puede faltar
  (ej.: no hay evento por-bulto de preparación → hubo que agregar `execution.unit_prepared`).

## 1. Fundación de canales (coordinador, NUNCA un agente de package)
Si hay tema o dimensión nuevos, el COORDINADOR los agrega en los archivos COMPARTIDOS primero (para que los
agentes de emisión los usen y no choquen):
- `packages/logistics/shared/src/Presentation/Realtime/RealtimeChannels.php`: `TOPIC_X`, y builders/`forEntity`
  (ya tiene all/city/node/driver/carrier/vehicle; `forEntity` deduplica y valida uuid/código).
- `app/Support/Realtime/UserRealtimeChannels.php`: sumar el tema a `TOPIC_MATRIX` (qué roles, qué dimensión).
- `routes/channels.php`: sumar el tema al array `$realtimeTopics` (el loop autoriza todas las dimensiones por
  `UserRealtimeChannels::mayJoin`).
- Actualizar `tests/Feature/StaffInboxRealtimeTest.php` (mapa de canales por rol; `toBe` exige orden de claves =
  orden de `TOPIC_MATRIX`).
Pint+PHPStan de esos 3 archivos ANTES de lanzar emisión.

## 2. Emisión (package dueño del evento, o `app/` si es cross-context)
Molde: `app/Outbox/InboxRealtimeObserver.php` + `app/Events/InboxUpdated.php`, o el `route.updated` de
execution. Tres piezas:
- **Evento** `ShouldBroadcast` (NUNCA `Now`): `broadcastAs()` = nombre estable, `$tries=1`, cola
  `config('observability.metrics.queues.notifications','notifications')`, `broadcastOn()` = `PrivateChannel`
  de los nombres de `RealtimeChannels::forEntity(TOPIC, $dims)`, `broadcastWith()` = payload primitivo.
- **Observador** `*RealtimeObserver implements OutboxObserver`: `const EVENTS` (event_names), filtra +
  `StaffInbox::realtimeEnabled()`, anti-ráfaga `Cache::add` por clave (ventana ~2 s; 5 s dashboard), encola el
  job con `->delay()`. Nunca lanza (try/catch + report). Con broadcasting null/log: no encola ni consulta.
- **Job** que resuelve las dimensiones (lectura cross-context read-only por query builder, barata, tolerante;
  valida UUID antes de comparar contra columnas uuid → evita 22P02) y difunde el evento.
Observador `final readonly` sin constructor = autoresoluble (no necesita binding).

## 3. Registro e integración (coordinador)
- Registrar el observador en `App\Providers\AppServiceProvider` con el helper `addOutboxObserver` (hay un
  `observe*Realtime()` por frente; agregar el import + el método + la llamada en `boot()`).
- OpenAPI: schema `<Nombre>Event` (nullable con union `type: [x,'null']`), sumarlo al `x-events` de
  `BroadcastConfig.channels`. Integrar con script Python (`once()` + chequeo de refs; pyyaml valida). NO
  reserializar el YAML entero.
- es.json: normalmente NADA (las señales no llevan texto visible).

## 4. GOTCHAS (aprendidos a golpes)
- **Dep nullable del contenedor** (memoria `container-nullable-dependency-gotcha`): si el job/handler se
  resuelve por el contenedor y agregás una dependencia, hacela REQUERIDA (no `?Tipo=null`, que recibe null).
- **UUIDv7 en tests**: dos ids generados en el mismo ms comparten el prefijo de tiempo; para un `code`/`plate`
  único en un seed usar `substr($id, -8)` (random), NO `substr($id, 0, 8)` (prefijo de tiempo → colisión).
- **Catch-all del outbox**: hay `'execution.' => DomainEventNotificationDispatcher` por prefijo; un evento
  `execution.*` nuevo cae ahí pero es no-op si no está en su `EVENT_MAP` cerrado (solo se persiste; los
  observadores de realtime lo leen igual). No hace falta rutearlo al nulo.
- **Agregar una clave a un ReadModel** (ej. `delivery_evidence.with_shortage`) rompe tests que afirman el
  shape EXACTO con `toBe([...])`/`assertJsonPath(..., [array])`: buscarlos (`grep` del shape viejo) y
  actualizarlos; una cadena `->and()->toBe()` aborta en el primer fallo y esconde los siguientes.

## 5. Test + gates acotados
Test Pest: el hecho encola la señal a los canales/payload correctos; sin transporte no emite; anti-ráfaga
colapsa la ráfaga; no lanza con payload incompleto. Fechas `+00:00`. Gates: `pint <dirs>`, `phpstan analyse`
nivel 8, y SOLO los tests de lo tocado (tracking/execution/telemetry/host + `StaffInboxRealtimeTest` +
`CrossContextRealtimeSignalsTest`). La suite completa la corre el dueño.

## 6. Avisar a la app
Al cerrar, mandar a la sesión de la app (`SendMessage`) el contrato: tema(s), evento(s), payload exacto,
canales por dimensión, y que todo sale de `broadcasting/config.channels` (no arma nombres). Si el openapi dejó
de moverse, un único aviso "SPEC ESTABLE" para que haga un solo regen del cliente.
