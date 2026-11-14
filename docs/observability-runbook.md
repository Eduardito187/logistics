# Runbook — Observabilidad y alertas (Ola 5)

> Cómo levantar el stack de monitoreo (Prometheus + Alertmanager + Grafana + exporters), cómo se integra en
> staging y qué hacer ante cada alerta: **síntoma → diagnóstico → acción**.
>
> Complementa [observability.md](observability.md) (qué se mide y por qué), [slo.md](slo.md) (objetivos y
> presupuesto de error) y [ha-runbook.md](ha-runbook.md) (failover y deploy sin downtime).

El stack es **opt-in**: vive en un archivo compose aparte y la plataforma funciona igual sin él. No carga
nada de terceros (cero CDN): Grafana corre sin plugins externos, sin telemetría y con el dashboard
provisionado desde el repo.

| Pieza | Archivo |
| --- | --- |
| Servicios | `.docker/compose/docker-compose.observability.yml` |
| Scrape | `.docker/observability/prometheus.yml` |
| Recording rules (SLIs) | `.docker/observability/rules.yml` |
| Alertas | `.docker/observability/alerts.yml` |
| Ruteo de alertas (plantilla) | `.docker/observability/alertmanager.yml` |
| Datasources y proveedor de dashboards | `.docker/observability/grafana/provisioning/` |
| Dashboard | `.docker/observability/grafana/dashboards/logistics-overview.json` |

---

## 1. Levantar el stack (desarrollo)

### 1.1 Requisito único: `METRICS_TOKEN`

`/api/metrics` es *fail-closed*: sin token ni allowlist responde 404. El **mismo** valor lo lee la app
(`config/observability.php`) y lo usa Prometheus, y ambos lo toman del `.env` de la raíz:

```bash
# Generar un token y ponerlo en el .env (METRICS_TOKEN=...)
openssl rand -hex 32
```

Después de cambiarlo, la app tiene que releer su config:

```bash
docker compose exec -T app php artisan config:clear
```

Cómo llega el token a Prometheus: Prometheus no expande variables de entorno en su config, así que el
entrypoint del servicio escribe la env `METRICS_TOKEN` en `/prometheus/runtime/metrics_token` (0600, dentro
del volumen) y `prometheus.yml` lo referencia con `authorization.credentials_file`. El secreto nunca queda
en el repo.

### 1.2 Arrancar

```bash
docker compose -f docker-compose.yml -f .docker/compose/docker-compose.observability.yml up -d
```

Si el entorno ya apila otros overrides (Windows, HA), se agregan los mismos `-f` de siempre y este al final.

| Servicio | URL (solo `127.0.0.1` por defecto) | Credenciales |
| --- | --- | --- |
| Grafana | http://localhost:3110 | `admin` / `admin` (`GRAFANA_ADMIN_USER` / `GRAFANA_ADMIN_PASSWORD`) |
| Prometheus | http://localhost:9190 | sin autenticación |
| Alertmanager | http://localhost:9193 | sin autenticación |

Los exporters (`postgres-exporter`, `valkey-exporter`) no publican puertos: solo los ve Prometheus por la red
`dismac_lg_network`.

### 1.3 Verificar

1. **Targets**: http://localhost:9190/targets → `logistics-app`, `postgres`, `valkey`, `prometheus`,
   `alertmanager` y `grafana` en `UP`.
2. **Scrape de la app a mano** (lo mismo que hace Prometheus):

   ```bash
   curl -s -H "Authorization: Bearer $METRICS_TOKEN" http://localhost:8110/api/metrics | head
   ```

3. **Reglas**: http://localhost:9190/rules sin errores; http://localhost:9190/alerts lista las alertas.
4. **Grafana**: abre directo en el dashboard «Logistics — Visión general» (carpeta *Logistics*).

Si `logistics-app` aparece `DOWN` con `server returned HTTP status 404`, el token no coincide o está vacío
(el log del contenedor `prometheus` lo avisa al arrancar).

### 1.4 Cómo leer las métricas de la app

- **Un solo target lógico.** Contadores e histogramas son globales: todas las réplicas los comparten vía
  Valkey y no llevan etiqueta de instancia. Prometheus scrapea **un** target (nginx / el balanceador). No
  agregar un target por réplica: cada una devolvería el mismo total y las sumas contarían doble (si alguna
  vez se hace, agregar con `max by (...)`).
- **Latidos en `-1`.** `logistics_scheduler_last_tick_age_seconds` y
  `logistics_queue_worker_last_activity_age_seconds{queue}` valen `-1` cuando nunca se observó un latido
  (stack recién levantado, proceso que no arrancó). Las alertas tratan `-1` como «sin latido».
