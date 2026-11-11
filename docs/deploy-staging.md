# Deploy automático de staging

Staging se despliega solo, en el servidor Ubuntu de Oracle Cloud que tiene el self-hosted runner de
GitHub Actions de este repo (labels `self-hosted, docker`). Complementa [deploy.md](deploy.md), la
guía general de staging y producción.

## Qué dispara el deploy

| Evento | Workflow | Qué hace |
|---|---|---|
| Push a `staging` (= merge de un PR hacia `staging`) | [staging-deploy.yml](../.github/workflows/staging-deploy.yml) | Build, migraciones, deploy y smoke test |
| Manual (Actions → *Staging deploy* → *Run workflow*) | ídem | Redespliega sin commit nuevo |
| PR abierto/actualizado hacia `staging` | [staging-pr-check.yml](../.github/workflows/staging-pr-check.yml) | **Solo build** de las imágenes en el servidor, sin desplegar |
| PR o push a `dev`/`staging`/`production` | [ci.yml](../.github/workflows/ci.yml) (ya existía) | Pint, PHPStan 8, Pest con cobertura ≥75%, en `ubuntu-latest` |

**Solo se despliega desde `staging`.** El job tiene el candado `if: github.ref == 'refs/heads/staging'`:
un *Run workflow* lanzado eligiendo otra rama se omite sin tocar el servidor. Pushes a `dev`,
`production` o `feature/*` nunca despliegan.

Un push nuevo a `staging` **cancela** el deploy en curso (`concurrency` con `cancel-in-progress`). Es
seguro: el deploy es idempotente y la corrida siguiente deja el estado completo.

Los PR desde **forks** no se construyen en el servidor: ejecutarían código ajeno en él.

## Qué hace cada deploy

[scripts/staging/deploy.sh](../scripts/staging/deploy.sh) sobre
[docker-compose.staging.yml](../.docker/compose/docker-compose.staging.yml), con el proyecto compose
fijo `logistics-staging`:

1. **Preflight**: versiones de Docker y Compose, disco libre y validación del compose.
2. **Build** de las imágenes `dismac/logistics-app:staging` (target `prod`: PHP-FPM, composer
   `--no-dev`, assets de Vite y Filament) y `dismac/logistics-web:staging` (target `web`: nginx con
   `public/` horneado). Todo el toolchain (PHP, Composer, Node) vive dentro de Docker; el host solo
   necesita Docker.
3. **Infra**: levanta `postgres`, `pgbouncer` y `valkey`, y espera a que estén healthy.
4. **Migraciones**: `php artisan migrate --force`, una sola vez, en un contenedor descartable con
   la imagen nueva.
5. **Deploy**: `docker compose -p logistics-staging up -d --build --remove-orphans --wait`, que
   espera a que **todos** los servicios queden healthy.
6. **Smoke test**: `GET /up` = 200, `GET /api/v1/health` = 200 con `"status":"ok"` (PostgreSQL con
   PostGIS y Valkey) y `GET /admin/login` = 200. Si algo falla, el job falla y vuelca el estado y
   los últimos logs de cada servicio.
7. **Limpieza** (siempre, aunque falle): borra el env con secretos, `docker image prune -f`, la caché
   de build de más de 7 días y el workspace.

La primera corrida crea todo desde cero. Las siguientes recrean solo lo que cambió y **conservan los
volúmenes**, así que la base de staging persiste entre deploys.

### Servicios

| Servicio | Imagen | Rol |
|---|---|---|
| `web` | `dismac/logistics-web:staging` | nginx, **único puerto publicado** |
| `app` | `dismac/logistics-app:staging` | PHP-FPM |
| `worker` | `dismac/logistics-app:staging` | supervisor: colas `default`, `outbox`, `notifications`, `planning` y el **único** scheduler |
| `postgres` | `postgis/postgis:17-3.5-alpine` | base `logistics` (PostGIS lo crea el init de la imagen) |
| `pgbouncer` | `edoburu/pgbouncer` | pool de conexiones (la app conecta acá) |
| `valkey` | `valkey/valkey:8-alpine` | cache, colas, sesiones y locks |

