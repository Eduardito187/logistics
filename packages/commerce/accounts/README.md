# commerce/accounts

Cuentas / identidad comercial del frente comercial (ADR 018, ítem EC-F0-04). Bounded context de la
familia `packages/commerce/*` (namespace `Commerce\Accounts\`), construido con la arquitectura objetivo
del proyecto: DDD + CQRS + Clean Architecture, UUID v7, eventos al outbox, reutilizando el núcleo
`logistics/shared` y `logistics/outbox`.

## Responsabilidad

- **Cuenta** (agregado `Account`): tipo (persona/empresa/seller-persona), nombre comercial, NIT (empresas),
  estado comercial (activa/suspendida) y direcciones de compra (value objects persistidos como JSONB en la
  fila de la cuenta).
- Referencia al **usuario de `access`** por UUID (`user_id`): el modelo comercial del cliente, separado de
  su identidad/authN.

## No-responsabilidad

- **Identidad / autenticación** (usuarios, tokens, SSO, MFA): la resuelve el contexto `access`. `accounts`
  NO modifica `access`; solo lo referencia por `Uuid`. (ADR 018.)
- **Catálogo, precio, carrito, orden, pago**: contextos aparte de comercio.
- **Seller (marketplace)**: lo modela `sellers`; una cuenta `seller_person` lo referencia por UUID.

## CQRS

- Comandos: `RegisterAccount`, `AddAddress`, `RemoveAddress`, `SuspendAccount`, `ActivateAccount`.
- Consultas: `GetAccount`, `GetAccountByUser`, `ListAccounts` (filtro por tipo) → ReadModels primitivos.

## Reglas de negocio

- Una cuenta de **empresa** (`company`) requiere NIT (`taxId`).
- El nombre comercial no puede estar vacío.
- A lo sumo una dirección es la **predeterminada**: al agregar una marcada como tal, se desmarcan las demás.
- `suspend()`/`activate()` son idempotentes (no re-graban evento si el estado no cambia).

## Estado

Estructura y dominio completos; tests Unit (dominio) + Feature (contra Postgres). Verificación de la DoD
(PHPStan 8 + Pint + Pest + build) corre en CI.
