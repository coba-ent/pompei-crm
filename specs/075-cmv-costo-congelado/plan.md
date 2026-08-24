# Implementation Plan: Costo congelado en el ítem de venta para un CMV fiel a Contagram

**Branch**: `075-cmv-costo-congelado` | **Date**: 2026-08-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/075-cmv-costo-congelado/spec.md`

## Summary

El "Costo Mercadería Vendida" del Informe de Ventas está calculado con una premisa refutada: la spec
068 lo derivó del promedio ponderado de las compras registradas, cuando Contagram usa el **costo del
producto congelado al momento de la venta**. En julio 2026 eso dejó el CMV en $24,6M contra $40,57M
reales y el KPI "Resultado" inflado en ~$16M.

**Enfoque técnico**: agregar `costo_unitario` (nullable, sin default) a `venta_items` y a
`nota_credito_debito_items`, poblarlo en los cuatro puntos de creación de producción, y cambiar la
expresión SQL del CMV a `COALESCE(costo_congelado, promedio_compras, 0) × cantidad_firmada`. El
`NULL` de las líneas históricas activa el fallback, lo que da cero regresión sin backfill.

El cambio es de superficie chica (dos columnas, una expresión SQL) pero tiene **dos puntos filosos**:
la edición de ventas borra y recrea los ítems (R5), y `NULL` vs `0` en la columna nueva no son
intercambiables (R7).

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent, Query Builder (SQL crudo en los informes), Yajra DataTables

**Storage**: MySQL (`contagram_migracion` local; producción en el VPS). Tests en SQLite

**Testing**: PHPUnit (`tests/Feature`, `tests/Unit`)

**Target Platform**: aplicación web Laravel, single-tenant

**Project Type**: web app monolítica (Blade + AJAX)

**Performance Goals**: sin regresión sobre SC-002 de la spec 068 (informe de ventas con ~5.000 ventas
sin degradación). Leer una columna propia es más barato que el `leftJoinSub`, que se conserva sólo
para el fallback

**Constraints**:
- Cero regresión en los KPIs históricos (SC-003): es requisito de despliegue, no un nice-to-have
- Sin backfill: las ~1M de líneas existentes quedan en `NULL` a propósito
- MySQL estricto (`ONLY_FULL_GROUP_BY`) en producción vs SQLite en tests: validación manual
  obligatoria en navegador (ver memoria del proyecto)

**Scale/Scope**: 2 columnas nuevas, 1 migración, ~6 archivos de producción tocados, 0 pantallas nuevas

## Constitution Check

*GATE: debe pasar antes de Phase 0. Re-evaluado después de Phase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ⚠️ **Contradicción identificada y con plan de resolución** | Esta spec contradice `docs/documentacion_principal_crm.md §21.1` y `docs/modelo_datos.md §Deuda de modelo`, que presentan el promedio ponderado como la regla del CMV. La contradicción está **explícita, fundamentada con evidencia** (exports de julio 2026) y su resolución es FR-011: corregir ambos documentos **antes** de `/speckit-tasks`. No se avanza en silencio. A favor: `modelo_datos.md` ya anticipaba esta spec ("congelar el costo al confirmar la venta sigue siendo la solución exacta, pero es una spec propia"). |
| **II. Desarrollo spec-driven** | ✅ | Cadena completa `specify → clarify → plan → checklist → tasks → analyze` antes de tocar código. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ | No toca CAE, tipo de comprobante, numeración ni emisión. El costo es un dato de gestión interna, sin efecto fiscal. El soft delete de ventas y notas se mantiene intacto. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ **Aplica de lleno** | Es cálculo de importes sobre el KPI que el cliente usa para decidir. Tests obligatorios para los 5 invariantes del contrato (I1–I5), la conservación en la edición y la no-regresión histórica. |
| **V. Convenciones Laravel + dominio en español** | ✅ | `costo_unitario` sigue la nomenclatura de `precio_unitario` en la misma tabla. |

**Regla de oro (fidelidad estructural a Contagram)**: ✅ **es la razón de existir de la spec.** No
cambia ninguna pantalla, columna ni KPI; corrige un **valor** para que coincida con el original. Y a
diferencia de la spec 068, la regla no se infiere de un caso único en una cuenta demo: se valida con
1.016 líneas cruzando proveedor y fecha.

**Veredicto**: PASA, con la contradicción documental como acción obligatoria pre-`tasks`.

### Re-evaluación post-Phase 1

Sin violaciones nuevas. El diseño no agrega tablas, endpoints, dependencias ni complejidad
estructural: dos columnas y un `COALESCE`. La única desviación evaluada y **descartada** fue usar un
model event de Eloquent como punto único de congelamiento (R4) — se rechazó por hacer la regla
invisible y por contaminar los comandos de migración, que deben dejar `NULL`.

## Project Structure

### Documentation (this feature)

```text
specs/075-cmv-costo-congelado/
├── plan.md              # Este archivo
├── spec.md              # Qué y por qué
├── research.md          # R1-R9: decisiones y evidencia
├── data-model.md        # Las dos columnas nuevas y sus reglas
├── quickstart.md        # 7 escenarios de validación
├── contracts/
│   └── cmv-api.md       # Firma de sqlCmv() e invariantes I1-I5
├── checklists/
│   └── requirements.md
└── tasks.md             # (lo genera /speckit-tasks)
```

### Source Code (repository root)

```text
database/migrations/
└── 2026_08_XX_XXXXXX_add_costo_unitario_a_items_de_venta_y_notas.php   # NUEVO

