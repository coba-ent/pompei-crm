# Implementation Plan: Cancelaciones de Mercado Libre posteriores a la venta, y avisos de sincronización

**Branch**: `063-ml-cancelaciones-avisos` | **Date**: 2026-08-12 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/063-ml-cancelaciones-avisos/spec.md`

## Summary

Cuando una orden de Mercado Libre se cancela **después** de haberse convertido en Venta, hoy no pasa
nada: quedan vivos la facturación, el cobro, el movimiento de Tesorería y el descuento de stock. En
producción eso ya significa **$560.051,43 de ventas irreales y 5 unidades de stock descontadas de
más**. En paralelo, los errores de sincronización de stock se reintentan indefinidamente sin que
nadie se entere.

El enfoque es **detectar y avisar**, nada más. Resolver una venta revertida ya está construido —el
circuito de notas de crédito con ajuste de stock, y la eliminación— y la pantalla de Órdenes ya tiene
el concepto de "requiere atención" con su enum de motivos. El trabajo real es: **detectar** la
cancelación posterior, **distinguir** sus tres variantes, **conducir** a la Venta para que se
resuelva con lo existente, y **cortar** el reintento infinito de las publicaciones bloqueadas.

**No se construye ningún circuito de reversión.** Ese es el criterio que ordena el diseño y lo que
mantiene la feature chica.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent, API de Mercado Libre (`ClienteMercadoLibre` ya existente)

**Storage**: MySQL — sin tablas nuevas; 3 columnas en `ml_publicacion_producto`

**Testing**: PHPUnit (Feature tests), con `Http::fake()` para la API

**Target Platform**: aplicación web (Blade + DataTables + modales Bootstrap)

**Project Type**: web app single-tenant

**Performance Goals**: no crítico. 126 órdenes históricas, 12 canceladas, 270 publicaciones. La
detección se apoya en la sincronización existente y **no agrega llamadas a la API**; el corte de
reintentos las **reduce** (~305 fallidas cada 6 h → menos de 10, SC-004)

**Constraints**: no alterar comprobantes ya autorizados por ARCA (Principio III); ninguna
modificación automática de plata o stock (decisión del usuario, FR-003)

**Scale/Scope**: 4 ventas afectadas hoy, 5 publicaciones con error. Tiendanube fuera de alcance

## Constitution Check

| Principio | Estado | Cómo se cumple |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ parcial | `docs/modelo_datos.md` ya se actualizó con los tres motivos, la transición nueva y las columnas de control de errores (antes de `tasks`, como exige el principio). Falta `docs/documentacion_principal_crm.md`, que describe el circuito funcional y se actualiza en T028. |
| **II. Desarrollo spec-driven** | ✅ | Esta spec precede al código. |
| **III. Corrección fiscal innegociable** | ✅ | La feature no toca comprobantes: sólo avisa y conduce a los circuitos existentes. Al revisar el código apareció que `VentaController::destroy()` permite hoy eliminar una Venta con CAE emitido — **agujero preexistente, ajeno a esta feature**, registrado aparte (research §R7). |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ | La detección no mueve dinero, pero decide sobre ventas cobradas: lleva tests obligatorios de que **no modifica** importes, cobros ni stock (FR-003). |
| **V. Convenciones Laravel + dominio en español** | ✅ | Se extienden enums y observers existentes, con nombres de dominio en español. |

**Gate**: PASA. El modelo de datos ya está actualizado; el circuito funcional queda como T028.

## Project Structure

### Documentation (this feature)

```text
specs/063-ml-cancelaciones-avisos/
├── plan.md              # Este archivo
├── spec.md
├── research.md          # Qué se verificó en código y producción
├── data-model.md        # Campos y estados nuevos
├── quickstart.md        # Cómo validar que funciona
└── checklists/
    └── requirements.md
