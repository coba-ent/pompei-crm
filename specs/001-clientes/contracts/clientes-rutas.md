# Contrato de UI / Rutas: Clientes

Interfaz que esta feature expone al usuario. Rutas web Laravel (Blade + AJAX), en español, sobre el
layout `layouts.default`. Todas bajo el prefijo `/clientes`.

**Reglas de diseño obligatorias aplicadas** (ver `CLAUDE.md`):
- El listado es una **DataTable responsive con carga AJAX server-side** → endpoint `clientes.data`.
- Alta/edición/eliminación se hacen en **modales de Bootstrap enviados por AJAX**; la página **nunca**
  se recarga. Los endpoints responden **JSON**, no redirects.
- Toda notificación (éxito/error) se muestra con **toasts de Toastr** en el front.

## Rutas

| Método | Ruta | Nombre | Acción | Respuesta | Historia |
|---|---|---|---|---|---|
| GET | `/clientes` | `clientes.index` | Página del listado (shell con la tabla y el modal) | HTML (Blade) | US3 |
| GET | `/clientes/data` | `clientes.data` | Datos server-side para la DataTable (paginado/orden/búsqueda/filtros) | JSON (formato DataTables) | US3 |
| GET | `/clientes/stats` | `clientes.stats` | Métricas para las cards informativas (total, activos, aptos, nuevos del mes) | JSON | — |
| GET | `/clientes/export` | `clientes.export` | Exporta el listado filtrado a CSV/Excel (BOM UTF-8, streaming) | descarga CSV | — |
| POST | `/clientes` | `clientes.store` | Crear cliente (desde el modal) | JSON | US1, US2, US5, US6 |
| GET | `/clientes/{cliente}` | `clientes.show` | Datos del cliente para precargar el modal de edición | JSON | US1 |
| PUT/PATCH | `/clientes/{cliente}` | `clientes.update` | Actualizar cliente (desde el modal) | JSON | US1, US2, US5, US6 |
| DELETE | `/clientes/{cliente}` | `clientes.destroy` | Eliminar físicamente (sólo si no tiene operaciones) | JSON | US4 |
| PATCH | `/clientes/{cliente}/estado` | `clientes.estado` | Alternar activo/inactivo (baja lógica) | JSON | US4 |
| POST | `/clientes/verificar-cuit` | `clientes.verificar-cuit` | Verificar CUIT contra ARCA y devolver datos fiscales | JSON | US2 |

## Contratos JSON

### GET `clientes.data` (DataTables server-side)

- Request: parámetros estándar de DataTables (`draw`, `start`, `length`, `search[value]`,
  `order[...]`) + filtros extra: `estado` (`activos`/`inactivos`/`todos`), `categoria_id`.
- Response: `{ draw, recordsTotal, recordsFiltered, data: [ { id, nombre, cuit, email, telefono,
  categoria, condicion_iva, apto_facturar (bool), activo (bool), acciones (HTML) }, ... ] }`.
- Búsqueda global aplica sobre nombre y CUIT (FR-018); filtros sobre estado y categoría (FR-019).

### POST `clientes.store` / PATCH `clientes.update` (form del modal, AJAX)

Campos aceptados: `nombre` (requerido), `nombre_pila`, `apellido`, `apodo_ml`, `pagina_web`, `email`,
`telefono`, `telefono_celular`, `domicilio`, `localidad`, `provincia`, `cp`, `nota`, `razon_social`,
`tipo_documento` (CUIT/CUIL/DNI/Pasaporte/CDI), `cuit`, `condicion_iva_id`, `tipo_comprobante_defecto`,
`domicilio_fiscal`, `localidad_fiscal`, `provincia_fiscal`, `cp_fiscal`, `telefono_fiscal`,
`telefono_celular_fiscal`, `categoria_id`, `lista_precio_id`, `descuento_general_pct`, `nota_cliente`,
`saldo_inicial`, `campos_personalizados` (array clave/valor), `contactos` (array de personas de
contacto: `nombre`, `cargo`, `telefono`, `email`).

Validaciones (FormRequest):

- `nombre`: required, string, max 255.
- `cuit`: nullable, unique en `clientes.cuit` (ignorando el registro propio en update; los NULL no
  colisionan → varios clientes sin CUIT permitidos, FR-016). La regla `CuitValido` (DV) se aplica sólo
  cuando `tipo_documento` es CUIT o CUIL; para DNI/Pasaporte/CDI se guarda sin ese chequeo. FR-025.
- `tipo_documento`: nullable, in `CUIT,CUIL,DNI,Pasaporte,CDI` (default CUIT).
- `contactos.*.nombre`: nullable string; las filas sin `nombre` se descartan al guardar. `contactos.*.email`: nullable email.
- `condicion_iva_id`: nullable, exists en `condiciones_iva`.
- `tipo_comprobante_defecto`: nullable, in `A,B,C,E`.
- `categoria_id`: nullable, exists en `categorias` (tipo venta).
- `lista_precio_id`: nullable, exists en `listas_precio`.
- `descuento_general_pct`: nullable, numeric, between 0 and 100. FR-014.
- `saldo_inicial`: nullable, numeric.
- `email`: nullable, email.

Respuestas:

- Éxito → HTTP 200 `{ ok: true, mensaje: "...", cliente: {...} }`. El front cierra el modal, recarga
  la DataTable y muestra un toast de éxito.
- Error de validación → HTTP 422 `{ ok: false, errors: { campo: ["mensaje", ...] } }`. El front
  muestra los errores en el modal y/o un toast de error, sin recargar.

### GET `clientes.show`

- Response: `{ cliente: { ...todos los campos... } }` para precargar el modal de edición.

### DELETE `clientes.destroy`

- Si `cliente.tieneOperaciones()` → HTTP 409 `{ ok: false, mensaje: "Sólo puede inactivarse: el
  cliente tiene operaciones asociadas." }` (toast de error). FR-022.
- Si no → HTTP 200 `{ ok: true, mensaje: "Cliente eliminado." }` (toast de éxito, fila removida de la
  tabla). FR-023.

### PATCH `clientes.estado` (toggle activo)

- Alterna `activo`; HTTP 200 `{ ok: true, activo: bool, mensaje: "..." }`. El front actualiza la fila
  y muestra toast. FR-020/FR-021.

### POST `clientes.verificar-cuit`

- Request: `{ cuit: string }`.
- Response:
  - `{ valido: false, error: "..." }` si el formato/DV es inválido (FR-006).
  - `{ valido: true, datos: { razon_social?, domicilio?, condicion_iva_id?, ... } | null }` si el
    formato es válido. `datos = null` cuando el verificador (stub/servicio) no devuelve información o
    ARCA no está disponible — el front deja los campos como están y el usuario completa a mano
    (resiliencia, FR-008).

## Notas de UI

- El listado usa una **DataTable** (patrón del template NexaDash, `uc-select2`/`table-datatable-basic`
  como referencia visual), responsive, con "Registros por página" y botón de exportar.
- El botón "Nuevo Cliente" abre el **modal** de alta; el ícono de editar en cada fila abre el mismo
  modal precargado (`_modal_form.blade.php`).
- El botón "Verificar" junto al CUIT dispara `verificar-cuit` (fetch AJAX) y autocompleta si hay
  datos, sin recargar.
- Indicador visual de "apto para facturar" por fila y en el modal, según
  `Cliente::esAptoParaFacturar()`.
- Toda respuesta (éxito/error/validación) se comunica con **toasts de Toastr**.
