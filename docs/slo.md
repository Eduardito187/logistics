# SLOs — logistics

> Objetivos de nivel de servicio de la plataforma logística (Ola 5 del
> [roadmap de alta disponibilidad](roadmap-alta-disponibilidad-escala.md): 2.000 clientes concurrentes,
> disponibilidad 99,9 %). Define **qué se mide** (SLI), **cuánto se promete** (SLO), **cuánto se puede
> fallar** (presupuesto de error) y **qué se frena** cuando el presupuesto se agota.
>
> Las consultas viven como *recording rules* en `.docker/observability/rules.yml` (fuente única: si
> cambia un objetivo o un selector, se cambia ahí). Las alertas que los vigilan están en
> `.docker/observability/alerts.yml` y su respuesta en [observability-runbook.md](observability-runbook.md).

## 1. Resumen

| SLO | SLI | Objetivo | Ventana |
| --- | --- | --- | --- |
| Disponibilidad | 1 − 5xx / (total − 4xx) | **99,9 %** | 30 días móviles |
| Latencia — app API lectura | p95 de `gate="app"`, `GET`/`HEAD` | **≤ 250 ms** en el 99 % de las ventanas de 5 min | 30 días |
| Latencia — app API escritura / POD | p95 de `gate="app"`, `POST`/`PUT`/`PATCH`/`DELETE` | **≤ 1 s** en el 99 % de las ventanas | 30 días |
| Latencia — tracking público | p95 de `gate="public"` | **≤ 500 ms** en el 99 % de las ventanas | 30 días |
| Latencia — admin | p95 de `gate="admin"` | **≤ 2,5 s** en el 99 % de las ventanas | 30 días |
| Frescura del outbox | el pendiente más viejo tiene < 60 s | **99 %** del tiempo | 30 días |

Los valores de latencia son **iniciales**: se fijaron antes del primer load test y se recalibran con el
procedimiento de §3.3.

## 2. Disponibilidad — 99,9 % mensual

### 2.1 Definición

Un request es **válido** si su respuesta no es 4xx (un 4xx es un error del cliente: validación,
credenciales, no encontrado; no le cuenta a la plataforma). Un request válido es **malo** si responde
5xx. `/api/metrics` y `/up` no se miden (el collector los omite), así que el scrape y las sondas no
inflan el denominador. El código exacto (`status`) solo existe en el contador
`logistics_http_requests_total`; el histograma de duración no lo lleva.

```
disponibilidad = 1 − (requests 5xx) / (requests totales − requests 4xx)
```

### 2.2 Consultas

Series base (ventana de 5 min, una sola serie cada una):

```promql
# logistics:slo_http_valid:rate5m
sum(rate(logistics_http_requests_total{status!~"4.."}[5m]))

# logistics:slo_http_errors:rate5m
sum(rate(logistics_http_requests_total{status=~"5.."}[5m])) or vector(0)
```

Tasa de error por ventana `W` ∈ {30m, 1h, 2h, 6h, 1d, 3d, 30d} (`logistics:slo_availability:error_ratio_<W>`):

```promql
sum_over_time(logistics:slo_http_errors:rate5m[W]) / (sum_over_time(logistics:slo_http_valid:rate5m[W]) > 0)
```

Disponibilidad del mes y presupuesto restante:

```promql
# Disponibilidad de los últimos 30 días (objetivo ≥ 0.999)
1 - logistics:slo_availability:error_ratio_30d

# Fracción del presupuesto de error que queda (1 = intacto, 0 = agotado)
logistics:slo_availability:error_budget_remaining_ratio
#   = 1 - (logistics:slo_availability:error_ratio_30d / (1 - 0.999))

# Minutos de presupuesto consumidos en la ventana
(logistics:slo_availability:error_ratio_30d / 0.001) * 43.2
```

### 2.3 Límite del SLI y su complemento

El SLI se mide **desde la app**. Si la app está totalmente caída no hay scrape y por lo tanto no hay
muestras: una caída total **no aparece** en la tasa de error. Se cubre con dos piezas:

- La alerta `LogisticsAppCaida` (page a los 2 min).
- El SLI complementario de tiempo scrapeable, que **también** descuenta del presupuesto:

```promql
# logistics:slo_uptime:ratio_30d — fracción del tiempo en que Prometheus pudo scrapear la app
avg_over_time(up{job="logistics-app"}[30d])
```

