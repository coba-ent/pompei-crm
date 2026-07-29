# Contrato: Verificar documento (CUIT/CUIL)

Endpoint liviano, no-CRUD, usado por el botón "Verificar" del bloque Datos de Facturación en los
modales de Cliente y Proveedor (FR-001/FR-002). Dos rutas idénticas en forma, una por módulo, para
mantener el gating de permisos alineado con cada módulo (ver [research.md](../research.md) R2).

## `GET clientes/verificar-documento`

**Acceso**: hoy, mismo que el resto de las rutas de Clientes (`estado`/`opciones` en
`routes/web.php:50-58`) — sólo requieren estar autenticado (`auth`), sin un middleware `permiso:`
específico de módulo (a diferencia de Ventas/Compras/Tesorería). Ver research.md R2 para el detalle.

**Query params**:

| Param | Tipo | Requerido | Notas |
|---|---|---|---|
| `tipo_documento` | string | Sí | Uno de `CUIT`, `CUIL`, `DNI`, `Pasaporte`, `CDI` (mismo enum que el campo del modal). |
| `numero` | string | Sí | El valor tal cual está en el input — puede venir con guiones, el backend lo normaliza antes de validar. |

**Respuesta 200** (`tipo_documento` no es CUIT/CUIL, o `numero` vacío):

```json
{ "aplica": false }
```

Caso "no corresponde validar" (DNI/Pasaporte/CDI, o campo vacío — ver Edge Cases de spec.md). El
frontend no debe mostrar ni error ni confirmación en este caso.

**Respuesta 200** (`tipo_documento` es CUIT o CUIL, con `numero` cargado):

```json
{ "aplica": true, "valido": true }
```

o

```json
{ "aplica": true, "valido": false, "mensaje": "El CUIT ingresado no es válido." }
```

El texto de `mensaje` es exactamente el que ya usa `App\Rules\CuitValido` para bloquear el guardado
(no uno nuevo) — así el botón y el bloqueo al guardar siempre muestran lo mismo.

**Errores**: `422` si falta `tipo_documento` o `numero` en la query (FormRequest liviano). No hay
otros modos de error — es un cálculo local sin dependencias externas.

## `GET proveedores/verificar-documento`

Mismo contrato exacto que `clientes/verificar-documento`, con el mismo tratamiento de acceso que el
resto de las rutas de Proveedores.

## Uso desde el frontend

`resources/js/clientes.js` / `proveedores.js`: al hacer clic en "Verificar", `GET` al endpoint
correspondiente con los valores actuales de `tipo_documento` y `cuit` del formulario (sin necesidad de
haber guardado nada todavía). Pinta el resultado en `[data-field="cuit"]` (el mismo contenedor de
`invalid-feedback` que ya usa la validación de guardado, ver `_modal_form.blade.php`), sin toast — es
feedback inline, no una notificación de operación completada.
