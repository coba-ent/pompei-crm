# Implementation Plan: Ventas de Mercado Libre

**Branch**: `012-ventas-mercadolibre` | **Date**: 2026-07-27 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/012-ventas-mercadolibre/spec.md`

## Summary

Sincronizar las órdenes de venta de Mercado Libre hacia el CRM, listarlas en una pantalla nueva dentro
de Ingresos, y convertirlas en Ventas del CRM —manual o automáticamente— con su cobranza y su
movimiento de stock.

El enfoque técnico se apoya íntegramente en infraestructura ya construida y probada: `ClienteMercadoLibre`
(spec 011) como único punto de salida hacia la API, `CalculoComprobante` y `Cobranzas` (spec 008) para
totales y cobro, `StockService` (spec 002) para el movimiento de stock, y `Cache::lock` para exclusión
mutua portable. Lo genuinamente nuevo son cuatro piezas: la traducción del formato de Mercado Libre, la
tabla de vinculación publicación↔producto, el orquestador de conversión a Venta, y el sincronizador
programado con frecuencia configurable.

**⚠️ Esta spec además cierra una brecha preexistente**: las Ventas del CRM no descuentan stock (ver
Constitution Check y [research.md R1](./research.md)). Sin eso, el objetivo declarado del usuario
—reciprocidad de stock con Mercado Libre— es inalcanzable.

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12

**Primary Dependencies**: Eloquent · Laravel HTTP Client (`Http`) · Laravel Scheduler · `Cache::lock`
(exclusión mutua) · Yajra DataTables (server-side) · Bootstrap 5 + NexaDash · Select2 · Toastr

**Storage**: MySQL. Tablas nuevas: `ml_ordenes`, `ml_orden_items`, `ml_publicacion_producto`. Columnas
nuevas en `ml_configuracion` y en `ventas`.

**Testing**: PHPUnit (Feature tests). `Http::fake()` para simular la API de Mercado Libre, siguiendo el
patrón ya usado en `tests/Feature/Integraciones/` de la spec 011.

**Target Platform**: hosting compartido (tarea programada del sistema, sin procesos permanentes) **y**
VPS con colas. Mismo código, distinta configuración de entorno.

**Project Type**: aplicación web monolítica (Laravel + Blade), single-tenant.

**Performance Goals**: una corrida de sincronización con hasta ~200 órdenes nuevas debe completarse
dentro del límite de ejecución típico de hosting compartido; la conversión de una orden individual debe
responder de forma inmediata al usuario.

**Constraints**: sin procesos de larga duración garantizados · sin almacenamiento en memoria garantizado
· límites de solicitudes de la API de Mercado Libre · la primera corrida no debe arrastrar el historial
completo de la cuenta.

**Scale/Scope**: un único negocio, una única cuenta de Mercado Libre. Volumen esperado de decenas a
cientos de órdenes por mes. 2 pantallas nuevas + extensión de la pantalla de configuración existente.

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ⚠️ **Contradicción detectada y resuelta** | Ver abajo. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 012 escrita, clarificada y aprobada antes de planear. |
| **III. Corrección fiscal innegociable** | ✅ Pasa | El tipo de comprobante se **deriva** de la condición de IVA (FR-039/FR-040), nunca se elige a mano — exactamente lo que exige el principio. No se emite CAE: las Ventas siguen siendo no fiscales, con el sello "NO VÁLIDO COMO FACTURA" ya vigente. Las Ventas usan borrado lógico, ya implementado. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Tests obligatorios sobre: desagregación de IVA y coincidencia de totales, derivación del comprobante, idempotencia y concurrencia de la conversión, movimiento de stock e imputación de la cobranza. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Tablas, columnas, rutas y textos en español; snake_case; sin `empresa_id`. |

### ⚠️ Contradicción con el principio I — resuelta explícitamente

El principio I prohíbe avanzar en silencio ante una contradicción entre la spec y la documentación de
dominio. Hay una:

> **FR-046 exige descontar stock "con el mismo comportamiento que cualquier otra Venta del CRM".
> Ese comportamiento no existe: las Ventas del CRM no generan movimientos de stock.**

Verificado en código y confirmado por la propia documentación (`docs §6.2` y `docs/modelo_datos.md`,
que afirman que las operaciones `entrada`/`salida` "no existen todavía"). Detalle completo en
[research.md R1](./research.md).

**Resolución adoptada**: construir el descuento de stock por Venta como servicio compartido y cablearlo
para Ventas de Mercado Libre **y** manuales. Fundamento: cablearlo sólo para Mercado Libre crearía dos
comportamientos divergentes para el mismo documento —la inconsistencia que el principio I prohíbe—, y
dejaría el objetivo del usuario (evitar sobreventa) sin cumplir, ya que la spec 013 no tendría
movimiento local que propagar.

**Obligación derivada**: corregir `docs/documentacion_principal_crm.md §6.2` y la nota del enum `tipo`
en `docs/modelo_datos.md` **antes de `/speckit-tasks`**.

### Re-evaluación post-Fase 1

✅ **Pasa**. El diseño no introduce complejidad injustificada: no se agregan capas de abstracción nuevas,
no se crean patrones que el proyecto no use ya, y toda llamada externa sigue pasando por el único punto
de salida existente. La única expansión de alcance es la resuelta arriba, y es reductora de complejidad
—elimina una divergencia— no aditiva.

## Project Structure

### Documentation (this feature)

```text
specs/012-ventas-mercadolibre/
├── plan.md              # Este archivo
├── research.md          # Fase 0 — decisiones técnicas y contradicción R1
├── data-model.md        # Fase 1 — entidades, columnas, índices, transiciones
├── quickstart.md        # Fase 1 — guía de validación end-to-end
├── contracts/
│   └── rutas-internas.md   # Fase 1 — contrato de endpoints del CRM
├── checklists/
│   └── requirements.md
└── tasks.md             # Generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Enums/MercadoLibre/
│   ├── EstadoConexion.php                    # existente (spec 011)
│   ├── EstadoOrden.php                       # NUEVO — estado en Mercado Libre
│   ├── EstadoConversion.php                  # NUEVO — los 5 estados de FR-007a
│   └── MotivoRequiereAtencion.php            # NUEVO — motivos de bloqueo (FR-007b)
├── Models/Integraciones/
│   ├── MercadoLibreConfiguracion.php         # EXTENDER — columnas nuevas
│   ├── MercadoLibreOrden.php                 # NUEVO
│   ├── MercadoLibreOrdenItem.php             # NUEVO
│   └── MercadoLibrePublicacionProducto.php   # NUEVO — vinculación 1:1
├── Services/MercadoLibre/
│   ├── ClienteMercadoLibre.php               # existente — NO se toca
│   ├── TraductorOrdenes.php                  # NUEVO — R3, aísla el formato externo
│   ├── SincronizadorOrdenes.php              # NUEVO — R4/R5/R6, paginación e idempotencia
│   ├── ConversorOrdenAVenta.php              # NUEVO — orquesta la conversión
│   ├── ResolutorCliente.php                  # NUEVO — apodo ML, alta y ambigüedad
│   └── DerivadorComprobante.php              # NUEVO — R8, condición IVA → A/B
├── Services/Ingresos/
│   ├── CalculoComprobante.php                # existente — NO se toca
│   ├── Cobranzas.php                         # existente — se reutiliza
│   └── StockDeVenta.php                      # NUEVO — cierra la brecha de R1
├── Http/Controllers/Ingresos/
│   ├── MercadoLibreVentaController.php       # NUEVO — listado, sincronizar, convertir
│   └── MercadoLibreVinculacionController.php # NUEVO — pantalla de vinculaciones
├── Http/Requests/Integraciones/              # existente — se agregan requests
├── Console/Commands/
│   └── SincronizarOrdenesMercadoLibre.php    # NUEVO — comando de la tarea programada
└── Observers/
    └── VentaObserver.php                     # EXTENDER — reintegro de stock al borrar