app/
├── Models/
│   ├── VentaItem.php                          # + costo_unitario en $fillable y $casts
│   └── NotaCreditoDebitoItem.php              # idem
├── Services/
│   ├── Ingresos/
│   │   └── CalculoComprobante.php             # congela el costo al armar cada línea
│   ├── Informes/
│   │   ├── CostoMercaderiaVendida.php         # sqlCmv() + COALESCE; docblock reescrito
│   │   └── VentasInformeQuery.php             # pasa la columna congelada en ambas ramas
│   ├── MercadoLibre/ConversorOrdenAVenta.php  # congela al convertir la orden
│   └── Tiendanube/ConversorOrdenAVenta.php    # idem
└── Http/Controllers/
    ├── VentaController.php                    # conserva el costo a través del delete+recreate
    └── NotaCreditoDebitoController.php        # centraliza el armado del ítem (6 puntos)

tests/
├── Feature/
│   ├── CmvCostoCongeladoTest.php              # NUEVO: invariantes I1-I5
│   ├── CmvEdicionVentaTest.php                # NUEVO: FR-009
│   └── CmvNotaCreditoTest.php                 # NUEVO: FR-008
└── (existentes de InformeVentas: deben seguir verdes sin tocarlos)

docs/
├── documentacion_principal_crm.md             # §21.1 corregido (FR-011)
└── modelo_datos.md                            # §Deuda de modelo + §21.1 corregidos (FR-011)
```

## Orden de implementación sugerido

El orden importa: hay una secuencia que mantiene el sistema consistente en cada paso.

1. **Migración + modelos** — columnas nullable. Nada cambia de comportamiento todavía.
2. **`sqlCmv()` con el `COALESCE`** — con todas las columnas en `NULL`, el informe sigue dando
   **exactamente lo mismo**. Es el paso que prueba que el fallback funciona antes de que haya un solo
   dato congelado. Acá va el Escenario 2 del quickstart.
3. **Congelamiento en alta manual** (`CalculoComprobante`) — primera venta con costo real.
4. **Conservación en la edición** (`VentaController::update`) — el punto filoso (R5).
5. **Canales externos** (ML y Tiendanube).
6. **Notas de crédito/débito** — depende de que las ventas ya congelen.
7. **Documentación de dominio** (FR-011) — obligatorio antes de cerrar.

## Riesgos y mitigaciones

| Riesgo | Impacto | Mitigación |
|---|---|---|
| La columna se crea con `default 0` en vez de nullable | **Alto**: el CMV histórico se desploma a ~0 y el Resultado de todos los períodos viejos se dispara | Test explícito de no-regresión (I1) + Escenario 2 del quickstart, tomando la línea de base **antes** de migrar |
| `NULLIF(costo_unitario, 0)` en la expresión SQL | Medio: los productos sin costo toman el promedio de compras y dejan de reproducir a Contagram | Invariante I2 con test dedicado |
| La edición pierde el costo congelado | **Alto**: editar una venta vieja reescribe el Resultado de un mes cerrado | Task dedicada (paso 4) + Escenario 3. Reusar `$itemsAnteriores`, que ya se captura en la línea 538 |
| Se olvida algún punto de creación (ML / TN / alguno de los 6 de NC) | Medio: ventas nuevas sin congelar que caen al fallback en silencio | Query de verificación del Escenario 6, agrupada por `origen` |
| Tests verdes en SQLite pero roto en MySQL | Medio: antecedente documentado en el proyecto | Validación manual en navegador obligatoria antes de dar por cerrada la feature |
| El informe muestra dos criterios mezclados y confunde al cliente | Bajo | Decisión explícita del usuario. Evaluar en `/speckit-tasks` si conviene una nota al pie o un tooltip que lo aclare |

## Complexity Tracking

No hay desviaciones de la constitución que justificar. El diseño elegido es el de menor complejidad
que satisface los requisitos: sin tablas nuevas, sin servicios nuevos, sin endpoints nuevos, sin
dependencias nuevas.
