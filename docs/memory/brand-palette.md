---
name: brand-palette
description: Paleta de marca Dismac (del e-commerce Magento) para usar en el admin/UI
metadata:
  node_type: memory
  type: reference
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-10-13T08:12:57.555Z
---

Paleta oficial del e-commerce Dismac (fuente: `/dismac/dis-commerce/public/css/storefront.css`, `--color-dismatec-red` / `--color-brand`). Usar en el admin Filament y cualquier UI, especialmente **modo claro**:

- **Rojo de marca (primary):** `#E2231A`  (Dismac/Dismatec red)
- Rojo oscuro (hover/énfasis): `#D32F2F`
- Panel / fondo claro: `#F8F8F8` (también `#FAFAFA`, `#FDFDFD`)
- Bordes: `#C9C9C9` y `#ECECEC`
- Tinte rojo claro (fondos de alerta/negativo): `#FCE9E8`
- Aviso/warning: `#FEF0D6`
- Deshabilitado: `#C9C9C9`
- Blanco `#FFFFFF`; texto oscuro `#1C1C1C` / `#2E2E2E`; grises `#919191` / `#767676` / `#6B6B6B`

Aplicado en `app/Providers/Filament/AdminPanelProvider.php` como color `primary` del panel. El proyecto Magento vive en `/dismac/dis-commerce`. Ver [[project-overview]].