Quedan **fuera** de staging, a propósito: OSRM, VROOM, Nominatim y tileserver (datos geo de varios
GB; la app degrada sola a ruteo por cercanía y al geocoder neutro), MinIO (disco `local`), Soketi
(`BROADCAST_CONNECTION=log`) y el backup programado. Se suman cuando staging los necesite.

## Secrets y variables a cargar

En GitHub: **Settings → Secrets and variables → Actions**.

### Secrets (obligatorios)

| Secret | Qué es | Cómo generarlo |
|---|---|---|
| `STAGING_APP_KEY` | Clave de Laravel (`base64:...`) | `docker compose exec -T app php artisan key:generate --show` |
| `STAGING_DB_PASSWORD` | Contraseña de PostgreSQL de staging | Cualquier cadena larga aleatoria |

> **No cambiar `STAGING_DB_PASSWORD` después del primer deploy.** PostgreSQL fija la contraseña al
> crear el volumen; si el secret cambia, la app ya no puede conectarse. Para rotarla, primero
> cambiarla dentro de PostgreSQL (`ALTER USER logistics PASSWORD '...'`) y después el secret.

### Secret opcional

| Secret | Qué es |
|---|---|
| `STAGING_ENV_EXTRA` | Líneas `CLAVE=valor` adicionales, una por línea: SAP, FCM, S3, MAIL, `LABELS_SIGNING_KEY`, `MAP_TILE_URL`, etc. **Reemplazan** a los defaults de staging (por ejemplo `MAIL_MAILER=smtp` pisa el `log` por defecto). Las líneas vacías y los comentarios se ignoran. Ver `.env.example`. |

### Variables (no secretas, pestaña *Variables*)

| Variable | Default | Qué es |
|---|---|---|
| `STAGING_HTTP_PORT` | `8110` | Puerto del host donde nginx publica la app |
| `STAGING_APP_URL` | `http://localhost:<puerto>` | URL pública (por ejemplo `http://<IP-pública>:8110`). Se usa en `APP_URL` y como enlace del entorno en GitHub |

Defaults de staging (los genera [scripts/staging/render-env.sh](../scripts/staging/render-env.sh)):
`APP_ENV=staging`, `APP_DEBUG=false`, `OPTIMIZE_ON_BOOT=true`, logs JSON a stderr, `MAIL_MAILER=log`,
`BROADCAST_CONNECTION=log`, `FILESYSTEM_DISK=local`, colas, cache y sesiones en Valkey.

## Puerto y acceso

La app queda en **`http://<IP-pública-del-servidor>:8110`** (o el valor de `STAGING_HTTP_PORT`):
admin en `/admin`, API en `/api/v1`.

El workflow **no abre puertos en la nube**. Hay que agregar una regla de ingreso TCP para ese puerto
en la **Security List** (o el NSG) de la VCN en Oracle Cloud. PostgreSQL, PgBouncer y Valkey **no**
se publican al host: solo son accesibles dentro de la red Docker de staging.

## Logs y operación

Desde el servidor (usuario `ubuntu`):

```bash
# Logs en vivo de todo staging
docker compose -p logistics-staging logs -f

# Solo un servicio (app, worker, web, postgres, pgbouncer, valkey)
docker compose -p logistics-staging logs -f worker

# Estado de los contenedores
docker compose -p logistics-staging ps

# Artisan dentro de la app
docker exec -it logistics-staging-app-1 php artisan about
docker exec -it logistics-staging-app-1 php artisan queue:failed
```

El log de cada deploy está en la pestaña **Actions** del repo, en el job *Build + deploy*, con un
grupo por paso. Si falla, al final aparecen el estado y los últimos logs de cada servicio.

### Primer usuario del admin

La base de staging nace vacía (solo esquema). Para entrar al panel, sembrar los datos base:

```bash
docker exec -it logistics-staging-app-1 php artisan db:seed --force
```

Los seeders son idempotentes y crean el usuario `admin@dismac.test` con la contraseña de desarrollo.
**Cambiarla** después de entrar, porque staging queda expuesto en un puerto público.

### Bajar staging

```bash
docker compose -p logistics-staging down        # detiene y borra contenedores; CONSERVA la base
```

Nunca usar `down -v` sin un backup previo: borra los volúmenes, y con ellos la base de staging.