database/migrations/                          # 3 tablas nuevas + 2 alter
resources/views/ingresos/mercadolibre/        # NUEVO — listado, vinculaciones, conversión
resources/js/mercadolibre-ventas.js           # NUEVO
routes/web.php                                # EXTENDER — grupo Ingresos
tests/Feature/Integraciones/                  # NUEVO — tests de esta spec
```

**Structure Decision**: se respeta la organización vigente del proyecto sin introducir estructuras
nuevas. Los modelos de integración van bajo `app/Models/Integraciones/` y los servicios bajo
`app/Services/MercadoLibre/`, tal como los dejó la spec 011. Los controladores van bajo
`app/Http/Controllers/Ingresos/` —directorio nuevo pero consistente con `Configuracion/` e
`Integraciones/` ya existentes— porque las pantallas pertenecen funcionalmente al módulo Ingresos, no a
Configuración. `StockDeVenta` se ubica en `Services/Ingresos/` junto a `Cobranzas`, por ser el mismo
tipo de pieza: el punto único de integración entre Ingresos y otro módulo.

## Enfoque técnico por área

### 1. Sincronización

`SincronizadorOrdenes` corre bajo candado global. Antes de paginar verifica los tres cortes de FR-017/
FR-018 (función desactivada, modo sólo lectura, conexión caída o no configurada) y aborta con un único
registro en el historial, sin entrar al bucle. Pide a Mercado Libre las órdenes actualizadas desde la
última marca temporal exitosa, con solapamiento de seguridad, y hace *upsert* por identificador de
orden. Al terminar cada página persiste el avance, de modo que una interrupción se retome sin
reprocesar (FR-015). Si la creación automática está activa, delega cada orden apta en
`ConversorOrdenAVenta`.

### 2. Conversión

`ConversorOrdenAVenta` es el mismo camino para el flujo manual y el automático — una sola
implementación, dos disparadores. Toma el candado por orden, revalida que siga sin convertir, y dentro
de **una única transacción** crea Venta, ítems, cobranza y movimientos de stock (FR-048). Antes de
abrir la transacción resuelve las precondiciones: producto vinculado por cada línea, cliente
inequívoco, ausencia de variantes. Si alguna falla, marca la orden como "Requiere atención" con el
motivo y no toca nada más (FR-052).

### 3. Estado de conversión

El estado de FR-007a es **derivado y persistido**: se recalcula en cada sincronización y en cada
intento de conversión, y se guarda para que el listado pueda filtrarlo y ordenarlo sin recomputar por
fila. El motivo de bloqueo se guarda junto al estado.

### 4. Interfaz

Listado con DataTables server-side y panel de filtros; vinculaciones y configuración por modales AJAX
con Toastr; selectores de producto con Select2 vía el endpoint `productos.opciones` ya existente. La
pantalla de conversión reutiliza el formulario de página completa de "Nueva Venta" —excepción ya
documentada en la spec—, precargado desde la orden.

### 5. Menú lateral

La entrada "Mercado Libre" en Ingresos se muestra sólo con la función avanzada activa, con el mismo
mecanismo condicional que ya usa Abonos en `resources/views/elements/sidebar.blade.php`.

## Complexity Tracking

| Violación | Por qué es necesaria | Alternativa más simple, y por qué se rechazó |
|---|---|---|
| Ampliar el alcance al descuento de stock de **todas** las Ventas, no sólo las de Mercado Libre | Sin ello la spec se contradice a sí misma (FR-046 referencia un comportamiento inexistente), contradice la constitución, y el objetivo del usuario —evitar sobreventa— queda inalcanzable porque la spec 013 no tendría movimiento local que propagar. | Cablearlo sólo para Mercado Libre: rechazado por generar dos comportamientos distintos para el mismo documento, que es exactamente la inconsistencia silenciosa que prohíbe el principio I. Diferirlo a la spec 013: rechazado por invertir el orden lógico de las dependencias. |