Para el reporte mensual se toma **el peor de los dos** (disponibilidad por requests y tiempo scrapeable).
Pendiente recomendado: una sonda externa (blackbox) contra `/api/v1/health` desde fuera del host, que
es lo único que ve lo mismo que el cliente (DNS, red, balanceador).

## 3. Latencia — p95 por tipo de request

### 3.1 Clases y objetivos iniciales

| Clase (`slo_class`) | Selector | Objetivo p95 | Por qué ese número |
| --- | --- | --- | --- |
| `app_read` | `gate="app", method=~"GET\|HEAD"` | 250 ms | Lecturas de la app móvil (ver ruta, lista de paradas): ReadModels + caché; el chofer espera con el teléfono en la mano. |
| `app_write` | `gate="app", method!~"GET\|HEAD\|OPTIONS"` | 1 s | Escrituras transaccionales (POD con evidencia, escaneos, asignar vehículo): validación + transacción + outbox en la misma transacción. |
| `tracking_public` | `gate="public"` | 500 ms | Enlace de seguimiento que abre el cliente final: mucho tráfico, datos poco cambiantes, cacheable. |
| `admin` | `gate="admin"` | 2,5 s | Panel Filament/Livewire: páginas con tablas y widgets; usuarios internos, más tolerancia. |

El SLO se cumple si el p95 de la ventana de 5 min está dentro del objetivo en al menos el **99 % de las
ventanas** de los últimos 30 días (una ventana sin tráfico no cuenta).

### 3.2 Consultas

```promql
# logistics:slo_latency:p95_5m{slo_class="app_read"}
histogram_quantile(0.95, sum by (le) (
    rate(logistics_http_request_duration_seconds_bucket{gate="app", method=~"GET|HEAD"}[5m])
))

# logistics:slo_latency:p95_5m{slo_class="app_write"}
histogram_quantile(0.95, sum by (le) (
    rate(logistics_http_request_duration_seconds_bucket{gate="app", method!~"GET|HEAD|OPTIONS"}[5m])
))

# logistics:slo_latency:p95_5m{slo_class="tracking_public"}
histogram_quantile(0.95, sum by (le) (
    rate(logistics_http_request_duration_seconds_bucket{gate="public"}[5m])
))

# logistics:slo_latency:p95_5m{slo_class="admin"}
histogram_quantile(0.95, sum by (le) (
    rate(logistics_http_request_duration_seconds_bucket{gate="admin"}[5m])
))

# ¿La ventana cumplió? (1/0) — logistics:slo_latency:within_objective
logistics:slo_latency:p95_5m <= bool on (slo_class) logistics:slo_latency:objective_seconds

# Cumplimiento del SLO en 30 días, por clase (objetivo ≥ 0.99)
avg_over_time(logistics:slo_latency:within_objective[30d])
```

Los objetivos son series (`logistics:slo_latency:objective_seconds{slo_class}`), así alertas y dashboard
los leen del mismo lugar.

> **Puertas (`gate`):** `app`, `channels`, `integrations`, `admin`, `public`, `other`. Las puertas
> `channels`, `integrations` y `other` no tienen SLO de latencia propio todavía: cuentan para la
> disponibilidad y se ven en el panel «p95 por puerta».

> **Precisión del p95:** `histogram_quantile` interpola dentro del bucket. Los buckets del collector son
> `le` = 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10 s. Por eso **cada objetivo está sobre un límite de bucket**
> (0.25, 0.5, 1, 2.5): «p95 ≤ objetivo» equivale exactamente a «al menos el 95 % de los requests cayó en
> el bucket del objetivo», sin error de interpolación. Un objetivo intermedio (p. ej. 300 ms) se mediría
> con un error de hasta el ancho del bucket. Si hace falta más resolución, se agregan buckets al collector.

> **Varias réplicas:** contadores e histogramas son **globales** (compartidos por todas las réplicas vía
> Valkey, sin etiqueta de instancia). Prometheus scrapea **un solo target lógico** (el balanceador). Si se
> scrapearan varias réplicas, cada una devolvería el mismo total y las sumas contarían doble: en ese caso
> hay que agregar antes con `max by (...)`.

### 3.3 Cómo recalibrar tras el primer load test

Los objetivos de arriba son una hipótesis. El primer load test (k6/Gatling a 2.000 concurrentes, escenario
login → ver ruta → POD → tracking → admin) los convierte en datos:

