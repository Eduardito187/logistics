# Observabilidad — logistics

Guía base de observabilidad de la plataforma: logs estructurados, métricas a exponer, trazabilidad
de la cadena asíncrona y cómo enchufar Sentry. Es la referencia de operación; la configuración vive
en `config/logging.php` y `config/sentry.php`, y las envs en `.env.example`.

Principio rector: la plataforma procesa eventos de forma asíncrona por outbox (ADR 002, ADR 008) y
corre sobre colas (Valkey/Redis). Por eso la observabilidad se centra en **el flujo de eventos y las
colas**, no solo en el request HTTP.

## 1. Logs estructurados (JSON)

El canal `json` de `config/logging.php` emite **una línea JSON por evento**, pensado para que el
agregador (Loki, CloudWatch Logs, ELK) lo ingiera sin parsear texto libre. Cada registro lleva
`message`, `level`, `channel`, `datetime`, `context` y `extra`.

- En desarrollo se mantiene `LOG_STACK=single` (texto legible).
- En producción se recomienda `LOG_STACK=json` y escribir a `php://stderr` (`LOG_JSON_STREAM`), para
  que el runtime/contenedor recoja el stream y lo mande al agregador.

### Correlación (`request_id`)

El canal `json` adjunta un identificador de correlación mediante un **processor configurable**
(`LOG_REQUEST_ID_PROCESSOR`):

- **Default — `Monolog\Processor\UidProcessor`.** Adjunta un id único por proceso bajo `extra.uid`.
  Sirve para agrupar todas las líneas de un mismo request o worker.
- **Propagación de extremo a extremo.** Para arrastrar un mismo id a través de toda la cadena
  asíncrona (request → job → notificación) se apunta la env a un **processor propio** que tome el
  header `X-Request-Id` entrante (o genere un UUID v7 si no viene) y lo exponga como `request_id`.
  Esa clase la aporta la capa de aplicación (`app/` o un package); esta config solo la referencia por
  nombre, para no acoplar la config a una implementación concreta.

Regla de oro: **todo log de un job o de un listener de evento incluye en su `context` el
`request_id`/`event_id` que originó el trabajo.** Es lo que permite reconstruir la traza completa
(sección 3).

## 2. Métricas a exponer

Métricas que el equipo de operación necesita en el dashboard. Se exponen por un endpoint de métricas
(formato Prometheus u OpenMetrics) o se derivan del agregador de logs. Aquí se fija **qué** medir y con
qué dimensiones; el **cómo** (exporter concreto) se decide al instrumentar.

### Negocio / operación

| Métrica | Tipo | Dimensiones | Para qué |
| --- | --- | --- | --- |
| Entregas | contador | estado (entregada/fallida), zona, corredor, día | Volumen y tasa de éxito de entrega |
| Escaneos | contador | tipo de evento, nodo, dispositivo | Pulso del flujo de eventos (ADR 008, eventos inmutables) |

### Plataforma / API

| Métrica | Tipo | Dimensiones | Para qué |
| --- | --- | --- | --- |
| Latencia API | histograma | puerta (pública/servicio/admin), ruta, método, código | p50/p95/p99 de respuesta; SLO de la API |
| Profundidad de colas | gauge | nombre de cola | Backlog de trabajo asíncrono; alerta si crece sin bajar |

### Outbox (ADR 002 / ADR 008)

El outbox es el corazón de la integración asíncrona; su salud se vigila aparte:

| Métrica | Tipo | Dimensiones | Alerta |
| --- | --- | --- | --- |
| Outbox pendiente | gauge | destino (SAP/FCM/email/SMS/webhook) | Crece y no drena ⇒ relay caído o destino lento |
| Outbox fallido | contador | destino, tipo de error | Pico ⇒ problema en un destino externo |
| Outbox dead-letter | gauge | destino | **> 0 es incidente**: eventos que agotaron reintentos |
| Edad del pendiente más viejo | gauge | destino | Latencia real de integración (p. ej. retraso de SAP) |

### Ruteo y planificación (ADR 009 / ADR 012)