- **El latido de un worker no avanza mientras corre un job.** Por eso la alerta exige además cola no vacía
  y `planning` tiene umbral de 30 min.
- **Caché = toda la instancia Valkey.** `logistics_cache_*` mide la instancia completa (caché + colas +
  sesiones + locks), no solo las claves de caché.
- **Lag de réplica.** `logistics_db_replication_lag_bytes` no tiene muestras si no hay standby (las alertas
  no evalúan, es lo esperado). **Sale 0 si el usuario de la base no tiene el rol `pg_monitor`**: con
  standby y lag clavado en 0, verificar el rol antes de confiar en el número
  (`GRANT pg_monitor TO <usuario>;`) y contrastar con `php artisan logistics:replication:lag`.
- **No se miden** `/api/metrics` ni `/up`: no aparecen en RPS, errores ni latencia.
- **El histograma de latencia no lleva `status`** (solo `route`, `method`, `gate`, `le`); el código exacto
  está en `logistics_http_requests_total`.

### 1.5 Operación del stack

```bash
# Recargar reglas/config de Prometheus tras editar prometheus.yml, rules.yml o alerts.yml
curl -X POST http://localhost:9190/-/reload

# Aplicar cambios de alertmanager.yml o de las envs ALERT_* (la plantilla se renderiza al arrancar)
docker compose -f docker-compose.yml -f .docker/compose/docker-compose.observability.yml up -d --force-recreate alertmanager

# Bajar SOLO el monitoreo (la plataforma sigue corriendo)
docker compose -f docker-compose.yml -f .docker/compose/docker-compose.observability.yml stop prometheus alertmanager grafana postgres-exporter valkey-exporter
```

El dashboard se edita en su JSON (el proveedor tiene `allowUiUpdates: false`): se prueba el cambio en la UI,
se exporta el JSON y se commitea. Retención de Prometheus: 35 días (`OBS_PROMETHEUS_RETENTION`), lo que
necesitan las ventanas de 30 días de los SLOs.

### 1.6 Notificaciones

Por defecto las alertas **no se envían a nadie**: `ALERT_WEBHOOK_URL` apunta a un puerto cerrado a propósito
(nada sale a un tercero sin decisión explícita). Se ven igual en Alertmanager y en Grafana. Para recibirlas:

```dotenv
# Webhook (Slack/Teams/ntfy/puente propio)
ALERT_CHANNEL=webhook
ALERT_WEBHOOK_URL=https://<destino-de-tickets>
ALERT_WEBHOOK_URL_PAGE=https://<destino-de-guardia>   # opcional; vacío = usa ALERT_WEBHOOK_URL

# o email
ALERT_CHANNEL=email
ALERT_EMAIL_TO=ops@<dominio>
ALERT_EMAIL_TO_PAGE=guardia@<dominio>
ALERT_SMTP_SMARTHOST=<host>:587
ALERT_SMTP_FROM=alertas@<dominio>
ALERT_SMTP_USERNAME=<usuario>
ALERT_SMTP_PASSWORD=<clave>
```

Ruteo: `severity="page"` → receptor de guardia (repite cada 1 h); `severity="ticket"` → receptor de tickets
(repite cada 12 h). Mientras `LogisticsAppCaida` está activa se silencian las alertas derivadas de la app
(`layer="app"`); las de infraestructura (`layer="infra"`) no.

Probar el canal de punta a punta sin esperar un incidente:

```bash
curl -s -X POST http://localhost:9193/api/v2/alerts -H 'Content-Type: application/json' -d '[
  {"labels": {"alertname": "PruebaDeCanal", "severity": "ticket", "service": "logistics", "layer": "app"},
   "annotations": {"summary": "Prueba del canal de alertas"}}
]'
```

---

## 2. Integración en staging

Staging usa un compose **autónomo** (`.docker/compose/docker-compose.staging.yml`, proyecto
`logistics-staging`) y su deploy hace `up -d --remove-orphans`. Dos consecuencias:

- El monitoreo **no** se apila en el mismo proyecto: `--remove-orphans` lo borraría en cada deploy. Corre
  como proyecto aparte, `logistics-observability`, enganchado a la red docker de staging.
- El runner **limpia el workspace** al terminar cada job, así que la config no se puede montar desde el
  checkout: se copia a un directorio persistente del servidor (el mismo `STAGING_STATE_DIR` que ya guarda
  la clave de etiquetas).