```

### Source Code

```text
app/
├── Enums/MercadoLibre/
│   ├── MotivoRequiereAtencion.php      # + 3 casos
│   ├── EstadoOrden.php                 # dejar de colapsar cancelled/partially_refunded
│   └── EstadoConversion.php            # + transición Convertida → RequiereAtencion
├── Services/MercadoLibre/
│   ├── TraductorOrdenes.php            # leer también el estado del pago (mediación)
│   ├── SincronizadorOrdenes.php        # detectar la cancelación posterior
│   ├── DetectorCancelaciones.php       # NUEVO — la regla, aislada del sincronizador
│   └── SincronizadorStock.php          # clasificar error, contar intentos, cortar
├── Http/Controllers/Ingresos/
│   └── MercadoLibreVinculacionController.php   # descartar aviso, reactivar publicación
└── Models/Integraciones/
    └── MercadoLibrePublicacionProducto.php     # scope que excluye las bloqueadas

database/migrations/
└── ..._add_control_de_errores_a_ml_publicacion_producto.php

resources/views/ingresos/mercadolibre/    # columna de aviso + acciones
resources/js/mercadolibre-*.js

tests/Feature/Integraciones/
├── CancelacionPosteriorTest.php          # NUEVO
└── ErroresSincronizacionStockTest.php    # NUEVO
```

## Enfoque técnico

### 1. Detección (US1, US3)

Un servicio propio, `DetectorCancelaciones`, con la regla aislada: recibe la orden ya actualizada y
decide si corresponde marcar aviso y con qué motivo. Se invoca desde `procesarOrden()`, después de
que la orden se actualizó y **sólo si tiene `venta_id`** — si nunca se convirtió, no hay nada que
avisar (FR-007).

Se aísla en un servicio en vez de escribirlo dentro del sincronizador porque la regla tiene tres
variantes y condiciones de cierre, y embutirla ahí la haría intestable por separado.

El punto delicado: **la mediación no está en el estado de la orden sino en el del pago**, así que
`TraductorOrdenes` tiene que exponer el estado de los pagos, que hoy descarta.

### 2. Resolución (US2)

Dos acciones sobre una orden marcada:

- **Ir a la Venta** → un link desde el aviso. Ahí la persona usa lo que ya existe: emitir una nota
  de crédito o eliminar la venta. **No se construye ni se modifica ninguna de las dos.**
- **Descartar el aviso** → la orden vuelve a `Convertida`, registrando quién y cuándo. Por AJAX con
  confirmación en modal y toast, según las reglas de UI del proyecto.

El cierre del aviso es **automático**: si la Venta quedó compensada por una nota de crédito o fue
eliminada, el aviso deja de estar pendiente sin pedir un paso extra. Eso se resuelve al evaluar el
estado, no con un circuito propio.

### 3. Errores de sincronización (US4)

En `SincronizadorStock`, al fallar:

1. Si el error es igual al anterior, incrementa el contador; si es distinto, reinicia en 1 y
   actualiza `stock_error_desde`.
2. Al llegar a 5, marca `stock_requiere_intervencion`.
3. La selección de publicaciones pendientes excluye las marcadas — ahí está el ahorro de llamadas.

Al sincronizar con éxito, todo se limpia.

### 4. Visibilidad

En la pantalla de Órdenes, las marcadas ya se ven por el estado `requiere_atencion` existente; hay
que sumar las acciones. En el panel de vinculaciones, la columna de estado ya distingue
`pendiente`/`sincronizado`: se agrega el estado bloqueado, el motivo, los intentos y la diferencia
entre el stock del CRM y el publicado.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Que alguien resuelva un aviso eliminando una factura con CAE, en vez de emitir la nota de crédito | El aviso no elige por el usuario, pero la spec deja escrito cuál es la vía recomendada (FR-009a). El agujero de fondo se registra aparte (research §R7) |
| Un reintento que se corta por error deja stock desactualizado en silencio | El bloqueo es **visible** en el panel y reactivable a mano (FR-017) |
| Separar `EstadoOrden` puede afectar pantallas que hoy tratan todo como "Cancelada" | Buscar todos los usos de `EstadoOrden::Cancelada` antes de tocar el enum |
| Marcar aviso sobre una venta ya anulada manualmente | Condición explícita: sólo si la Venta está vigente (FR-007) |

## Fuera de alcance

- Corregir las 4 ventas ya afectadas (a mano, aparte).
- Tiendanube.
- Notificaciones fuera del sistema (módulo propio, ya registrado como pendiente).
- Emisión de la nota de crédito ante ARCA.
