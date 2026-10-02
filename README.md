# Dismac Logistics — plataforma logística

Backend, servicios y admin web de la plataforma logística de Dismac: despacho, última milla y red
logística completa (CD→cliente, CD→CD, CD→tienda, tienda→tienda, tienda→CD y recojo en tienda),
fuera del dominio SAP.

- Visión completa del proyecto: [`docs/vision.md`](docs/vision.md)
- Arquitectura: [`docs/architecture.md`](docs/architecture.md)
- Decisiones (ADRs): [`docs/adr/`](docs/adr)
- Contrato de API: [`openapi/v1.yaml`](openapi/v1.yaml)
- App interna (Flutter): repo [`logistics-app`](https://github.com/Eduardito187/logistics-app)

## Stack

Laravel 13 (PHP 8.4) · PostgreSQL 17 + PostGIS · Valkey · Filament 4 · Pest 4 · PHPStan nivel 8 · Docker.

## Primer arranque

Requisitos: Docker y `make` (en WSL).

```bash
make install
```

Levanta todo, instala dependencias, genera `APP_KEY` y corre migraciones. Después:

| Servicio | URL |
| --- | --- |
| API | http://localhost:8110/api/v1/health |
| Admin | http://localhost:8110/admin |
| Mailpit | http://localhost:8055 |
| PostgreSQL | `localhost:5442` (usuario y clave `logistics`) |
| Valkey | `localhost:6409` |

Crear un usuario del admin:

```bash
make artisan c="make:filament-user"
```

Generar las claves de firma de etiquetas (van al `.env`, nunca al repo):

```bash
make artisan c="logistics:labels:keygen"
```

## Comandos frecuentes

```bash
make help      # todos los comandos
make test      # suite completa (base logistics_test, nunca la de desarrollo)
make lint      # Pint + PHPStan
make ci        # lo mismo que corre GitHub Actions
make shell     # shell dentro del container
make reload    # los workers recargan el código
```

## Estructura

```
app/                      # Código transversal: API v1, admin Filament, providers
packages/logistics/*      # Un package por bounded context (DDD de 4 capas)
openapi/v1.yaml           # Contrato con la app y los canales (fuente de verdad)
docs/                     # Visión, arquitectura y ADRs
.docker/                  # Imagen PHP, nginx, init de PostgreSQL
```

## Reglas del proyecto

1. Nada se da por terminado sin tests + PHPStan + Pint + verificación real contra Docker.
2. Todo endpoint nuevo se documenta en `openapi/v1.yaml` en el mismo cambio.
3. Todo texto visible del admin va en inglés; comentarios y docs en español.
4. Un package nuevo se agrega en `bootstrap/providers.php` **y** en `dont-discover` a la vez.