Diferencias de staging respecto de dev: la app se scrapea en `web:8080` (no `nginx:80`), la red se llama
`logistics-staging_staging` y la clave de Postgres es el secret `STAGING_DB_PASSWORD`.

> Estos pasos están **documentados, no aplicados**: los archivos de staging no se tocaron.

### 2.1 `scripts/staging/render-env.sh` — token de métricas estable

Agregar después del bloque de `LABELS_SIGNING_KEY` (mismo patrón: si no viene en `STAGING_ENV_EXTRA`, se
genera una vez y se persiste fuera del workspace):

```bash
# Token de /api/metrics (lo comparten la app y Prometheus). Estable entre deploys.
if ! grep -Eq '^METRICS_TOKEN=.+' "$out"; then
    state_dir="${STAGING_STATE_DIR:-$HOME/.logistics-staging}"
    token_file="$state_dir/metrics-token.env"
    if [[ ! -s "$token_file" ]]; then
        command -v openssl >/dev/null 2>&1 || fail "Falta openssl en el runner para generar METRICS_TOKEN (o definilo en STAGING_ENV_EXTRA)."
        mkdir -p "$state_dir"
        chmod 700 "$state_dir"
        printf 'METRICS_TOKEN=%s\n' "$(openssl rand -hex 32)" > "$token_file"
        chmod 600 "$token_file"
        echo "[render-env] METRICS_TOKEN generado y persistido en $token_file."
    fi
    grep -Ev '^METRICS_TOKEN=' "$out" > "$out.tmp" || true
    cat "$token_file" >> "$out.tmp"
    mv "$out.tmp" "$out"
fi

# El exporter de PostgreSQL usa la misma credencial de la base de staging.
grep -q '^OBS_PG_PASSWORD=' "$out" || echo "OBS_PG_PASSWORD=${DB_PASSWORD}" >> "$out"
```

La clave de Grafana y el destino de las alertas van en el secret `STAGING_ENV_EXTRA`
(`GRAFANA_ADMIN_PASSWORD=...`, `ALERT_WEBHOOK_URL=...`); no hace falta código nuevo para eso.

### 2.2 `scripts/staging/deploy.sh` — levantar el proyecto de observabilidad

Agregar después del paso «Smoke test HTTP» (antes del `trap - ERR` final). No bloquea el deploy: el
monitoreo caído no debe tumbar un deploy sano.

```bash
step "Observabilidad: Prometheus + Alertmanager + Grafana (proyecto aparte, no bloquea el deploy)"
obs_dir="${STAGING_STATE_DIR:-$HOME/.logistics-staging}/observability"
mkdir -p "$obs_dir"
# Copia EN SITIO (no reemplazar el directorio: los contenedores lo tienen montado por inodo).
cp -R "$root/.docker/observability/." "$obs_dir/"
chmod -R a+rX "$obs_dir"
obs_compose="$(to_native "$root/.docker/compose/docker-compose.observability.yml")"
obs_dc() {
    OBS_CONFIG_DIR="$(to_native "$obs_dir")" \
    OBS_NETWORK="${project}_staging" \
    OBS_NETWORK_EXTERNAL=true \
    OBS_METRICS_TARGET=web:8080 \
    OBS_CONTAINER_PREFIX="${project//-/_}" \
    docker compose -p "${project}-observability" -f "$obs_compose" --env-file "$env_file" "$@"
}
# --force-recreate: relee la config copiada y vuelve a renderizar token y plantilla de Alertmanager.
obs_dc up -d --force-recreate --wait --wait-timeout 180 \
    || echo "AVISO: el stack de observabilidad no levantó; el deploy sigue. Revisar: docker compose -p ${project}-observability logs."
endstep
```

Verificación opcional dentro del mismo paso (el scrape autenticado funciona):

```bash
metrics_token="$(grep -E '^METRICS_TOKEN=' "$env_file" | tail -1 | cut -d= -f2)"
code="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer ${metrics_token}" "${base_url}/api/metrics" || true)"
[[ "$code" == "200" ]] || echo "AVISO: /api/metrics respondió ${code} con el token de staging."
```

### 2.3 `.docker/compose/docker-compose.staging.yml`

No necesita servicios nuevos. Lo único recomendable es **fijar el nombre de la red** para que sea un
contrato explícito y no el nombre derivado del proyecto:

```yaml
networks:
    staging:
        driver: bridge
        name: logistics-staging_staging
```

