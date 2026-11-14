# Runbook — Alta disponibilidad (Ola 4)

> Guía operativa para habilitar y operar la topología HA de la plataforma logística.
> Complementa `docs/deploy.md` y el roadmap de alta disponibilidad.

---

## 1. Topología HA

```
                    ┌─────────────────────────────┐
                    │         Nginx (LB)           │
                    │  upstream php_backend {      │
                    │    server app-1:9000;        │
                    │    server app-2:9000;        │
                    │  }                           │
                    └──────────┬──────────────────┘
                               │
               ┌───────────────┼───────────────┐
               ▼               ▼               ▼
         ┌──────────┐   ┌──────────┐   ┌──────────┐
         │  App-1   │   │  App-2   │   │ Workers  │
         │ PHP-FPM  │   │ PHP-FPM  │   │supervisor│
         └────┬─────┘   └────┬─────┘   └────┬─────┘
              │              │              │
              └──────┬───────┘              │
                     ▼                      │
         ┌───────────────────┐    ┌─────────┴─────────┐
         │    PgBouncer      │    │     PgBouncer      │
         │   (escritura)     │    │     (lectura)      │
         │    :6432          │    │      :6432         │
         └────────┬──────────┘    └────────┬──────────┘
                  ▼                        ▼
         ┌──────────────┐         ┌──────────────┐
         │  PostgreSQL   │───WAL──│  PostgreSQL   │
         │  (primario)   │ stream │  (standby)    │
         └──────────────┘         └──────────────┘

         ┌──────────────┐   ┌──────────────┐
         │    Valkey     │───│    Valkey     │
         │  (primario)   │   │  (réplica)   │
         └──────┬───────┘   └──────────────┘
                │
    ┌───────────┼───────────┐
    ▼           ▼           ▼
  Sentinel  Sentinel  Sentinel
    (1)       (2)       (3)
```

## 2. Levantar la topología HA

```bash
# Desde el directorio raíz del proyecto:
docker compose -f docker-compose.yml -f docker-compose.ha.yml up -d
```

Esto agrega:
- `postgres-standby`: réplica de lectura (hot standby) con streaming replication.
- `pgbouncer-read`: pool de conexiones dedicado al standby para el read/write split (Ola 3).
- `valkey-replica`: réplica de Valkey.
- `valkey-sentinel-1`, `2`, `3`: quórum de Sentinels para failover automático de Valkey.
- `app` con `deploy.replicas: 2`: dos instancias PHP-FPM.

## 3. Sesión del admin

La sesión del admin Filament **ya está en Valkey** (`config/session.php` → `driver: redis`,
conexión `session` en DB 2). **No se necesita cambiar nada** para que funcione con múltiples
instancias de app: cualquier instancia lee la misma sesión desde Valkey.

## 4. PostgreSQL HA

### 4.1 Streaming replication

El standby (`postgres-standby`) se inicializa con `pg_basebackup` del primario y recibe
WAL en streaming. La primera vez es lento (copia toda la base); después solo recibe los
incrementales.

### 4.2 Monitorear el lag

```bash
docker compose exec app php artisan logistics:replication:lag
```

Muestra el lag en bytes y milisegundos de cada standby conectado. El scheduler lo corre
cada 5 minutos automáticamente.

### 4.3 Failover manual

Si el primario cae y hay que promover el standby:

```bash
# 1. Verificar que el standby está al día.
docker compose exec app php artisan logistics:replication:lag

# 2. Promover el standby a primario.
docker compose exec postgres-standby pg_ctl promote -D /var/lib/postgresql/data

# 3. Apuntar PgBouncer (escritura) al nuevo primario.
#    Cambiar DB_HOST=postgres-standby en el .env y reiniciar pgbouncer.

# 4. Reconstruir un nuevo standby desde el nuevo primario.
```

Para failover AUTOMÁTICO en producción, usar **Patroni** (líder electo por etcd/consul) o
un servicio gestionado con HA (RDS, Cloud SQL, etc.). El compose HA sirve de referencia.

## 5. Valkey HA (Sentinel)

### 5.1 Cómo funciona

Tres Sentinels monitorean el Valkey primario. Si el primario no responde en 5s
(`down-after-milliseconds`), los Sentinels votan (quórum de 2) y promueven la réplica.