1. Correr el escenario **al menos 30 min en régimen estable** contra un entorno con la topología objetivo
   (PgBouncer, réplica, workers reales).
2. Tomar el p95 **medido** por clase en ese tramo:

   ```promql
   max_over_time(logistics:slo_latency:p95_5m[30m])
   ```

3. Fijar el objetivo como **p95 medido + 20–30 % de margen**, redondeado **hacia arriba al límite de
   bucket** más cercano (0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10 s). Nunca se fija un objetivo más estricto que lo que el sistema ya demostró cumplir.
4. Si el p95 medido **supera** lo que la experiencia de usuario tolera (≈ 1 s en la app móvil y en el
   tracking público), el objetivo **no** se afloja: queda el número deseado y la brecha entra al backlog
   de performance como deuda explícita.
5. Editar el valor en `rules.yml` (`logistics:slo_latency:objective_seconds`), actualizar la tabla de §3.1 y
   recargar Prometheus (`curl -X POST http://localhost:9190/-/reload`).
6. Repetir tras cada ola de escalabilidad (Octane, réplicas de lectura, HA): un objetivo que se cumple al
   100 % durante un trimestre está flojo y se ajusta hacia abajo.

## 4. Frescura del outbox

El outbox es el corazón de la integración asíncrona (tracking, notificaciones al cliente, SAP). Su SLO
responde: *¿los eventos salen a tiempo?*

- **Objetivo declarado:** el 99 % de los eventos se despacha en menos de 60 s.
- **SLI disponible hoy (proxy):** el 99 % del **tiempo**, el mensaje pendiente más viejo tiene menos de 60 s.
  Es una cota conservadora: si el más viejo tiene menos de 60 s, **todos** los pendientes la cumplen.
  La métrica vale 0 cuando no hay pendientes (outbox vacío = fresco).

```promql
# logistics:slo_outbox_freshness:within_objective (1/0)
logistics_outbox_oldest_pending_age_seconds < bool 60

# logistics:slo_outbox_freshness:ratio_30d (objetivo ≥ 0.99)
avg_over_time(logistics:slo_outbox_freshness:within_objective[30d])
```

Presupuesto: 1 % de 30 días = **7 h 12 min** al mes con el outbox atrasado más de 60 s.

Mejora pendiente (pedido al collector de la app): un histograma
`logistics_outbox_dispatch_delay_seconds` (tiempo entre `created_at` y `dispatched_at` de cada mensaje)
permitiría medir el objetivo por **evento** y no por tiempo:

```promql
sum(rate(logistics_outbox_dispatch_delay_seconds_bucket{le="60"}[30d]))
/
sum(rate(logistics_outbox_dispatch_delay_seconds_count[30d]))
```

El dead-letter **no tiene presupuesto**: `logistics_outbox_messages{status="dead_lettered"} > 0` siempre es
una alerta (ver runbook).

## 5. Presupuesto de error

| SLO | Objetivo | Presupuesto en 30 días |
| --- | --- | --- |
| Disponibilidad | 99,9 % | 0,1 % de los requests válidos ≈ **43,2 minutos** de caída total equivalente |
| Latencia (cada clase) | 99 % de las ventanas | 1 % ≈ 86 ventanas de 5 min ≈ **7 h 12 min** fuera de objetivo |
| Frescura del outbox | 99 % del tiempo | **7 h 12 min** con pendientes de más de 60 s |

Cálculo de disponibilidad: 30 días × 24 h × 60 min = 43.200 min; × 0,001 = **43,2 min**. En semana: 10,1 min;
en día: 1,44 min.

### Alertas de quema (burn rate)

La quema es la tasa de error dividida por el presupuesto (0,001). Quema 1× = se gasta exactamente el
presupuesto en 30 días. Alertas multi-ventana (SRE Workbook); cada una exige la ventana larga **y** una
corta para apagarse rápido cuando el problema termina:

| Alerta | Ventanas | Quema | Presupuesto gastado | Severidad |
| --- | --- | --- | --- | --- |
| `LogisticsSLOBurnRateRapido1h` | 1 h y 5 min | 14,4× | 2 % en 1 h | page |
| `LogisticsSLOBurnRateRapido6h` | 6 h y 30 min | 6× | 5 % en 6 h | page |
| `LogisticsSLOBurnRateLento1d` | 1 d y 2 h | 3× | 10 % en 1 d | ticket |
| `LogisticsSLOBurnRateLento3d` | 3 d y 6 h | 1× | 10 % en 3 d | ticket |