(Es el mismo nombre que Compose ya genera hoy, así que no recrea la red.) La app y el worker reciben
`METRICS_TOKEN` por el env-file generado en §2.1, sin cambios en el compose.

### 2.4 Acceso

Los puertos quedan en `127.0.0.1` del servidor (Prometheus y Alertmanager no tienen autenticación). Se entra
por túnel SSH, sin abrir nada en la Security List:

```bash
ssh -L 3110:127.0.0.1:3110 -L 9190:127.0.0.1:9190 -L 9193:127.0.0.1:9193 <usuario>@<servidor-staging>
```

### 2.5 Operación en staging

```bash
# Estado y logs del monitoreo
docker compose -p logistics-observability ps
docker compose -p logistics-observability logs -f --tail=100 prometheus

# Bajar el monitoreo sin tocar staging (los volúmenes con el historial se conservan)
docker compose -p logistics-observability down
```

Los comandos de diagnóstico de la §3 se escriben para dev (`docker compose exec -T app …`). En staging el
prefijo es `docker compose -p logistics-staging exec -T app …`; los workers y el scheduler viven en el
servicio `worker` (dev: `queue-worker` y `scheduler`) y nginx es `web` (dev: `nginx`).

---

## 3. Respuesta a alertas

Convención: **page** = hay impacto ahora, se atiende de inmediato; **ticket** = se atiende en horario hábil.
Antes de cualquier acción correctiva sobre datos, respaldo: `php artisan backup:create`
(ver [backup-restore.md](backup-restore.md)).

Primer vistazo común a cualquier alerta:

```bash
docker compose ps                                          # qué contenedor está caído / unhealthy
docker compose exec -T app php artisan health:check        # PostgreSQL/PostGIS + esquema + Valkey
curl -s http://localhost:8110/api/v1/health                # lo mismo, por HTTP
```

### LogisticsAppCaida

**Severidad:** page · **Condición:** no hay `logistics_up = 1` hace 2 min.

- **Síntoma:** la app no responde el scrape. Los usuarios probablemente ven errores o timeouts. El resto de
  las alertas de la app se silencia mientras dure.
- **Diagnóstico:**
  1. `docker compose ps`: ¿`app` y `nginx` están `running`/`healthy`?
  2. `curl -s http://localhost:8110/api/v1/health`: si responde 200, la app vive y el problema es el
     **scrape** (token).
  3. http://localhost:9190/targets → error del job `logistics-app`: `404` = token vacío o distinto;
     `connection refused`/`context deadline exceeded` = nginx o FPM caídos o saturados.
  4. `docker compose logs --tail=100 app nginx`.
- **Acción:**
  - Token: igualar `METRICS_TOKEN` en el `.env`, `php artisan config:clear` y recrear `prometheus`.
  - App caída: `docker compose up -d app nginx`. Si quedó `unhealthy` por dependencia, resolver primero
    PostgreSQL/Valkey (ver `LogisticsPostgresCaido` / `LogisticsValkeyCaido`).
  - Si la caída empezó con un deploy: rollback del deploy; antes del próximo,
    `php artisan logistics:pre-deploy`.

### Logistics5xxAlto

**Severidad:** page (> 5 % en 5 min) / ticket `Logistics5xxElevado` (> 1 % en 15 min), por puerta (`gate`).

- **Síntoma:** una fracción de los requests de esa puerta falla con 5xx.
- **Diagnóstico:**
  1. Qué rutas fallan (Prometheus):

     ```promql
     topk(10, sum by (route, status) (rate(logistics_http_requests_total{status=~"5.."}[5m])))
     ```

  2. La excepción dominante: `docker compose logs --tail=200 app` (o `storage/logs/laravel.log`).
  3. ¿Coincide con un deploy o una migración? El error `0A000 cached plan must not change result type`
     tras migrar en vivo tumba todos los requests autenticados.
  4. ¿Saturación? Paneles «Uso de conexiones» y «Latencia»: sin conexiones de DB los requests terminan en 5xx.
- **Acción:**
  - Bug de un deploy: rollback.
  - `0A000`: reiniciar la app para descartar los planes viejos (`docker compose restart app`) y verificar que
    la migración corrió por la conexión directa (`--database=pgsql_direct`).
  - Dependencia caída: seguir la alerta de esa dependencia.
  - Una sola ruta rota y no crítica: arreglo normal por ticket; vigilar el burn rate.

### LogisticsSLOBurnRate

**Alertas:** `LogisticsSLOBurnRateRapido1h` y `LogisticsSLOBurnRateRapido6h` (page),
`LogisticsSLOBurnRateLento1d` y `LogisticsSLOBurnRateLento3d` (ticket),
`LogisticsSLOPresupuestoCasiAgotado` (ticket).

