# Reporte de load test — {escenario} — {fecha}

> Copiar esta plantilla por corrida (o por comparación FPM vs Octane) y completarla. Adjuntar el JSON de
> `loadtest/data/results/`. Los objetivos salen de `docs/slo.md`.

## Veredicto

**{Cumple / No cumple} los SLOs a {N} VUs / {N} RPS.** {Una o dos frases: qué aguantó, dónde está el
cuello y qué se recomienda hacer.}

## Contexto de la corrida

| Dato | Valor |
| --- | --- |
| Fecha y hora (inicio – fin) | |
| Entorno (nunca producción) | |
| Commit / rama | |
| Runtime de la app | FPM (`pm.max_children` = ) / Octane (workers = ) |
| Réplicas de app × CPU / RAM | |
| PostgreSQL (versión, CPU/RAM, `max_connections`) | |
| PgBouncer (modo, `default_pool_size`, `max_client_conn`) | |
| Valkey | |
| Réplica de lectura (sí/no) | |
| Workers de cola / outbox | |
| Generador (máquina, CPU/RAM, ubicación de red) | |
| k6 (versión) | |
| Escenario y parámetros (`-e …`) | |
| `RUN_ID` y archivo de resumen | |
| Pool (`--drivers` × `--stops`, operadores, lote) | |
| Volumen de datos de la base (envíos, pings, escaneos) | |

## Resultado contra los SLOs

| SLO | Objetivo | Medido (k6) | Medido (servidor, `/api/metrics`) | ¿Cumple? |
| --- | --- | --- | --- | --- |
| Disponibilidad (`http_req_failed`, solo 5xx y errores de red) | < 0,1 % | | | |
| Lectura app p95 (`kind:read`) | < 250 ms | | | |
| Escritura / POD p95 (`kind:write`) | < 1 s | | | |
| Rastreo público p95 (`kind:tracking`) | < 500 ms | | | |
| Admin p95 (`kind:admin`, solo la página de acceso: muestra parcial) | < 2,5 s | | | |

| Dato de la corrida | Valor |
| --- | --- |
| VUs máximos | |
| Requests totales / RPS promedio / RPS pico | |
| 429 (`lt_rate_limited`) — debe ser 0 | |
| 5xx / red (`lt_server_errors`) | |
| 4xx (`lt_client_errors`) — debería ser 0; no cuenta contra el SLO | |
| Checks OK | |
| `dropped_iterations` (baseline) | |
| POD frescos / POD repetidos | |
| Login p95 (informativo) | |

## Latencia por endpoint

| Endpoint (`ep`) | Requests | med | p95 | p99 | max |
| --- | --- | --- | --- | --- | --- |
| `driver_route` — GET /app/vehicles/{vehicleId}/route | | | | | |
| `driver_deliveries` — GET /app/vehicles/{vehicleId}/deliveries | | | | | |
| `delivery_state` — GET /app/deliveries/{shipmentId} | | | | | |
| `telemetry_pings` — POST /app/telemetry/pings | | | | | |
| `stop_arrived` — POST /app/deliveries/{shipmentId}/arrived | | | | | |
| `pod_complete` — POST /app/deliveries/{shipmentId}/complete | | | | | |
| `tracking_page` — GET /t/{token} | | | | | |
| `tracking_public` — GET /channels/tracking/{token} | | | | | |
| `tracking_public_units` — GET /channels/tracking/{token}/units | | | | | |
| `ops_shipments_list` — GET /app/shipments | | | | | |
| `ops_shipments_summary` — GET /app/shipments/summary | | | | | |
| `ops_fleet_live` — GET /app/telemetry/fleet/live | | | | | |
| `ops_kpis_daily` — GET /app/kpis/daily | | | | | |
| `ops_shipment_tracking` — GET /app/tracking/shipments/{shipmentId} | | | | | |
| `admin_login_page` — GET /admin/login | | | | | |

Objetivo p95 por clase: 250 ms las lecturas (`driver_*`, `delivery_state`, `ops_*`), 1 s las escrituras
(`telemetry_pings`, `stop_arrived`, `pod_complete`), 500 ms `tracking_*`, 2,5 s `admin_*`.

## Recursos durante la corrida

| Recurso | Reposo | Meseta | Pico | Observación |
| --- | --- | --- | --- | --- |
| App: CPU por réplica | | | | |
| App: memoria por réplica / por worker | | | | |
| App: workers ocupados (FPM) / reinicios (Octane) | | | | |
| PgBouncer: `cl_waiting` / `maxwait` | | | | |
| PostgreSQL: conexiones activas / CPU | | | | |
| PostgreSQL: locks en espera / consultas lentas | | | | |
| Lag de réplica | | | | |
| Outbox: pendientes / edad del más viejo | | | | |
| Colas: largo / fallidos | | | | |
| Buffer de pings (`logistics_telemetry_ingest_buffer_depth`), si la ingesta va en modo buffered | | | | |
| p95 del servidor por clase (`logistics:slo_latency:p95_5m`) vs p95 de k6 | | | | |
| Valkey: memoria / ops por segundo / evictions | | | | |
| nginx: 499 / 502 / 504 | | | | |
| Generador: CPU | | | | |

## Comparación FPM vs Octane

> Solo si la corrida es parte de la comparación. Mismo escenario, mismas réplicas, mismo pool; mediana
> de 3 corridas por variante.

| Métrica | FPM | Octane | Diferencia |
| --- | --- | --- | --- |
| Lectura p95 | | | |
| Escritura p95 | | | |
| Rastreo p95 | | | |
| p99 global | | | |
| p95 servidor vs p95 k6 (lectura) | | | |
| RPS máximo sostenido dentro de SLO | | | |
| CPU por request (CPU-s de app ÷ requests) | | | |
| Memoria por worker (inicio → fin del soak) | | | |
| Conexiones a PgBouncer en meseta | | | |
| Errores / reinicios de workers | | | |

**Conclusión de la comparación:** {cuál conviene, con qué configuración y cuántas réplicas hacen falta
para 2.000 concurrentes}.

## Cuello de botella

- **Qué se saturó primero:** {CPU de app / pool de PgBouncer / una consulta / outbox / Valkey / el
  generador}.
- **Evidencia:** {métrica, gráfico o log que lo muestra}.
- **A partir de qué carga:** {VUs / RPS}.

## Hallazgos y acciones

| # | Hallazgo | Evidencia | Acción propuesta | Prioridad | Responsable |
| --- | --- | --- | --- | --- | --- |
| 1 | | | | | |
| 2 | | | | | |

## Limpieza

- [ ] `php artisan loadtest:cleanup --force` corrido (o el pool se deja para la próxima corrida: {motivo}).
- [ ] Resumen JSON archivado junto a este reporte.
- [ ] Outbox y colas volvieron a cero después de la corrida.
