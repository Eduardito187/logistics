# sellerportal — Portal / API del seller (autoservicio, solo lectura)

Fase 5 del roadmap marketplace (`docs/marketplace-dropship-roadmap.md` §8 punto 3; ADR 017). Capa de
**Presentación delgada** que le da a un seller autenticado visibilidad sobre SUS datos. No reimplementa
dominio: agrega **lecturas existentes** de otros contextos filtradas por el seller del token.

## Responsabilidad

Un seller autenticado por un **token de integración** (contexto `access`) **scoped a su `sellerId`** puede,
SOLO LECTURA por ahora, consultar por la puerta `integrations` (prefijo `seller-portal`):

- `GET /v1/integrations/seller-portal/asns` — sus ASNs (inbound): `inbound_asns WHERE seller_id = token.sellerId`.
- `GET /v1/integrations/seller-portal/stock` — su stock on-hand: `inventory_lots WHERE owner_party_id = token.sellerId`.
- `GET /v1/integrations/seller-portal/settlements` — sus liquidaciones: `seller_settlements WHERE seller_id = token.sellerId`.

## No-responsabilidad

- No escribe nada (crear ASN, iniciar devoluciones, webhooks: fases/iteraciones siguientes).
- No tiene migraciones propias (solo LEE tablas de `inbound` / `inventory` / `settlement`).
- No tiene dominio (agregados/VOs/eventos): es un agregador de lecturas.
- No depende por composer de `inbound` / `inventory` / `settlement`: lee sus tablas read-only con
  `DB::table(...)` envuelto en `try/catch` (patrón cross-context del skill `bounded-context`), para
  mantenerse **extraíble a microservicio** sin acoplar agregados.

## Resolución del `sellerId` (contrato con `access`)

El token de integración **no lleva hoy un claim `seller_id` de primera clase**. Se resuelve a partir de
los **permisos del token** (mecanismo que `access` ya expone, sin cambiarlo):

- Autorización: el token debe conceder el permiso `sellerportal.read` (lo exige la ruta con
  `access.permission:sellerportal.read`).
- Scope de seller: el token lleva **exactamente un** permiso de la forma `sellerportal.seller.<uuidhex>`,
  donde `<uuidhex>` es el UUID del seller en minúsculas y **sin guiones** (las claves de permiso de
  `access` solo admiten segmentos `[a-z0-9]`, ver `PermissionKey`). El `SellerContextResolver` lo extrae
  de los permisos efectivos que el middleware `access.token` dejó en la request.

Un token `*` (comodín) **no** identifica a un seller concreto ⇒ se rechaza (403): nunca se permite que un
token lea datos de más de un seller. Defensa en profundidad: si la request trae `?seller_id=` o el header
`X-Seller-Id`, debe coincidir con el seller resuelto del token, o 403.

### Gancho para el claim real

Cuando `access` exponga un `seller_id` de primera clase en el token (claim/binding), basta cambiar la
implementación de `SellerContextResolver` (leer el claim en vez de escanear permisos); la interfaz, los
controllers y las rutas NO cambian. El binding sigue viviendo en `access` (emisión del token), no aquí.

## Registro (6 lugares — pendiente, lo hace el dueño de los archivos raíz)

Esta sesión NO toca archivos raíz (partición exclusiva `packages/logistics/sellerportal/**`). Para activar
el package hay que, en el mismo cambio (ver `docs/marketplace-dropship-roadmap.md` §9):

1. `composer.json` raíz: `require logistics/sellerportal` + `extra.laravel.dont-discover`.
2. `bootstrap/providers.php`: alta de `SellerPortalServiceProvider` (tras `AccessServiceProvider`, que
   registra el alias `access.token` / `access.permission`).
3. `AdminPanelProvider`: n/a (no hay Filament).
4. `routes/api.php`: `require __DIR__.'/api/seller-portal.php'` en `$contextMaps` (fragmento provisto en
   `packages/logistics/sellerportal/routes/api.php`, puerta `integrations`).
5. `DatabaseSeeder`: n/a (solo lee).
6. `openapi/v1.yaml`: endpoints del portal (snippet en `openapi-seller-portal.snippet.yaml`).

## Estado

Fase 5, iteración 1: **solo lectura** (ASNs, stock on-hand, liquidaciones). Verificado con PHPStan nivel 8,
Pint y Pest (filtrado por seller) vía shim de autoload (el package aún no está registrado en la raíz).