- **Síntoma:** el presupuesto de error del SLO de disponibilidad (43,2 min/mes) se consume más rápido de lo
  sostenible. Rápida = incidente en curso; lenta = errores crónicos de baja intensidad.
- **Diagnóstico:**
  1. Panel «Burn rate (× presupuesto)»: ¿sube, se estabiliza o ya baja?
  2. Mismo desglose por ruta que en `Logistics5xxAlto`, con la ventana de la alerta:

     ```promql
     topk(10, sum by (route) (increase(logistics_http_requests_total{status=~"5.."}[6h])))
     ```

  3. Presupuesto restante: `logistics:slo_availability:error_budget_remaining_ratio`.
- **Acción:**
  - Rápida: tratarla como `Logistics5xxAlto` (mitigar primero: rollback, reinicio, sacar la dependencia rota).
  - Lenta: abrir ticket por la ruta que más aporta y priorizarlo sobre features.
  - Presupuesto < 10 %: aplicar la política de [slo.md §6](slo.md) (congelar cambios de riesgo).
  - Todo page de quema rápida lleva postmortem.

### LogisticsLatenciaP95FueraDeSLO

**Severidad:** ticket (p95 sobre el objetivo 15 min) / page `LogisticsLatenciaP95Critica` (al doble, 10 min),
por clase (`slo_class`: `app_read`, `app_write`, `tracking_public`, `admin`).

- **Síntoma:** la plataforma responde lento para ese tipo de request.
- **Diagnóstico:**
  1. Qué rutas son lentas:

     ```promql
     topk(10, histogram_quantile(0.95, sum by (le, route) (rate(logistics_http_request_duration_seconds_bucket[5m]))))
     ```

  2. Consultas lentas: `docker compose exec -T app php artisan logistics:db:slow-queries --limit=20 --order=mean`.
  3. Saturación: paneles «Uso de conexiones», «Hit rate de caché» (una caída del hit rate manda todo a la
     base), «Lag de réplica» y «Duración de spans».
  4. ¿Subió el tráfico? Panel «RPS por puerta».
- **Acción:**
  - Consulta sin índice / plan malo: `php artisan logistics:db:maintain` (ANALYZE) y, si hace falta, índice
    por migración aditiva.
  - Caché fría tras un reinicio de Valkey: se recupera sola; vigilar.
  - Más tráfico del que el tier aguanta: escalar réplicas de app (ver [ha-runbook.md](ha-runbook.md) §6–7).
  - Si el objetivo quedó irreal tras un cambio de arquitectura: recalibrar ([slo.md §3.3](slo.md)), nunca
    silenciar la alerta.

### LogisticsColaSinDrenar

**Severidad:** ticket (> 100 jobs sin bajar 15 min) / page `LogisticsColaSaturada` (> 1000, 10 min), por cola.

- **Síntoma:** el trabajo asíncrono de esa cola se atrasa: notificaciones tardías, SAP sin enterarse,
  planificación que no termina.
- **Diagnóstico:**
  1. `docker compose exec -T app php artisan logistics:queue:health`: profundidad de todas las colas.
  2. Panel «Latido de workers por cola»: ¿el worker de esa cola procesa?
  3. `docker compose exec -T app php artisan queue:failed`: ¿los jobs fallan y se reencolan en bucle?
  4. Logs del worker: `docker compose logs --tail=200 queue-worker` (staging: `worker`).
- **Acción:**
  - Worker caído o colgado: ver `LogisticsWorkerSinActividad`.
  - Destino externo lento (SAP/FCM/SMTP): el backlog drena solo cuando el destino vuelve; no vaciar la cola.
    Para SAP, `php artisan logistics:sap:retry-notifications` reencola las que ya cumplieron su backoff.
  - Volumen legítimo: escalar workers de esa cola ([ha-runbook.md](ha-runbook.md) §7.2). El scheduler debe
    seguir en **una sola** instancia.

### LogisticsWorkerSinActividad

**Severidad:** page (colas `default`/`outbox`/`notifications`/`sap`, > 5 min) / ticket (`planning`, > 30 min).

- **Síntoma:** hay jobs esperando y el worker de esa cola no da señales (latido viejo, o `-1` si nunca se lo
  vio). El latido no avanza mientras corre un job: en `planning` un plan largo es normal hasta 30 min.