| Métrica | Tipo | Dimensiones | Para qué |
| --- | --- | --- | --- |
| Duración del auto-planner | histograma | modo (`optimization`/`slots`), nº de pedidos | Vigilar que el job escale a cientos de pedidos sin timeout |
| Resultado del auto-planner | contador | modo, resultado (ok/fallo/reintento) | Idempotencia y fiabilidad del job |
| Llamadas OSRM | contador + histograma | endpoint (`/table`, `/route`), resultado, latencia | Dependencia externa crítica del VRP; detectar lentitud/errores |
| Tamaño de matriz OSRM | histograma | — | Correlacionar latencia del planner con el tamaño del problema |

Nota: las llamadas a OSRM y a los destinos externos **no ocurren dentro de un request de usuario**
(ADR 002), así que su latencia se mide en los jobs, no en la latencia de la API.

## 3. Trazabilidad de la cadena asíncrona

El camino de un cambio no termina en el request; sigue de forma asíncrona:

```
request/evento de dominio
      │  (misma transacción)
      ▼
   outbox  ──────────────▶  relay (job)  ──────────────▶  notificación / SAP / webhook
  event_id                reintentos +                     push FCM, web push,
  (UUID v7)               idempotencia                      email, SMS (ADR 006/010)
```

Para poder seguir **un** evento de punta a punta:

1. Al nacer el evento se fija un id de correlación (el `event_id` UUID v7 del outbox, y/o el
   `request_id` del request que lo originó).
2. Ese id viaja en el payload del evento y se re-emite en el `context` de cada log: al encolar, al
   ejecutar el relay, al despachar la notificación y al confirmar/fallar.
3. En el agregador se filtra por ese id y aparece la traza completa (request → outbox → relay →
   destino), con sus latencias y reintentos.

Cuando se encienda el **tracing de performance de Sentry** (sección 4), cada eslabón se vuelve un span
de la misma transacción, lo que da la traza de forma visual además del filtro por log.

## 4. Cómo enchufar Sentry

Sentry queda **pre-configurado pero no instalado**. `config/sentry.php` ya fija las claves y las envs
(con los nombres que usa `sentry/sentry-laravel`), de modo que encenderlo no requiere decidir nada,
solo instalar y poner el DSN. Mientras el paquete no esté, el archivo es inerte y no cambia nada.

Pasos para habilitarlo:

1. **Instalar el SDK:**

   ```bash
   docker compose exec -T app composer require sentry/sentry-laravel
   ```

2. **Definir el DSN** del proyecto en el entorno (no versionar el valor real):

   ```dotenv
   SENTRY_LARAVEL_DSN=https://<clave>@<org>.ingest.sentry.io/<proyecto>
   SENTRY_RELEASE=         # inyectar el SHA del commit o el tag desde CI
   SENTRY_ENVIRONMENT=production
   ```

   Con el DSN vacío el SDK queda desactivado: seguro para local y staging.

3. **(Opcional) Regenerar la config** con la versión instalada y revisar el diff para no perder los
   valores ya acordados:

   ```bash
   docker compose exec -T app php artisan sentry:publish --dsn=...
   ```

4. **Captura de errores:** al instalar el paquete, el handler de excepciones de Laravel 13
   (`bootstrap/app.php`, `withExceptions`) reporta a Sentry automáticamente. Las excepciones que son
   ruido esperado (validación, 404, auth) se listan en `ignore_exceptions` de `config/sentry.php`.

5. **Tracing de performance (opcional):** fijar `SENTRY_TRACES_SAMPLE_RATE` (empezar bajo, p. ej.
   `0.1`, para no inflar la cuota). Da la latencia de la API por ruta y los spans de colas, SQL,
   Redis y llamadas HTTP de salida (OSRM), alineado con las métricas de la sección 2.

Qué envía Sentry y qué no:

- `send_default_pii=false` por defecto: no manda IP, cookies ni datos de usuario sin decisión
  explícita.
- `sample_rate=1.0`: captura el 100% de los errores (bajar solo por cuota).
- Logs y métricas siguen yendo al agregador (secciones 1–2); Sentry es para **errores y performance**,
  no reemplaza al agregador de logs.

## Referencias

- ADR 002 — I/O externo fuera del request, vía outbox.
- ADR 008 — DDD/CQRS/UUID v7/outbox (eventos inmutables, `event_id`).
- ADR 009 — ruteo OSRM + VRP (llamadas `/table` y `/route`).
- ADR 012 — auto-planner asíncrono en dos modos.
- `config/logging.php`, `config/sentry.php`, `.env.example`.
