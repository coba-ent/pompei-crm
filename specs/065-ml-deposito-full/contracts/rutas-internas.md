# Contrato: rutas internas del CRM afectadas

**Feature**: 065-ml-deposito-full

Ninguna ruta nueva. Se amplían tres endpoints existentes. Todo por AJAX, sin recarga de página, con
Toastr para las notificaciones (reglas obligatorias del proyecto).

---

## 1. `GET /ingresos/mercadolibre/vinculaciones/datatable` — se amplía

DataTables server-side ya existente sobre `ml_publicacion_producto`.

**Columnas nuevas en la respuesta**:

```json
{
  "logistic_type": "fulfillment",
  "logistica_etiqueta": "Full",
  "es_full": true
}
```

**Traducción de etiquetas** (capa de presentación, research R10):

| `logistic_type` | Etiqueta | Presentación |
|---|---|---|
| `fulfillment` | Full | **Badge destacado** |
| `xd_drop_off` | Colecta | Texto |
| `self_service` | Flex | Texto |
| `custom` | A cargo del vendedor | Texto |
| `not_specified` | Sin especificar | Texto atenuado |
| `null` | Sin clasificar | Texto atenuado |

**Parámetro de filtro nuevo** (FR-025): `logistic_type` — acepta cualquiera de los valores de arriba,
más `sin_clasificar` para `NULL`. Se resuelve server-side sobre la columna indexada, sin llamadas a
la API. Ausente o vacío = sin filtrar.

---

## 2. `PATCH configuracion/mercadolibre/ventas` — se amplía

> Ruta real verificada en `routes/web.php:414` →
> `MercadoLibreConfiguracionController@guardarVentas`, nombre `…mercadolibre.ventas.configurar`.
> **Es `PATCH`, no `POST`**, y cuelga de `configuracion/mercadolibre`, no de `ingresos/`.

**Campo nuevo en el request**: `deposito_full_id` (opcional, `nullable`).

**Validación** (`GuardarConfiguracionVentasMercadoLibreRequest`):

```php
'deposito_full_id' => ['nullable', 'exists:depositos,id', 'different:deposito_id'],
```

**Mensaje de error en español** (FR-017) — debe explicar el motivo, no sólo la regla:

> "El depósito para publicaciones Full tiene que ser distinto del depósito general de Mercado Libre.
> Si fueran el mismo, el stock que Mercado Libre informa de su centro de distribución sobrescribiría
> el stock real de tu depósito."

**Respuesta de error**: JSON 422 con los errores por campo, para mostrarlos en el modal sin recargar.

**Respuesta de éxito**: JSON 200 → Toastr de éxito.

---

## 3. `POST /ingresos/mercadolibre/sincronizacion-forzada` — cambia el mensaje de resultado

El job `SincronizacionForzadaMercadoLibre` encadena el reflejo Full después del push. El estado en
caché suma el resultado de Full al mensaje.

**Mensajes esperados**:

| Situación | Mensaje |
|---|---|
| Todo OK, con depósito Full | `"12 productos actualizados en Mercado Libre (stock), 3 omitidos por estar en Full — 3 productos actualizados desde Full (…) — N (precio)"` |
| Sin depósito Full configurado | `"… (stock), 3 omitidos por estar en Full — stock de Full no reflejado: no hay depósito para publicaciones Full configurado."` |
| Sin publicaciones Full | Mensaje actual, sin cambios |

**Requisito de no-regresión**: si no hay publicaciones Full vinculadas, el mensaje debe ser
**idéntico** al actual (SC-007).

---

## 4. Selector de depósitos — se reutiliza

El selector Select2 de "Depósito para publicaciones Full" consume el **mismo endpoint de opciones de
depósitos que ya usa** el selector del depósito general. No se crea endpoint nuevo.

Requisitos del proyecto aplicables: `width:'100%'`, `dropdownParent` = el modal contenedor, y
`.trigger('change.select2')` tras setear el valor por código.

---

## 5. Aviso de configuración incompleta (FR-026)

La pantalla de Configuración de Mercado Libre muestra una advertencia cuando existen vinculaciones
con `logistic_type = 'fulfillment'` **y** `deposito_full_id` es `null` o apunta a un depósito
inactivo:

> "Tenés N publicaciones en Full pero no configuraste un depósito para Full. Su stock no se está
> reflejando en el CRM y sus ventas se imputan al depósito general."

Se resuelve con el dato ya disponible en la vista; no requiere endpoint propio.