- **Diagnóstico:**
  1. `docker compose ps queue-worker` (staging: `worker`): ¿`running`? ¿se reinicia en bucle?
  2. `docker compose logs --tail=200 queue-worker`: excepción al arrancar, OOM, job colgado.
  3. `php artisan logistics:queue:health` para confirmar que la cola no baja.
- **Acción:**
  - Reciclar los workers de forma limpia (terminan el job en curso):
    `docker compose exec -T app php artisan queue:restart`.
  - Si no reaccionan: `docker compose restart queue-worker` (staging:
    `docker compose -p logistics-staging restart worker`).
  - Job que cuelga siempre: identificarlo en `queue:failed`, corregir la causa y recién entonces
    `php artisan queue:retry <id>`.

### LogisticsSchedulerSinLatido

**Severidad:** page · **Condición:** último tick del scheduler hace más de 3 min, o latido en `-1` (nunca
hizo tick) durante 5 min.

- **Síntoma:** no corre ninguna tarea programada. La más crítica es `logistics:outbox:pump` (cada minuto):
  sin ella el outbox se **congela en silencio** —no hay tracking nuevo, ni notificaciones, ni SAP— y la API
  sigue respondiendo 200. Es un incidente que el proyecto ya vivió.
- **Diagnóstico:**
  1. `docker compose ps scheduler` (staging: proceso `schedule:work` dentro de `worker`).
  2. `docker compose logs --tail=100 scheduler`.
  3. Panel «Edad del pendiente más viejo»: ¿ya está creciendo?
- **Acción:**
  1. Reiniciar el scheduler: `docker compose restart scheduler` (staging:
     `docker compose -p logistics-staging restart worker`).
  2. Limpiar mutex colgados de `withoutOverlapping` en Valkey (un proceso matado a mitad deja el lock y el
     scheduler **saltea** esa tarea hasta que expira):
     `docker compose exec -T app php artisan schedule:clear-cache`.
     (El pump del outbox no usa ese mutex justamente por este incidente, y el entrypoint ya lo limpia en
     cada arranque; el comando sirve para las demás tareas.)
  3. Drenar el outbox a mano mientras tanto: `docker compose exec -T app php artisan logistics:outbox:pump`.
  4. Verificar que el latido volvió (panel «Latido del scheduler») y que la edad del outbox baja.

### LogisticsFailedJobsCreciendo

**Severidad:** ticket · **Condición:** más de 10 jobs nuevos en `failed_jobs` en 15 min.

- **Síntoma:** jobs que agotan sus intentos.
- **Diagnóstico:** `docker compose exec -T app php artisan queue:failed` → cola y excepción dominantes.
- **Acción:** corregir la causa (config de un destino, bug, dato inválido) y reintentar:
  `php artisan queue:retry <id>` o `php artisan queue:retry all`. No hacer `queue:flush` sin entender qué se
  pierde.

### LogisticsOutboxAtrasado

**Severidad:** ticket (pendiente más viejo > 5 min) / page `LogisticsOutboxCongelado` (> 15 min).

- **Síntoma:** los eventos de dominio no llegan a destino: el tracking del cliente no avanza, no salen
  notificaciones, SAP no recibe.
- **Diagnóstico** (en este orden, de más a menos probable):
  1. Scheduler: panel «Latido del scheduler» / alerta `LogisticsSchedulerSinLatido`.
  2. Worker de la cola `outbox`: panel «Latido de workers por cola» y `php artisan logistics:queue:health`.
  3. Estado del outbox en la base:

     ```bash
     docker compose exec -T postgres psql -U logistics -d logistics -c \
       "SELECT status, count(*), min(available_at) AS mas_viejo FROM outbox_messages WHERE status IN ('pending','failed','dead_lettered') GROUP BY status;"
     ```

  4. Si hay muchos `failed`: `last_error` dominante.

     ```bash
     docker compose exec -T postgres psql -U logistics -d logistics -c \
       "SELECT event_name, left(last_error, 120) AS error, count(*) FROM outbox_messages WHERE status = 'failed' GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 10;"
     ```

- **Acción:**
  - Scheduler caído: seguir `LogisticsSchedulerSinLatido`.
  - Empujar a mano: `docker compose exec -T app php artisan logistics:outbox:pump` (encola a los workers) o,
    si los workers no están, el despacho sincrónico de respaldo
    `php artisan logistics:outbox:relay --limit=200`.
  - Después de un congelamiento largo, envíos que quedaron atascados en el tracking se reproyectan con
    `php artisan logistics:tracking:reconcile-from-execution` (sin `--force` simula; con `--force` aplica;
    es idempotente).

