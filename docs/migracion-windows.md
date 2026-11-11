# Migración de WSL → Windows (Docker Desktop) sin perder datos

Guía para mover el proyecto completo (código + Docker + volúmenes + red + base de datos) desde el
filesystem de WSL a `C:\Users\ehhuallata\Documents\APP`, conservando **todos los datos** y con el
rendimiento lo más cerca posible del de WSL.

## Por qué NO se pierden datos

- El `docker-compose.yml` fija **`name: dismac-logistics`** y la red **`dismac_lg_network`** de forma
  explícita (no dependen del nombre de la carpeta). Al mover la carpeta, Docker Compose **reutiliza los
  mismos volúmenes y la misma red** — nada se recrea.
- La base de datos vive en el **volumen nombrado `dismac-logistics_postgres_data`**, administrado por
  Docker Desktop **fuera de la carpeta del proyecto**. Copiar la carpeta ni lo roza.
- Aun así hacemos un **backup lógico** (`db:backup`) como red de seguridad antes de tocar nada.

## Por qué el rendimiento baja en NTFS (y cómo lo mitigamos)

Hoy es rápido porque el código está en el ext4 de WSL. En `C:\...\APP` (NTFS) el bind-mount del código
cruza la frontera WSL↔Windows y es más lento para PHP (miles de archivos). Mitigaciones ya incluidas:

- **`vendor/` y `node_modules/` ya son volúmenes nombrados** → el hot-path pesado NO cruza NTFS.
- **OPcache afinado** (`.docker/php/php.windows.ini`, vía el override `docker-compose.windows.yml`): elimina
  el `stat()` por archivo en cada request (el verdadero cuello de botella), con file-cache en RAM.
- **VirtioFS** (ajuste de Docker Desktop, abajo): el compartir archivos más rápido que el 9p viejo.

---

## Pre-requisitos (una sola vez)