### 5.2 Conectar la app a Sentinel

Para que la app descubra el primario vía Sentinel (en vez de apuntar a una IP fija),
agregar en el `.env` de producción:

```dotenv
REDIS_CLIENT=predis
REDIS_SENTINEL_SERVICE=dismac-valkey
REDIS_SENTINEL_HOST=valkey-sentinel-1,valkey-sentinel-2,valkey-sentinel-3
REDIS_SENTINEL_PORT=26379
```

Y en `config/database.php`, la conexión `default` (y las demás) se configuran con el array
de Sentinels. **No se necesita cambiar código**: Laravel/predis descubren el primario
automáticamente.

## 6. Deploy sin downtime

### 6.1 Pre-deploy

```bash
docker compose exec app php artisan logistics:pre-deploy
```

Verifica:
- Conectividad a PostgreSQL y Valkey.
- Señaliza `queue:restart` a los workers.
- Limpia caches del scheduler.
- Reporta migraciones pendientes.

### 6.2 Rolling deploy

```bash
# 1. Pre-deploy
docker compose exec app php artisan logistics:pre-deploy

# 2. Build de la imagen nueva
docker compose build app

# 3. Rolling restart (una instancia a la vez)
docker compose up -d --no-deps --scale app=2 app

# 4. Verificar salud
curl -s http://localhost:8110/api/v1/health | jq .
```

Con `deploy.replicas: 2` y `restart_policy.condition: on-failure`, Docker recrea las
instancias de a una (rolling). Nginx rutea al backend sano mientras el otro reinicia.

### 6.3 Graceful shutdown

PHP-FPM recibe `SIGQUIT` (drenado graceful) en vez de `SIGTERM` (corte inmediato):
- En el Dockerfile de prod: `STOPSIGNAL SIGQUIT`.
- `stop_grace_period: 30s` en el compose (tiempo para drenar requests en vuelo).

Los workers de cola ya drenan por diseño: `queue:restart` les señaliza, terminan el job
actual y mueren. Supervisor los reemplaza con la imagen nueva.

## 7. Workers resilientes

### 7.1 Monitoreo de profundidad de colas

```bash
docker compose exec app php artisan logistics:queue:health
```

El scheduler lo corre cada 5 minutos. Si alguna cola excede 500 jobs, emite un warning.

### 7.2 Escalar workers

```bash
# Vertical: más procesos por cola en supervisord.conf
# Horizontal: más réplicas del contenedor de workers
docker compose up -d --scale queue-worker=4
```

**Cuidado**: el scheduler debe correr en **una sola** instancia. Con múltiples workers,
deshabilitar el programa `scheduler` de supervisor en las réplicas extra.

## 8. Backups probados

### 8.1 Simulacro semanal (automático)

El scheduler corre `logistics:restore-drill` los domingos a las 04:00. Verifica:
- El archivo de dump existe y es legible.
- `pg_restore --list` lo parsea sin error (formato válido).
- Las tablas críticas aparecen en el TOC.

### 8.2 Simulacro manual

```bash
# Crear un backup fresco
docker compose exec app php artisan backup:create

# Verificar que es restaurable
docker compose exec app php artisan logistics:restore-drill
```

### 8.3 Restore real (ante desastre)

```bash
docker compose exec app php artisan backup:restore <path-al-dump>
```

Ver `docs/backup-restore.md` y el skill `db-backup` para el procedimiento completo.

## 9. Checklist de HA

- [ ] Topología HA levantada con el compose override.
- [ ] Standby conectado y recibiendo WAL (lag < 16 MB).
- [ ] PgBouncer de lectura sirviendo las queries del pool `read`.
- [ ] Valkey Sentinels corriendo (3 instancias, quórum de 2).
- [ ] Sesión del admin en Valkey (no en archivo local).
- [ ] Pre-deploy exitoso (`logistics:pre-deploy`).
- [ ] Rolling deploy sin downtime verificado.
- [ ] Workers señalizados y reciclados tras el deploy.
- [ ] Queue health check corriendo cada 5 minutos.
- [ ] Restore drill semanal pasando.
- [ ] Replication lag monitoreado cada 5 minutos.
