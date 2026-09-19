# Data Model — Teléfono y sitio web en el encabezado de los comprobantes impresos

**Feature**: 105-telefono-web-pdf | **Date**: 2026-09-18

## Entidad afectada: `datos_empresa`

Fila única (single-tenant), sin `SoftDeletes` — es configuración, no un registro de negocio con
historial fiscal. Acceso vía `DatosEmpresa::instancia(): ?self`.

### Columnas nuevas

| Campo | Tipo | Nullable | Notas |
|---|---|---|---|
| `telefono` | `string(255)` | sí | Texto libre. Se imprime tal cual (FR-007). Admite más de un número y aclaraciones (`11 5555-5555 / WhatsApp 11 4444-4444`). Sin validación de formato — ver research.md, Decisión 2. |
| `sitio_web` | `string(255)` | sí | Texto libre. Se imprime tal cual, con o sin `www`, con o sin protocolo. No se convierte en link ni se normaliza. |

### Columnas existentes (sin cambios, para contexto)

`razon_social`, `cuit` (string 11), `domicilio_fiscal`, `condicion_iva`, `ingresos_brutos`,
`ruta_logo`, `mail_contador`.

> `domicilio_fiscal` es la "dirección" del pedido del cliente y **ya se imprime**. No se agrega
> ninguna columna de domicilio. Ver spec.md § *Contexto y recorte del pedido*.

### Reglas de validación

Ambos campos: `['nullable', 'string', 'max:255']`, consistente con el resto de los campos de texto de
esta pantalla.

No hay reglas de unicidad, de formato ni de obligatoriedad: FR-002 los define como opcionales.

### Migración

Aditiva y reversible:

- `up()`: agrega las dos columnas como `string` nullable, después de `ingresos_brutos`.
- `down()`: las dropea.

Sobre la base de producción (una sola fila, con datos reales) el efecto es que la fila existente queda
con ambos campos en `NULL`. Ese es el estado que FR-010 exige preservar: nada se pierde y los campos
nuevos aparecen vacíos hasta que alguien los complete.

### Impacto en el modelo

`App\Models\DatosEmpresa::$fillable` suma `'telefono'` y `'sitio_web'`. Sin casts, sin accessors, sin
mutators: son strings que se guardan y se imprimen como vinieron.

### Lo que NO cambia

- Ninguna otra tabla.
- `comprobantes_fiscales`, el circuito WSAA/WSFEv1, el CAE y el QR quedan intactos (FR-009): el
  encabezado del emisor es metadata de presentación y no participa de la emisión fiscal.
- No se agregan índices: es una tabla de una sola fila.

## Actualización obligatoria de `docs/modelo_datos.md`

Por el principio I de la constitución, la sección `datos_empresa` de `docs/modelo_datos.md` se
actualiza **antes de `/speckit-tasks`**, incorporando:

1. Las dos columnas nuevas de esta feature.
2. **Corrección**: el doc dice que el partial del encabezado lo consumen los PDFs "de Venta (§5) y de
   Notas de Crédito/Débito (§5)". Son **cinco**: Venta, Presupuesto, NC/ND, Remito y Recibo.
3. **Corrección**: falta la columna `mail_contador`, agregada por la migración
   `2026_08_27_234709_add_mail_contador_to_datos_empresa_table.php` y ya en uso por el envío al
   contador.