### LogisticsOutboxDeadLetter

**Severidad:** ticket (> 0 durante 5 min) / page `LogisticsOutboxDeadLetterCreciendo` (+20 en 30 min).

- **Síntoma:** hay eventos que agotaron sus reintentos. **No se reintentan solos**: algún sistema externo o
  una proyección quedó sin enterarse.
- **Diagnóstico:**

  ```bash
  docker compose exec -T postgres psql -U logistics -d logistics -c \
    "SELECT event_name, left(last_error, 160) AS error, count(*), min(created_at) AS desde FROM outbox_messages WHERE status = 'dead_lettered' GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 20;"
  ```

  También en el admin: recurso de mensajes del outbox, filtrando por estado dead-lettered.
- **Acción:**
  1. Corregir la causa (credencial de un destino, payload que un reactor no acepta, destino caído).
  2. Reintentar cada mensaje con la acción *Retry* del recurso del outbox en el admin (o el endpoint
     `POST …/outbox/messages/{id}/retry` del contrato). Descartar (*Discard*) solo lo que se confirme
     que no debe salir.
  3. Si el dead-letter era de tracking, verificar con
     `php artisan logistics:tracking:reconcile-from-execution` (simulación) que no quedaron envíos atascados.
  4. Si crece rápido (page): frenar la causa **antes** de reintentar, o los reintentos vuelven a caer.

### LogisticsConexionesDBAltas

**Severidad:** ticket (> 80 % de `max_connections`, 5 min) / page `LogisticsConexionesDBAgotandose` (> 95 %, 2 min).

- **Síntoma:** PostgreSQL se acerca a su tope de conexiones; al llegar, los requests nuevos fallan.
- **Diagnóstico:**

  ```bash
  docker compose exec -T postgres psql -U logistics -d logistics -c \
    "SELECT state, usename, application_name, count(*) FROM pg_stat_activity GROUP BY 1, 2, 3 ORDER BY 4 DESC;"
  ```

  - Muchas `idle in transaction`: una transacción abierta que no cierra (fuga).
  - Muchas conexiones que no vienen de PgBouncer: algo se conecta directo a Postgres salteando el pooler.
  - `logistics:db:slow-queries`: consultas largas que retienen conexiones.
- **Acción:**
  - Cortar las sesiones colgadas (primero las `idle in transaction` viejas):

    ```bash
    docker compose exec -T postgres psql -U logistics -d logistics -c \
      "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE state = 'idle in transaction' AND state_change < now() - interval '5 minutes';"
    ```

  - Revisar el dimensionado: la suma de pools de PgBouncer (`PGBOUNCER_DEFAULT_POOL_SIZE` × pares
    usuario/base + reserva) debe quedar holgada bajo `PG_MAX_CONNECTIONS`.
  - No subir `PG_MAX_CONNECTIONS` sin RAM proporcional: el pooling es la respuesta, no más conexiones.

### LogisticsLagReplicaAlto

**Severidad:** ticket (> 32 MB, 5 min) / page `LogisticsLagReplicaCritico` (> 512 MB, 10 min), por standby.

- **Síntoma:** las lecturas que van a la réplica devuelven datos viejos; con lag crítico la réplica deja de
  ser candidata a failover.
- **Diagnóstico:**
  1. `docker compose exec -T app php artisan logistics:replication:lag` (lag en bytes y tiempo por standby).
     Si la métrica marca 0 fijo pero el comando muestra lag, falta el rol `pg_monitor` (ver §1.4).
  2. ¿Hay una escritura masiva en el primario (backfill, prune, reindex)? ¿El standby está vivo
     (`docker compose ps postgres-standby`) y con disco?
- **Acción:**
  - Carga masiva: pausarla o hacerla por lotes más chicos; el lag se recupera solo.
  - Lag crítico: sacar las lecturas de la réplica (quitar `DB_READ_HOST` para que todo vaya al primario) hasta
    que se ponga al día.
  - Standby roto: reconstruirlo desde el primario ([ha-runbook.md](ha-runbook.md) §4). **No** promover una
    réplica con lag alto.

### LogisticsPostgresCaido

**Severidad:** page · **Condición:** `pg_up = 0` hace 1 min (el exporter no puede conectarse).

- **Síntoma:** sin base no hay plataforma: 5xx generalizados y `health` en 503.
- **Diagnóstico:** `docker compose ps postgres`, `docker compose logs --tail=100 postgres` (disco lleno,
  OOM, corrupción), `df -h` en el host.
