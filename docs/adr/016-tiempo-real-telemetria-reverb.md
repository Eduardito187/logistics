# ADR 016 — Seguimiento en tiempo real (telemetría) con ingesta por lotes + WebSockets (Reverb)

Fecha: 2026-10-14. Estado: aceptado. Relacionado: contexto `telemetry`, [[routing-geo-rule]], ADR 013 (todo OSM self-host).

## Contexto

Hay que seguir en vivo chofer + vehículo + entregas: torre de control con la flota moviéndose, y el
cliente viendo su pedido acercarse. Objetivo de escala: ~100 camiones hoy → nacional (cientos/miles) a
largo plazo, con alta concurrencia de datos y sostenible en el tiempo. Pregunta central: **¿polling o
sockets?**

Estado actual: la **ingesta ya existe** (contexto `telemetry`: `RecordVehiclePings` por lotes →
`telemetry_pings`/`telemetry_vehicle_positions`/`telemetry_trips`/`telemetry_geofences`/
`telemetry_alerts`, PostGIS). Lo que **falta es el fan-out en vivo**: hoy `BROADCAST_CONNECTION=log`, así
que la torre/tracking son carga estática o polling.

## Decisión

Son **dos flujos con tecnologías distintas**:

1. **Dispositivo → servidor (ingesta GPS)**: **HTTP por lotes** (ya implementado). El celular bufferea y
   envía batches cada ~10–30s / N puntos, Sanctum, idempotente. NO sockets desde el móvil (batería,
   reconexión, redes malas). Se mantiene.

2. **Servidor → espectadores (torre/cliente)**: **WebSockets (push) con Laravel Reverb** (servidor WS
   oficial de Laravel, **self-host y open-source** — coherente con ADR 013, sin Google/pago) + Laravel
   Echo en el SPA. Polling NO escala para muchos espectadores; se deja solo como **fallback** (con SSE como
   alternativa liviana de una vía).

### Flujo

```
Flutter (chofer) ──batch HTTP──► [API 202] ──encola──► Valkey queue
                                                  worker(s) (escalan):
                                                   ├─ última posición → VALKEY (hot)
                                                   ├─ crudo → PostGIS particionado / TimescaleDB (histórico)
                                                   ├─ geocerca/llegada/ETA → evento dominio → OUTBOX
                                                   └─ delta ──► REVERB ──► Echo (navegadores)
                                                       canales: fleet / vehicle.{id} / shipment.{id}
```

- **Coalescing/throttle**: NO se emite cada ping; se emite la última posición por vehículo a ritmo tope
  (~1 cada 2–3s). Corta el tráfico de fan-out.
- **Caliente vs frío**: Valkey = estado vivo (última posición, estado de geocerca, presencia). PostGIS/
  **TimescaleDB** = histórico (particionado por día, continuous aggregates, retención: downsamplear lo
  viejo, archivar a S3). El histórico no debe crecer sin límite en la tabla caliente.
- **Desacople** total vía colas + outbox (ya existen): la ingesta nunca escribe síncrono ni bloquea.
- **Canales y auth**: `fleet` (supervisores, gated por rol), `vehicle.{id}` (operación), `shipment.{id}`
  (cliente, con token de corta vida del tracking público — sin PII, coherente con el guard de PublicTracking).

## Escala y sostenibilidad

- **Horizontal**: API y workers stateless se replican; **Reverb multi-nodo con Valkey pub/sub**; nginx
  balancea. Cada contexto es extraíble a microservicio (meta del proyecto): telemetry/tracking pueden
  volverse servicios propios con su ingesta + broadcast.
- **Backpressure**: rate-limit por dispositivo/vehículo, batch, descartar/agregar puntos viejos.
- **Números**: 100 camiones @1 ping/10s ≈ 10/s (por lotes, muchos menos requests); 1000 ≈ 100/s. Un nodo
  Reverb sostiene miles de conexiones; lo que crece es el histórico → particionado + retención + archivo.
- **Camino de crecimiento**: masivo (decenas de miles de dispositivos) → broker **MQTT** (EMQX/Mosquitto)
  para ingesta puenteada a la cola, **TimescaleDB** para serie temporal, **Centrifugo** para fan-out
  masivo, **NATS/Kafka** como bus de eventos durable (el outbox puede montarse encima). Todo open-source.

## Plan de implementación (por fases, aditivo)

1. **Fan-out base**: `composer require laravel/reverb` + `npm i laravel-echo pusher-js`;
   `BROADCAST_CONNECTION=reverb`; config broadcasting + bootstrap Echo en `resources/js/app.js` (o un
   módulo), proceso Reverb en compose/supervisor. Evento `VehiclePositionUpdated` (ShouldBroadcast) en
   canal `fleet`.
2. **Hot state + coalescing**: última posición por vehículo en Valkey; throttle de broadcast (1/2–3s/veh);
   el worker de pings publica el delta.
3. **Torre de control en vivo** (SPA): Echo suscribe `fleet`; los marcadores se mueven sin recargar;
   presencia de choferes en línea.
4. **Tracking del cliente en vivo**: canal `shipment.{id}` con token; la página pública actualiza posición
   + ETA sin recargar; geocerca de llegada → "tu pedido está llegando".
5. **Histórico sostenible**: partición por día de `telemetry_pings`/positions (o TimescaleDB hypertable),
   continuous aggregates + política de retención + archivo a S3.
6. **Fallbacks**: endpoint SSE y polling para clientes sin WS.

## Consecuencias

- Nueva dependencia de un proceso Reverb (self-host) y de Valkey como pub/sub — ya hay Valkey. Un servicio
  más que operar, a cambio de tiempo real real y escalable sin polling.
- La ingesta no cambia (ya es por lotes). El contrato con la app Flutter para posiciones se mantiene.
