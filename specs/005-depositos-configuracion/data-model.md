# Data Model — Gestión de Depósitos (Fase 1)

Sin tablas nuevas. `depositos` ya existe (creada en `002-productos`); esta feature agrega la primera
UI de gestión y un método de dominio nuevo al modelo `Deposito`.

## `depositos` (existente, sin cambios de esquema)

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | obligatorio (FR-008) |
| activo | boolean | default true — baja lógica |
| timestamps | | |

Relaciones (ya existentes, sin cambios): `Deposito` 1───N `Stock` (`stocks.deposito_id`), `Deposito`
1───N `MovimientoStock` (`movimientos_stock.deposito_id`).

## Método de dominio nuevo: `Deposito::tieneOperaciones(): bool`

```
existe alguna fila en stocks con deposito_id = este depósito y cantidad != 0
  O
existe alguna fila en movimientos_stock con deposito_id = este depósito
```

Bloquea la eliminación física (FR-005) — mismo patrón que `Cliente`/`Proveedor`/`Producto::tieneOperaciones()`.

## Reglas de validación (desde Requirements)

| Regla | Origen | Dónde se aplica |
|---|---|---|
| `nombre` requerido | FR-008 | `DepositoController::store()`/`update()` (`required`) |
| No eliminar con stock/movimientos asociados | FR-005 | `DepositoController::destroy()` vía `tieneOperaciones()` (409) |
| Sólo depósitos activos alimentan selectores de Productos | FR-004, FR-007 | Ya vigente (`Deposito::activos()`), sin cambios |

## Consistencia con docs de dominio

`docs/documentacion_principal_crm.md` §2.2 ya menciona: "El alta/baja de depósitos hoy se maneja vía
seeder/DB directa (`DepositoSeeder`); la UI de gestión (Contagram: Configuración → Funciones
Avanzadas) se retoma cuando se rehaga ese módulo." **Acción pendiente durante `/speckit-implement`**:
actualizar esa frase para reflejar que la UI ya existe, y documentar la pantalla en una sección
activa nueva (§3, Configuración & Ajustes → Depósitos). `docs/modelo_datos.md` ya documenta la tabla
`depositos` correctamente — sólo se agrega la nota sobre `tieneOperaciones()`.