```promql
# Quema rápida, 1 h
logistics:slo_availability:error_ratio_1h > (14.4 * 0.001)
and logistics:slo_availability:error_ratio_5m > (14.4 * 0.001)

# Quema rápida, 6 h
logistics:slo_availability:error_ratio_6h > (6 * 0.001)
and logistics:slo_availability:error_ratio_30m > (6 * 0.001)

# Quema lenta, 1 d
logistics:slo_availability:error_ratio_1d > (3 * 0.001)
and logistics:slo_availability:error_ratio_2h > (3 * 0.001)

# Quema lenta, 3 d
logistics:slo_availability:error_ratio_3d > (1 * 0.001)
and logistics:slo_availability:error_ratio_6h > (1 * 0.001)
```

## 6. Política de presupuesto de error

El presupuesto es lo que permite cambiar cosas: mientras queda, se despliega con normalidad; cuando se
acaba, la prioridad pasa de *entregar* a *estabilizar*. Se evalúa sobre
`logistics:slo_availability:error_budget_remaining_ratio` (y el equivalente de latencia/outbox).

| Presupuesto restante | Estado | Qué se hace |
| --- | --- | --- |
| > 50 % | Verde | Ritmo normal de deploys y migraciones. |
| 10 % – 50 % | Amarillo | Se sigue desplegando, pero cada deploy de riesgo (migración sobre tabla caliente, cambio de infraestructura, Octane, PgBouncer) va con plan de rollback escrito y fuera del horario pico de despacho. Se revisa en la semanal qué consumió el presupuesto. |
| < 10 % | Rojo (`LogisticsSLOPresupuestoCasiAgotado`) | **Se congelan** los cambios de riesgo: nada de migraciones sobre tablas calientes, cambios de infraestructura ni features nuevas que toquen los caminos del SLO. Solo entran arreglos de confiabilidad, seguridad y errores bloqueantes de operación. |
| ≤ 0 % (agotado) | Congelado | Congelamiento total de features hasta que la ventana de 30 días vuelva a tener presupuesto. El trabajo del equipo va al postmortem y a sus acciones. Levantar el congelamiento antes requiere decisión explícita del dueño del proyecto, registrada por escrito. |

Reglas que acompañan:

- **Todo page de quema rápida y todo incidente que consuma más del 20 % del presupuesto mensual** lleva
  postmortem sin culpables (qué pasó, por qué no se detectó antes, qué se cambia) en la semana siguiente.
- **Lo que no descuenta:** mantenimientos anunciados con anticipación a operación y ejecutados fuera de
  horario de despacho; caídas de dependencias externas que el diseño ya aísla por outbox (SAP, FCM, SMTP)
  mientras la API siga respondiendo.
- **Lo que sí descuenta:** todo 5xx que recibe un cliente, venga de un bug, de un deploy o de la base.
- El presupuesto de **latencia** y el de **frescura del outbox** siguen la misma tabla, pero su
  congelamiento alcanza solo a los cambios que tocan ese camino (p. ej. presupuesto de outbox agotado →
  no se suman reactores ni destinos nuevos hasta estabilizar el pump y los workers).

## 7. Revisión

- **Semanal:** mirar el dashboard «Logistics — Visión general» (paneles de burn rate y cumplimiento de
  SLOs) y qué alertas sonaron.
- **Mensual:** registrar disponibilidad, cumplimiento de latencia por clase, frescura del outbox y minutos
  de presupuesto consumidos.
- **Tras cada load test / ola del roadmap:** recalibrar latencia (§3.3) y revisar si los objetivos siguen
  siendo los que el negocio necesita.

## Referencias

- `.docker/observability/rules.yml` — recording rules de todos los SLIs de este documento.
- `.docker/observability/alerts.yml` — alertas (incluidas las de burn rate).
- [observability-runbook.md](observability-runbook.md) — cómo levantar el stack y qué hacer ante cada alerta.
- [observability.md](observability.md) — logs, métricas y trazabilidad de la cadena asíncrona.
- [ha-runbook.md](ha-runbook.md) — topología HA, failover y deploy sin downtime.