- **Acción:**
  - Contenedor caído: `docker compose up -d postgres pgbouncer`, y cuando esté `healthy`, `app`.
  - Con topología HA y el primario irrecuperable: failover manual de [ha-runbook.md](ha-runbook.md) §4.3
    (verificar el lag antes de promover).
  - Pérdida de datos: restaurar el último bundle verificado —
    `php artisan backup:verify <bundle>` y luego `php artisan backup:restore <bundle>`
    ([backup-restore.md](backup-restore.md)). Nunca `migrate:fresh` ni borrar el volumen.
  - Si solo falla el exporter (la app está sana): revisar `OBS_PG_USER`/`OBS_PG_PASSWORD`.

### LogisticsCacheMemoriaAlta

**Severidad:** ticket · **Condición:** Valkey sobre el 90 % de `maxmemory` durante 30 min (requiere el
exporter de Valkey). La métrica es de **toda** la instancia, no solo de la caché.

- **Síntoma:** una caché LRU llena es normal un rato. Sostenida, el riesgo es que no quede lugar para las
  claves **sin TTL** (colas, sesiones, locks), que `volatile-lru` no puede desalojar: Valkey empieza a
  rechazar escrituras y se pierden jobs.
- **Diagnóstico:**

  ```bash
  docker compose exec -T valkey valkey-cli info memory | grep -E "used_memory_human|maxmemory_human|mem_fragmentation_ratio"
  docker compose exec -T valkey valkey-cli info stats  | grep -E "evicted_keys|keyspace_hits|keyspace_misses"
  docker compose exec -T app php artisan logistics:queue:health
  ```

  ¿Lo que ocupa es caché (evicciones altas, colas chicas) o son colas acumuladas (colas grandes)?
- **Acción:**
  - Colas acumuladas: es un problema de workers, seguir `LogisticsColaSinDrenar`.
  - Caché legítimamente grande: subir `VALKEY_MAXMEMORY` (con RAM disponible en el host) y recrear `valkey`.
  - Claves de caché sin TTL o con TTL excesivo: corregir en el código que las escribe.

### LogisticsValkeyCaido

**Severidad:** page · **Condición:** `redis_up = 0` hace 1 min.

- **Síntoma:** sin Valkey no hay caché, sesiones del admin, colas ni locks: logins caídos y jobs sin encolar.
- **Diagnóstico:** `docker compose ps valkey`, `docker compose logs --tail=100 valkey`,
  `docker compose exec -T valkey valkey-cli ping`.
- **Acción:**
  - `docker compose up -d valkey`; con AOF (`--appendonly yes`) las colas y sesiones se recuperan del disco.
  - Con Sentinel: confirmar que hubo failover y que la app apunta al nuevo primario
    ([ha-runbook.md](ha-runbook.md) §5).
  - Al volver: `php artisan schedule:clear-cache` (mutex que hayan quedado) y `php artisan queue:restart`.

### LogisticsExporterCaido

**Severidad:** ticket · **Condición:** Prometheus no puede scrapear `postgres-exporter` o `valkey-exporter`
hace 5 min.

- **Síntoma:** se pierde visibilidad de ese servicio. **No** implica que PostgreSQL o Valkey estén caídos.
- **Diagnóstico:** `docker compose -f docker-compose.yml -f .docker/compose/docker-compose.observability.yml ps`
  y los logs del exporter.
- **Acción:** recrear el exporter
  (`… up -d --force-recreate postgres-exporter valkey-exporter`); si falla la autenticación, revisar
  `OBS_PG_*` / `OBS_REDIS_*`.

---

## 4. Mantenimiento de las reglas

- Cambiar un umbral o un objetivo: editar `rules.yml` / `alerts.yml`, recargar Prometheus
  (`curl -X POST http://localhost:9190/-/reload`) y actualizar [slo.md](slo.md) en el mismo cambio.
- Alerta nueva: lleva `for:`, `severity` (`page`/`ticket`), `service`, `layer`, anotaciones en español
  (`summary`, `description` con qué mirar) y `runbook_url` apuntando a una sección de **este** documento.
- Validar antes de commitear (sin levantar nada):

  ```bash
  docker run --rm -v "$PWD/.docker/observability:/cfg:ro" --entrypoint promtool prom/prometheus:v3.5.0 check rules /cfg/rules.yml /cfg/alerts.yml
  ```

- Silenciar durante un mantenimiento anunciado: crear un *silence* en Alertmanager
  (http://localhost:9193/#/silences) con vencimiento; nunca comentar la regla.
