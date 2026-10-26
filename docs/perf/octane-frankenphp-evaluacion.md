# Evaluación: Laravel Octane / FrankenPHP — logistics

Estado: **propuesta, NO adoptado**. Este documento evalúa reemplazar el modelo actual
PHP-FPM clásico (un proceso por request, estado limpio en cada request) por un runtime de
aplicación persistente (Octane sobre Swoole/RoadRunner, o FrankenPHP con su worker mode).
No agrega ninguna dependencia ni cambia la imagen; es el insumo para decidir.

## 1. Contexto y punto de partida

Hoy la app corre en **PHP-FPM** (ver `.docker/php/`): cada request arranca el framework
desde cero y OPcache + preload recortan el coste de parseo/compilación. Ya está afinado:
`opcache.validate_timestamps=0`, JIT tracing, config/route/view/event cacheados en el boot
(`OPTIMIZE_ON_BOOT`), `filament:optimize`, y pool FPM parametrizable por entorno.

La meta del proyecto (CLAUDE.md, memoria del dueño) es **respuestas rápidas sobre millones
de registros con procesos transaccionales en simultáneo**. El cuello de botella dominante
en una app así suele ser **I/O (PostgreSQL/PostGIS, Valkey)** y el **arranque del framework
por request**, no el runtime PHP en sí.

## 2. Qué aportaría un runtime persistente

Octane/FrankenPHP arrancan el framework **una vez** y lo mantienen vivo entre requests:

- **Latencia**: se elimina el bootstrap por request (service providers, binding del
  contenedor, carga de config). En apps Laravel medianas/pesadas esto recorta típicamente
  **30–50 % de la latencia base** del tiempo de framework (no del tiempo de DB).
- **Concurrencia / throughput**: con Swoole o el worker mode de FrankenPHP se atienden más
  requests por core con menos memoria por request (el framework no se reconstruye). Para
  endpoints de lectura cacheados (ReadModels), el salto es grande.
- **FrankenPHP** suma: servidor HTTP propio (Caddy, HTTP/2 y HTTP/3, TLS automático), worker
  mode, y "early hints"; puede reemplazar a nginx+FPM por un solo binario.
- **Capacidades extra** de Octane: `concurrent tasks`, `ticks`, caché en memoria del worker.

## 3. Costo y riesgo

El cambio de modelo de ejecución convierte **fugas de estado entre requests** en bugs de
producción. Puntos a auditar en este repo antes de siquiera probarlo:

- **Estado global / estático**: cualquier `static` mutable, singleton con estado por request,
  o propiedad de servicio que acumule datos, se **filtra al siguiente request** (posible fuga
  de datos entre clientes y de memoria). Hay que barrer `packages/logistics/*` y `app/`.
- **Singletons en el contenedor**: servicios registrados como `singleton` que guarden request,
  usuario autenticado, tenant o conexión con estado deben revisarse. Laravel + Octane resetea
  algunos bindings (`config`, `request`, `auth`, DB, ...) vía `flush`, pero los **bindings
  propios** de cada contexto no se resetean solos.
- **CQRS buses**: `CommandBus`/`QueryBus` y sus handlers (`logistics/shared`) no deben
  retener estado entre despachos. Verificar que los handlers sean sin estado o se resuelvan
  frescos por request.
- **Outbox / workers**: ya corren como procesos de larga vida (`queue:work`), así que ese
  patrón (reciclar por `--max-time`/`--max-jobs`) ya está probado; Octane aplica la misma
  disciplina al lado web.
- **Memory leaks**: cualquier fuga que hoy se "limpia sola" al morir el proceso FPM, con un
  runtime persistente **crece** hasta reciclar el worker. Necesita `--max-requests` y
  monitoreo de RSS.
- **Librerías no reentrantes**: GD, clientes SAP/FCM, estado de Carbon/locale, `setlocale`,
  `date_default_timezone_set` por request, etc.
- **Filament / Livewire**: validar que el admin (muchos singletons de panel) no arrastre
  estado entre requests bajo Octane.
- **Cambio de imagen/operación**: nueva extensión (Swoole/openswoole) o binario (FrankenPHP),
  nuevo modelo de supervisión, health checks y señales de recarga; el deploy debe recargar
  workers web igual que hoy recarga los de cola.

## 4. Recomendación

**No adoptar ahora. Mantener PHP-FPM + OPcache/JIT + caches de arranque** (ya afinado), que
cubre la escala actual con riesgo operativo mínimo y aislamiento de estado por request —
coherente con "aislar errores" de la arquitectura objetivo.

Promover Octane/FrankenPHP a **spike cuando un load test (Fase 4) demuestre** que el
bootstrap del framework (no la DB) es el cuello de botella de la latencia p95/p99. Si se hace
el spike, preferir **FrankenPHP en worker mode** sobre Swoole: menos cambios de extensión,
servidor HTTP moderno (HTTP/3), y puede convivir con la imagen Alpine actual con menos
fricción que el stack Swoole. Mantener FPM como fallback durante la transición.

## 5. Plan de adopción por fases (si se aprueba el spike)

1. **Auditoría de estado** (bloqueante): barrer `static` mutables, `singleton` con estado y
   los handlers de CQRS en `packages/logistics/*` y `app/`. Salida: lista de puntos a corregir.
2. **Entorno aislado**: imagen `prod-octane` separada (sin tocar la actual), FrankenPHP worker
   mode detrás del mismo nginx o como edge. Nunca sobre la imagen de prod vigente.
3. **Pruebas de fuga**: load test sostenido observando RSS por worker y comparando respuestas
   entre requests concurrentes de distintos usuarios (detectar estado filtrado).
4. **Benchmark comparativo**: p50/p95/p99 y throughput vs. FPM afinado, en endpoints reales
   (health, tracking público, listados de ReadModel, y una escritura por CommandBus).
5. **Rollout gradual**: canary de un porcentaje del tráfico web, con FPM como fallback y plan
   de rollback de un paso (volver a la imagen FPM).
6. **Operación**: `--max-requests`/reciclado, alertas de RSS, y recarga de workers web en el
   deploy equivalente al `queue:restart` actual.
