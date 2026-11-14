# Load test — 2.000 usuarios concurrentes (Ola 5)

Pruebas de carga con [k6](https://k6.io) para encontrar el cuello antes de producción y comparar
**PHP-FPM vs Octane** (roadmap `docs/roadmap-alta-disponibilidad-escala.md`, Ola 5). Objetivo: 2.000
clientes concurrentes (≈150-400 RPS sostenidos, con margen para picos de 800-1.000 RPS).

> **Nunca contra producción.** Los comandos `loadtest:prepare` y `loadtest:cleanup` se niegan a correr
> con `APP_ENV=production`, y los scripts escriben (POD, GPS, llegadas) sobre un pool de datos dedicado.
> Correr solo en dev, staging o un entorno de carga.

Los SLOs que hacen de criterio de aprobación (ver `docs/slo.md`) están en `lib/slo.js`:

| SLO | Threshold en k6 |
| --- | --- |
| Disponibilidad 99,9 % | `http_req_failed < 0,1 %` (solo 5xx y errores de red) |
| Lectura app p95 < 250 ms | `http_req_duration{kind:read}` |
| Escritura / POD p95 < 1 s | `http_req_duration{kind:write}` |
| Rastreo público p95 < 500 ms | `http_req_duration{kind:tracking}` |
| Admin p95 < 2,5 s | `http_req_duration{kind:admin}` (muestra parcial, ver «No cubierto») |

Igual que el SLI del servidor, **un 4xx no cuenta contra la disponibilidad** (es un error del cliente):
`lib/client.js` marca como fallo solo 5xx y errores de red. Los 4xx hacen fallar su check y se cuentan
aparte en `lt_client_errors`, porque en un load test casi siempre delatan un problema del script o del pool.

## Qué hay en esta carpeta

| Archivo | Para qué |
| --- | --- |
| `smoke.js` | 1-5 VUs, una pasada. Verifica que el pool y cada flujo funcionan. |
| `baseline.js` | Tasa fija (~200 RPS, modelo abierto). La corrida comparable entre versiones. |
| `peak-2k.js` | Rampa a 2.000 VUs con think time 5-15 s, meseta y bajada. El objetivo de la ola. |
| `spike.js` | Salto brusco de la base al pico y vuelta: mide la recuperación. |
| `soak.js` | 1-2 h a carga media: fugas de memoria y de conexiones (clave para Octane). |
| `lib/` | Cliente común, flujos, pool, SLOs, resumen. Solo módulos built-in de k6 (cero CDN). |
| `data/` | Pool exportado (`pool.json`, con tokens) y resultados. **Gitignored.** |
| `REPORT-TEMPLATE.md` | Plantilla del reporte de cada corrida. |

### Qué simula cada persona

| Persona | Peso | Requests |
| --- | --- | --- |
| Chofer | 60 % | `GET /app/vehicles/{vehicleId}/route`, `POST /app/telemetry/pings`, `GET /app/deliveries/{shipmentId}`, `GET /app/vehicles/{vehicleId}/deliveries`, `POST /app/deliveries/{shipmentId}/arrived`, `POST /app/deliveries/{shipmentId}/complete` (POD) |
| Cliente | 30 % | `GET /t/{token}` (la página HTML pública, sin credenciales: 60 % de las consultas), `GET /api/v1/channels/tracking/{token}` (JSON de los canales, con `X-Channel-Key` / `X-Channel-Secret` e `If-None-Match`), `GET /api/v1/channels/tracking/{token}/units` |
| Operador | 10 % | `GET /app/shipments`, `GET /app/shipments/summary`, `GET /app/telemetry/fleet/live`, `GET /app/kpis/daily`, `GET /app/tracking/shipments/{shipmentId}`, `GET /admin/login` (5 %) |
| Login | goteo | `POST /app/auth/login` → `GET /app/auth/me` → `POST /app/auth/logout` (6/min) |

Cada request lleva los tags `kind` (read / write / tracking / admin / auth), `ep` (endpoint) y `persona`, así el
resumen separa el p95 por tipo y por endpoint. Las cabeceras de la puerta app (`Authorization: Bearer`,
`X-App-Version`, `X-App-Platform`, `X-App-Build`, `X-Device-Model`) y una `Idempotency-Key` nueva por
escritura las pone `lib/client.js`.

**No cubierto:** el panel de administración Filament con sesión (tablas, widgets, Livewire). k6 no
ejecuta el JavaScript del panel, así que del admin solo se mide la página de acceso (`GET /admin/login`,
sin sesión): sirve para ver el costo de arranque del panel, **no** valida el SLO de 2,5 s del admin. Los
listados de oficina se miden por la API de la puerta app, que comparte las mismas consultas de lectura.
Tampoco se sube la foto del POD (el contrato la admite asíncrona).

## Prerequisitos

1. El stack levantado y sano (`GET /api/v1/health` responde 200), con las migraciones al día y la red
   sembrada (`network_nodes` con al menos un nodo; los camiones del pool cuelgan de uno).
2. Workers de cola y del outbox corriendo: el POD y el GPS publican eventos, y parte de lo que hay que
   mirar es si el outbox drena al ritmo que se llena.
3. k6 **1.3.0** (la versión fijada en el compose). Con Docker no hace falta instalar nada.
4. Un entorno que NO sea producción y que no tenga proveedores reales de SMS/email/SAP conectados (los
   envíos del pool se siembran sin teléfono ni email, pero los eventos igual viajan por el outbox).

## 1. Preparar los datos

```bash
# Ver el plan sin escribir nada (dry-run por defecto):
docker compose exec -T app php artisan loadtest:prepare --drivers=300 --stops=8

# Sembrar y exportar loadtest/data/pool.json:
docker compose exec -T app php artisan loadtest:prepare --drivers=300 --stops=8 --force
```

El comando siembra, por los comandos reales de la aplicación (CommandBus) y de forma **aditiva e
idempotente**: N camiones (`LOADTEST-0001`…), N choferes (`LT-DRV-000001`…) con su cuenta
(`lt-driver-0001@loadtest.dismac.test`), dispositivo y token ya emitido; la ruta abierta de cada camión
con sus paradas; un envío por parada (`LT-{lote}-{chofer}-{parada}`) con seguimiento público y entrega
abierta lista para el POD; operadores (`lt-ops-…`, rol supervisor) con token; cuentas reservadas para el
login (`lt-login-…`); y el canal `LT-loadtest` para el rastreo público.

| Opción | Default | Qué es |
| --- | --- | --- |
| `--drivers=` | 50 | Choferes (cada uno con camión, dispositivo, token y ruta). |
| `--stops=` | 8 | Paradas por chofer. `drivers × stops` = POD "frescos" disponibles. |
| `--operators=` | 50 | Operadores con token para los listados. |
| `--logins=` | 10 | Cuentas del flujo de login (sin token pre-emitido). |
| `--batch=` | hoy (`ymd`) | Etiqueta de 6 caracteres del lote de envíos. Un lote nuevo agrega paradas frescas. |
| `--node=` | primer CD | Código del nodo base de los camiones. |
| `--password=` | `password123` | Contraseña de las cuentas del pool. |
| `--channel-rpm=` | 600000 | Rate limit por minuto del canal `LT-loadtest`. |
| `--output=` | `loadtest/data/pool.json` | Dónde exportar el pool. |
| `--force` | — | Escribir de verdad. |

Cosas a saber:

- **Volver a correrlo** con los mismos parámetros no duplica nada: solo rota los tokens y el secreto del
  canal, y reescribe el `pool.json`. Hay que hacerlo antes de cada sesión de pruebas: los tokens vencen
  según `SANCTUM_TOKEN_EXPIRATION` (720 min por defecto).
- **Las paradas se gastan.** Un POD cierra la parada; repetirlo sobre una parada ya entregada es un no-op
  exitoso en el dominio (se cuenta aparte como `lt_pod_replay`). Para tener paradas frescas otra vez:
  `loadtest:prepare --batch=XXXXXX --force` (agrega un lote) o limpiar y volver a sembrar.
- **Cuánto pool hace falta** (lo exige el rate limit por token, ver más abajo):

  | Escenario | `--drivers` mínimo | Recomendado |
  | --- | --- | --- |
  | `smoke.js` | 5 | 5 |
  | `baseline.js` a 200 RPS | 210 | 300 |
  | `peak-2k.js` (1.200 VUs de chofer) | 180 | 1.200 (un chofer por VU) |
  | `soak.js` (360 VUs de chofer) | 54 | 360 |

  Con menos choferes que VUs de chofer, varios VUs comparten chofer: comparten el cupo del token y
  pueden cerrar la misma parada a la vez. Para la corrida oficial de `peak-2k`, sembrar 1.200 × 8.
  Sembrar tarda (cada parada son varios comandos transaccionales): calcular algunos minutos por cada
  mil paradas.

## 2. Correr un escenario

Con Docker (recomendado; desde la raíz del proyecto):

```bash
K6="docker compose -f docker-compose.yml -f .docker/compose/docker-compose.loadtest.yml run --rm k6"

$K6 run /loadtest/smoke.js
$K6 run -e BASELINE_RPS=200 -e BASELINE_DURATION=10m /loadtest/baseline.js
$K6 run /loadtest/peak-2k.js
$K6 run /loadtest/spike.js
$K6 run -e POD_RATIO=0.02 -e SOAK_DURATION=2h /loadtest/soak.js
```

Con k6 instalado (por ejemplo, desde otra máquina contra staging):

```bash
cd loadtest
k6 run -e BASE_URL=https://staging.ejemplo.test smoke.js
```

Variables útiles (`-e NOMBRE=valor`):

| Variable | Default | Efecto |
| --- | --- | --- |
| `BASE_URL` | `http://nginx` | Stack bajo prueba. |
| `RUN_ID` | vacío | Etiqueta de la corrida en el nombre del resumen (`fpm`, `octane`…). |
| `THINK_MIN` / `THINK_MAX` | 5 / 15 | Think time en segundos. |
| `POD_RATIO` | 0.1 | Parte de las iteraciones de chofer que cierran una parada. |
| `PING_BATCH` | 5 | Lecturas GPS por lote. |
| `MIX_DRIVER` / `MIX_CUSTOMER` / `MIX_OPERATOR` | 0.6 / 0.3 / 0.1 | Mezcla de personas. |
| `PEAK_VUS`, `RAMP_UP`, `PLATEAU`, `RAMP_DOWN` | 2000, 10m, 20m, 5m | `peak-2k.js`. |
| `BASELINE_RPS`, `BASELINE_DURATION` | 200, 10m | `baseline.js`. |
| `SPIKE_BASE_VUS`, `SPIKE_VUS`, `SPIKE_RISE`, `SPIKE_HOLD` | 100, 2000, 20s, 3m | `spike.js`. |
| `SOAK_VUS`, `SOAK_DURATION` | 600, 2h | `soak.js`. |
| `SLO_READ_P95_MS`, `SLO_WRITE_P95_MS`, `SLO_TRACKING_P95_MS`, `SLO_ADMIN_P95_MS` | 250, 1000, 500, 2500 | Pisar un SLO. |
| `SKIP_PREFLIGHT` | — | `1` saltea la validación del tamaño del pool. |

Orden sugerido: `smoke` (¿funciona?) → `baseline` (¿cuánto tarda a carga conocida?) → `peak-2k`
(¿aguanta el objetivo?) → `spike` (¿se recupera?) → `soak` (¿aguanta en el tiempo?).

### Verlo en Grafana (opcional)

El proyecto tiene un stack de observabilidad opt-in (`.docker/compose/docker-compose.observability.yml`:
Prometheus en el servicio `prometheus`, publicado en el host en el puerto 9190, y Grafana). k6 puede
mandarle sus métricas por remote-write mientras corre; el compose de carga ya apunta ahí
(`http://prometheus:9090/api/v1/write` por la red interna):

```bash
$K6 run -o experimental-prometheus-rw /loadtest/peak-2k.js
```

No hay paso manual: ese Prometheus ya arranca con `--web.enable-remote-write-receiver` (solo es
alcanzable por la red interna de docker y por `127.0.0.1` del host). Si el stack de observabilidad no
está levantado, k6 solo avisa por consola que no pudo escribir: la corrida y el resumen JSON no se ven
afectados.

Las series salen con prefijo `k6_` y los tags `kind`, `ep`, `persona` y `scenario`, así se cruzan en el
mismo tablero con las métricas del servidor. Para otro Prometheus, `LOADTEST_PROMETHEUS_RW_URL=…`.

## 3. Leer el resultado contra los SLOs

Al terminar, k6 imprime un resumen y exporta el JSON completo a
`loadtest/data/results/<escenario>[-RUN_ID]-<fecha>.json`. Sale con código distinto de cero si algún
threshold falla.

Qué mirar, en este orden:

1. **`429 (throttle)` debe ser 0.** Si no, la corrida midió el rate limit y no la capacidad: agrandar el
   pool (ver «Rate limit») y repetir. Hay un threshold (`lt_rate_limited`) que la marca como fallida.
2. **`fallos (5xx/red)` < 0,1 %.** Es la disponibilidad del SLO. Aparte, **`4xx` debería ser 0**: no
   cuenta contra el SLO, pero un 4xx en esta prueba suele ser un problema del script o del pool (token
   vencido, parada inexistente) y hay que entenderlo antes de confiar en el resto.
3. **p95 por tipo** contra su SLO, y debajo **p95 por endpoint**: es el que dice cuál rompe el SLO.
4. **`dropped_iterations`** (solo `baseline`): si hay, el generador no llegó a la tasa pedida.
5. **`POD frescos` vs `POD repetidos`**: si casi todos son repetidos, el pool de paradas se agotó y la
   escritura medida fue la barata. Sembrar más paradas.
6. Recién ahí, las métricas del servidor (checklist de abajo) para explicar el porqué.

### Comparar con lo que mide el servidor

El p95 de k6 es el que ve el cliente: incluye la red, nginx y la espera por un worker libre. El servidor
publica el suyo en `GET /api/metrics` (formato Prometheus, protegido por `METRICS_TOKEN` / lista de IPs):
el histograma `logistics_http_request_duration_seconds` por `route`, `method` y `gate`. Comparar siempre
los dos para la misma ventana de la corrida:

```promql
histogram_quantile(0.95, sum by (le) (
    rate(logistics_http_request_duration_seconds_bucket{gate="app", method=~"GET|HEAD"}[5m])
))
```

(Las clases del SLO — `app_read`, `app_write`, `tracking_public`, `admin` — ya están como recording rules
`logistics:slo_latency:p95_5m`; ver `docs/slo.md`.)

- **k6 ≈ servidor:** el tiempo se va en la aplicación. Buscar el endpoint con peor p95 por `route`.
- **k6 muy por encima del servidor:** el tiempo se va ANTES de la app: workers de FPM agotados (cola en
  nginx), red, o el propio generador saturado. Subir réplicas o workers no es lo mismo que optimizar código.
- El histograma del servidor tiene buckets fijos: su p95 es una interpolación dentro del bucket, por eso
  los SLOs están puestos sobre límites de bucket. Diferencias chicas entre ambos números son esperables.
- `/t/{token}` es el `gate="public"` del servidor; el JSON de canales cae en el gate de canales. k6 los
  separa por `ep` (`tracking_page` vs `tracking_public`).

## 4. Rate limit: por qué no hizo falta tocar el código

El limiter `api` (`app/Providers/AuthServiceProvider.php`) ya limita **por token** en la puerta app
(120/min por `sha1(bearer)`), no por IP; la IP solo es la reserva de los requests sin credencial. Y la
puerta de canales limita **por canal** (`rate_limit_per_minute` de `api_channels`). Entonces, desde una
sola IP:

- **Puerta app:** cada VU usa el token de su chofer/operador. Con think de ~10 s un VU hace ~6 req/min,
  muy por debajo de 120. Lo único que hay que cuidar es no colgar demasiados VUs del mismo token; el
  **preflight** de cada script aborta con un mensaje claro si el pool no alcanza (exige que el promedio
  por token quede en ≤ 40 req/min).
- **Rastreo público (JSON de canales):** `loadtest:prepare` crea el canal `LT-loadtest` con un
  `rate_limit_per_minute` alto. Es un dato del entorno de prueba, no un cambio de código, y
  `loadtest:cleanup` lo borra. La página `/t/{token}` no lleva credenciales ni throttle.
- **Login:** `throttle:10,1` por IP. No se levanta: el flujo de login corre a 6/min con cuentas
  reservadas, y el resto de los VUs usa tokens ya emitidos por `loadtest:prepare` (que los emite por el
  mismo camino que el login: dispositivo + token). Una "tormenta de logins" desde una sola IP **no** es
  medible sin debilitar ese throttle; si algún día hace falta, se prueba con varios generadores (varias
  IPs), no con un bypass.

No hay ninguna variable de bypass ni cambio en producción.

## 5. Comparar FPM vs Octane

La comparación solo vale si lo único que cambia es el runtime.

1. **Mismo entorno:** mismo commit, misma base y volumen de datos, mismo PostgreSQL/PgBouncer/
   Valkey, misma cantidad de réplicas de app y de CPU/RAM por réplica, mismo generador, mismo pool
   (re-sembrado igual: `cleanup` + `prepare` con los mismos parámetros antes de cada corrida).
2. **Mismo escenario y parámetros.** Usar `baseline.js` (tasa fija) como corrida principal: en modelo
   abierto la carga no baja cuando el servidor se pone lento, así que los p95 son comparables.
   Etiquetar con `RUN_ID=fpm` / `RUN_ID=octane`.
3. **Calentar antes de medir** (el baseline ya tiene 1 min de rampa) y **repetir 3 veces** cada
   variante; comparar la mediana de las tres, no la mejor.
4. **Buscar el techo de cada una:** subir `BASELINE_RPS` en escalones (200 → 400 → 600 → 800 → 1.000)
   hasta que se rompa un SLO o aparezcan `dropped_iterations`/5xx. El último escalón que cumple es el
   **RPS máximo sostenido**.
5. **Soak obligatorio para Octane** (`soak.js`, 2 h): memoria por worker estable (sin escalera),
   conexiones a PgBouncer y Valkey estables, p95 del final ≈ p95 del principio.

Qué comparar:

| Métrica | De dónde sale | Qué dice |
| --- | --- | --- |
| p95 / p99 por tipo y por endpoint | resumen de k6 | Latencia que ve el usuario. |
| RPS máximo sostenido dentro de SLO | escalones de `baseline.js` | Capacidad por réplica. |
| CPU por request | CPU-segundos de los contenedores de app ÷ requests de la corrida | Costo: cuántas réplicas hacen falta para 2.000. |
| Memoria por worker (inicio vs fin) | `docker stats` / métricas del contenedor | Fugas (Octane). |
| Errores y reinicios de workers | logs + `lt_server_errors` | Estabilidad. |
| Conexiones a PgBouncer / Postgres | `SHOW POOLS` | Octane mantiene conexiones abiertas: puede necesitar otro tamaño de pool. |

CPU por request, a mano: anotar el uso acumulado de CPU de los contenedores de app antes y después de
la corrida (cgroup `cpu.stat` → `usage_usec`, o la integral de `rate(container_cpu_usage_seconds_total)`
en Prometheus) y dividir por `http_reqs` del resumen.

## 6. Dimensionar el generador de carga

- **No correr la prueba de 2.000 VUs en la misma máquina que el stack**: el generador le roba CPU al
  servidor y el resultado mide la pelea entre los dos. El compose sirve para `smoke`, para `baseline`
  chico y para ensayar; la corrida oficial va desde otra máquina.
- **Tamaño:** 2.000 VUs de estos scripts (JSON chico, think de 10 s) piden del orden de 2-4 vCPU y
  4-8 GB de RAM (unos 1-3 MB por VU más el pool en memoria). A 1.000 RPS, 4-8 vCPU.
- **Vigilar el generador:** si su CPU pasa del 80 %, las latencias medidas están infladas. k6 avisa si
  no llega a la tasa (`dropped_iterations`).
- **Sistema:** `ulimit -n 65536` y rango de puertos efímeros amplio (el compose ya lo configura).
- **Red:** generador y stack en la misma red/región; ancho de banda de sobra.
- **Más de un generador** (para pasar de ~1.000 RPS o probar desde varias IPs): partir el pool y los
  VUs con `--execution-segment` de k6 y sumar los resúmenes.

## 7. Checklist durante la corrida

Mirar esto mientras corre y anotarlo en el reporte: es lo que explica el resultado.

- [ ] **App (FPM/Octane):** CPU y memoria por réplica; en FPM, workers ocupados vs `pm.max_children`
      (¿"server reached max_children" en el log?); en Octane, memoria por worker y reinicios.
- [ ] **PgBouncer:** `SHOW POOLS;` → `cl_waiting` (clientes esperando conexión: si es > 0 sostenido, el
      pool es el cuello), `sv_active`, `maxwait`.
- [ ] **PostgreSQL:** conexiones activas, CPU, locks en espera, consultas lentas
      (`php artisan logistics:db:slow-queries`).
- [ ] **Lag de réplica** (si hay réplica de lectura): el comando de lag de replicación del proyecto. Con
      lag, una lectura justo después de un POD puede venir vieja.
- [ ] **Outbox:** pendientes y edad del más viejo (`GET /api/v1/app/outbox/messages/stats`). Tiene que
      drenar al ritmo que se llena; si crece sin parar, los workers del outbox son el cuello.
- [ ] **Colas:** largo de cada cola y jobs fallidos (comando de salud de colas del proyecto).
- [ ] **Valkey:** memoria, `ops/sec`, latencia, evictions (ahí viven la idempotencia, el throttle y el
      snapshot de dispositivos).
- [ ] **nginx:** conexiones activas y 499/502/504.
- [ ] **Generador:** CPU < 80 %, sin `dropped_iterations`.
- [ ] **Tablas que crecen:** `telemetry_pings` y `execution_scan_events` (particionadas).

Sospechosos a mirar primero (salen de leer el código, hay que confirmarlos midiendo):

- La puerta de canales verifica el secreto del canal con `Hash::check` (bcrypt) y hace un `UPDATE` de
  `last_used_at` **en cada request** (`AuthenticateChannel`). Con decenas de RPS sobre el JSON de canales
  eso es CPU pura por request y una fila caliente: candidato firme a romper el SLO de 500 ms. La página
  `/t/{token}` no pasa por ahí, así que comparar `tracking_page` contra `tracking_public` lo confirma.
- Sembrar la ruta por `AttachShipmentToRoute` carga y guarda el agregado completo de la ruta; lo mismo
  hace cada POD al avanzarla. Con rutas largas, el costo crece con la cantidad de paradas.

## 8. Limpiar

```bash
# Ver qué borraría (dry-run por defecto):
docker compose exec -T app php artisan loadtest:cleanup

# Borrar el pool de carga y loadtest/data/pool.json:
docker compose exec -T app php artisan loadtest:cleanup --force
```

Borra **solo** lo que tiene los prefijos del pool (y lo que las corridas dejaron colgando de esas filas:
escaneos, POD, GPS, viajes, alertas, sesiones de login), de hijos a padres y sin `truncate`. Si se
interrumpe, volver a correrlo termina el trabajo. No borra los mensajes del outbox ya emitidos (historial
de eventos; se purgan por su retención) ni las claves efímeras de Valkey (vencen solas).

Los resultados (`loadtest/data/results/`) no se borran: archivarlos con el reporte.