1. **Docker Desktop** instalado y corriendo, con **WSL 2 engine** (Settings → General → "Use the WSL 2
   based engine") y la **integración WSL activada** para tu distro (Settings → Resources → WSL integration).
2. **VirtioFS activado**: Settings → Resources → File sharing → **VirtioFS** (más rápido que gRPC/9p).
3. **PowerShell** (viene con Windows). Opcional: Git para Windows.

Averiguá el nombre de tu distro WSL (lo vas a necesitar para la ruta `\\wsl$`):

```powershell
wsl -l -q
```

---

## Fase 0 — Backup de seguridad (con Docker corriendo, desde la distro WSL)

```bash
# En la terminal WSL, dentro del proyecto actual:
docker compose up -d                       # asegurate de que el stack esté arriba
docker compose exec -T app php artisan db:backup
#   → genera storage/app/backups/backup-logistics-<timestamp>.dump
```

Copiá ese `.dump` a un lugar seguro FUERA del proyecto, p. ej.:

```powershell
# En PowerShell (Windows). Reemplazá <DISTRO> por el nombre de tu distro.
New-Item -ItemType Directory -Force "C:\Users\ehhuallata\Documents\backups" | Out-Null
Copy-Item "\\wsl$\<DISTRO>\dismac\logistics\storage\app\backups\*.dump" "C:\Users\ehhuallata\Documents\backups\"
```

## Fase 1 — Apagar el stack SIN borrar volúmenes

```bash
# En WSL:
docker compose down        # ⚠️ NUNCA uses `-v`: eso borraría la base de datos.
```

## Fase 2 — Copiar el proyecto a C:\Users\ehhuallata\Documents\APP

Usá el script (recomendado) o robocopy a mano. El script detecta la distro, copia excluyendo lo pesado
y lo que se regenera, fija el override de Windows y levanta el stack:

```powershell
# En PowerShell, parado en cualquier lado:
\\wsl$\<DISTRO>\dismac\logistics\scripts\windows\migrate.ps1
# o, si ya copiaste el script, desde donde esté:
powershell -ExecutionPolicy Bypass -File .\migrate.ps1 -Distro <DISTRO>
```

Equivalente a mano con robocopy (excluye lo que se repuebla solo o es gigante):

```powershell
robocopy "\\wsl$\<DISTRO>\dismac\logistics" "C:\Users\ehhuallata\Documents\APP" /E /COPY:DAT /DCOPY:DAT /R:1 /W:1 `
  /XD vendor node_modules .git "storage\app\backups" ".docker\osrm\data" ".docker\nominatim\data" ".docker\tileserver\data" `
  /XF "*.log"
```

- `vendor` y `node_modules` NO se copian: se repueblan solos (volúmenes nombrados + composer/npm al arrancar).
- Los datos geo (`osrm/nominatim/tileserver`) son varios GB y opt-in (`--profile geo`): se regeneran con sus
  runbooks; no se copian.
- El `.env` SÍ se copia (robocopy copia dotfiles).

## Fase 3 — Activar el override de Windows (perf)

En `C:\Users\ehhuallata\Documents\APP\.env`, agregá esta línea (el script lo hace solo):

```env
COMPOSE_FILE=docker-compose.yml:.docker/compose/docker-compose.windows.yml
```

Así cada `docker compose ...` aplica el PHP afinado para NTFS automáticamente.

## Fase 4 — Levantar desde la nueva ubicación

```powershell
cd C:\Users\ehhuallata\Documents\APP
docker compose up -d --build
```

Como el nombre del proyecto (`dismac-logistics`) y la red (`dismac_lg_network`) están fijos, Compose
**reutiliza los volúmenes existentes**: la base de datos aparece intacta. El entrypoint corre
`composer install` y repuebla el volumen `vendor` (rápido, ya estaba poblado); el servicio `assets`
recompila el tema.

## Fase 5 — Verificar (cero pérdida + rendimiento)

```powershell
docker compose ps                                  # todo "healthy"
start http://localhost:8110                         # abre el admin
```

Conteo de control (debe coincidir con lo que tenías):

```powershell
docker compose exec -T app php artisan tinker --execute="echo DB::table('commerce_orders')->count().' orders / '.DB::table('shipments')->count().' shipments';"
```

Prueba de rendimiento (la 2ª corrida, con OPcache caliente, debe ser notablemente más rápida):

```powershell
Measure-Command { curl.exe -s -o NUL http://localhost:8110/admin/login }
Measure-Command { curl.exe -s -o NUL http://localhost:8110/admin/login }
```

## Fase 6 — Restore (SOLO si algo salió mal: DB vacía)

Si por cualquier motivo la base apareciera vacía (p. ej. se corrió `down -v` por error), restaurá el backup:

```powershell
# Copiá el .dump a la nueva ubicación y restaurá:
Copy-Item "C:\Users\ehhuallata\Documents\backups\backup-*.dump" "C:\Users\ehhuallata\Documents\APP\storage\app\backups\"
docker compose exec -T app php artisan db:restore storage/app/backups/backup-<timestamp>.dump --force
```

## Limpieza (opcional, al final y ya verificado)

Cuando todo funcione desde `C:\...\APP`, podés borrar la copia vieja en WSL (`/dismac/logistics`). **No
borres los volúmenes de Docker**: son los que tienen tus datos y los seguís usando desde la nueva carpeta.

---

## Notas de rendimiento

- Si querés que los cambios de código se reflejen al instante (en vez de ≤5 s), bajá
  `opcache.revalidate_freq` a `0` en `.docker/php/php.windows.ini` (a costa de algo de velocidad).
- Para depurar con Xdebug, poné `xdebug.mode = develop,debug` en ese mismo archivo y reiniciá `app`
  (`docker compose restart app`). Dejalo en `off` para el día a día: Xdebug penaliza mucho.
- El máximo rendimiento sigue siendo con el código en el ext4 de WSL; esta configuración es para acercarse
  lo más posible cuando el código vive en NTFS por decisión de ubicación.
