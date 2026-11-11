#Requires -Version 5.1
<#
.SYNOPSIS
    Migra el proyecto logistics desde el filesystem de WSL a C:\Users\ehhuallata\Documents\APP
    (Docker Desktop) sin perder datos, y lo levanta con el tuning de rendimiento para Windows.

.DESCRIPTION
    - Copia el código desde \\wsl$\<distro>\dismac\logistics excluyendo lo que se repuebla solo
      (vendor, node_modules) y lo gigante/opt-in (datos geo).
    - Activa el override docker-compose.windows.yml (OPcache afinado para NTFS) vía COMPOSE_FILE en .env.
    - Levanta el stack. Como el nombre del proyecto y la red están fijos en el compose, REUTILIZA los
      volúmenes existentes de Docker Desktop: la base de datos aparece intacta.

    REQUISITOS PREVIOS (hacelos antes de correr esto, ver docs/migracion-windows.md):
      1. docker compose exec -T app php artisan db:backup   (backup de seguridad, desde WSL)
      2. docker compose down                                (apagar el stack SIN -v)
      3. Docker Desktop corriendo, con WSL2 engine + VirtioFS.

.PARAMETER Distro
    Nombre de la distro WSL (ver `wsl -l -q`). Si se omite, toma la primera.

.PARAMETER Dest
    Carpeta destino en Windows. Default: C:\Users\ehhuallata\Documents\APP

.PARAMETER SkipCopy
    No copiar; solo (re)activar el override y levantar (útil si ya copiaste).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\migrate.ps1 -Distro Ubuntu
#>
[CmdletBinding()]
param(
    [string]$Distro,
    [string]$Dest = 'C:\Users\ehhuallata\Documents\APP',
    [switch]$SkipCopy
)

$ErrorActionPreference = 'Stop'

function Info($m) { Write-Host "[migrate] $m" -ForegroundColor Cyan }
function Ok($m)   { Write-Host "[migrate] $m" -ForegroundColor Green }
function Warn($m) { Write-Host "[migrate] $m" -ForegroundColor Yellow }

# --- 1. Resolver la distro y la ruta origen ---
if (-not $Distro) {
    $Distro = (wsl.exe -l -q | Where-Object { $_ -and $_.Trim() } | Select-Object -First 1).Trim()
    Warn "Distro no indicada; uso la primera: '$Distro' (si no es la correcta, pasá -Distro <nombre>)."
}
$Source = "\\wsl`$\$Distro\dismac\logistics"
if (-not (Test-Path $Source)) {
    throw "No existe el origen '$Source'. Verificá el nombre de la distro con: wsl -l -q"
}
Info "Origen:  $Source"
Info "Destino: $Dest"

# --- 2. Copiar (robocopy), excluyendo lo que se repuebla o es gigante ---
if (-not $SkipCopy) {
    Info "Copiando el proyecto (robocopy)…"
    $excludeDirs = @('vendor', 'node_modules', '.git', 'storage\app\backups',
                     '.docker\osrm\data', '.docker\nominatim\data', '.docker\tileserver\data')
    $roboArgs = @($Source, $Dest, '/E', '/COPY:DAT', '/DCOPY:DAT', '/R:1', '/W:1', '/NFL', '/NDL', '/NP',
                  '/XD') + $excludeDirs + @('/XF', '*.log')
    & robocopy.exe @roboArgs | Out-Null
    # robocopy: exit 0-7 = éxito; 8+ = error.
    if ($LASTEXITCODE -ge 8) { throw "robocopy falló con código $LASTEXITCODE." }
    Ok "Copia completa (código robocopy $LASTEXITCODE)."
} else {
    Warn "SkipCopy: no se copió nada."
}

# --- 3. Activar el override de Windows en el .env del destino ---
$envPath = Join-Path $Dest '.env'
if (-not (Test-Path $envPath)) { throw "No se encontró $envPath (¿se copió el .env?)." }
$composeLine = 'COMPOSE_FILE=docker-compose.yml:.docker/compose/docker-compose.windows.yml'
$envContent = Get-Content $envPath -Raw
if ($envContent -notmatch '(?m)^\s*COMPOSE_FILE=') {
    Add-Content -Path $envPath -Value "`r`n# Override de rendimiento para Windows (ver docs/migracion-windows.md)`r`n$composeLine"
    Ok "Agregué COMPOSE_FILE al .env (override de Windows activado)."
} else {
    Warn "Ya existe una línea COMPOSE_FILE en el .env; no la toco. Verificá que incluya el override de Windows:"
    Warn "  $composeLine"
}

# --- 4. Levantar el stack desde la nueva ubicación ---
Push-Location $Dest
try {
    Info "Levantando el stack (docker compose up -d --build)…"
    docker compose up -d --build
    if ($LASTEXITCODE -ne 0) { throw "docker compose up falló (código $LASTEXITCODE). ¿Docker Desktop está corriendo?" }

    Info "Estado de los servicios:"
    docker compose ps
    Ok "Stack levantado. Abrí http://localhost:8110 para verificar."
    Info "Conteo de control (debe coincidir con lo que tenías):"
    docker compose exec -T app php artisan tinker --execute="echo DB::table('commerce_orders')->count().' orders / '.DB::table('shipments')->count().' shipments';"
} finally {
    Pop-Location
}

Ok "Migración lista. Si la base apareciera vacía, restaurá el backup (ver Fase 6 del runbook)."
