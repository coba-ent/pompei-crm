# Contrato: `GET /proveedores/verificar-documento`

**Spec**: [../spec.md](../spec.md) | **Ruta existente**: `routes/web.php` → `proveedores.verificar-documento`

Endpoint ya existente que se **extiende** con la consulta al padrón. La forma actual de la respuesta
se conserva íntegra (retrocompatible); se agrega la clave `padron` cuando el documento es válido.

Este contrato es **idéntico** al de `clientes.verificar-documento` tras esta feature, por diseño
(FR-001, SC-006). Cualquier divergencia futura entre ambos es un defecto.

---

## Request

```http
GET /proveedores/verificar-documento?tipo_documento=CUIT&numero=30-71234567-8
```

| Parámetro | Tipo | Obligatorio | Notas |
|-----------|------|-------------|-------|
| `tipo_documento` | string\|null | presente | Se compara en mayúsculas. Sólo `CUIT` y `CUIL` disparan verificación |
| `numero` | string\|null | presente | Se normaliza quitando todo lo que no sea dígito (acepta guiones) |

**Autenticación**: sesión web con los mismos permisos que el resto del módulo Proveedores.

---

## Responses

### A. El tipo de documento no aplica

Cuando `tipo_documento` no es CUIT ni CUIL, o `numero` queda vacío tras normalizar (FR-003).

```json
{ "aplica": false }
```

No se consulta ARCA. **Comportamiento actual, sin cambios.**

---

### B. CUIT matemáticamente inválido

Dígito verificador incorrecto (FR-002). No se consulta ARCA.

```json
{
  "aplica": true,
  "valido": false,
  "mensaje": "El CUIT ingresado no es válido."
}
```

**Comportamiento actual, sin cambios.**

---

### C. CUIT válido — respuesta extendida

A partir de esta feature, un CUIT válido incluye siempre la clave `padron` (FR-001).

```json
{
  "aplica": true,
  "valido": true,
  "padron": { }
}
```

El contenido de `padron` toma una de tres formas:

#### C.1 — No se pudo consultar (sin certificado activo o ARCA no disponible)

```json
{
  "consultado": false,
  "mensaje": "No se pudo consultar el padrón de ARCA en este momento."
}
```

Cubre: no hay `CertificadoFiscal` activo, falla de WSAA, timeout o error SOAP (FR-005, FR-006).
El front no modifica ningún campo (FR-007).

#### C.2 — Consultado, CUIT no encontrado

```json
{
  "consultado": true,
  "encontrado": false,
  "mensaje": "No se encontró el CUIT en el padrón de ARCA."
}
```

El front no modifica ningún campo.

#### C.3 — Encontrado

```json
{
  "consultado": true,
  "encontrado": true,
  "razon_social": "ACME SA",
  "domicilio_fiscal": "AV CORRIENTES 1234",
  "localidad_fiscal": "CABA",
  "provincia_fiscal": "Ciudad Autónoma de Buenos Aires",
  "condicion_iva": "Responsable Inscripto",
  "activo": true
}
```

**Regla de omisión**: las claves cuyo valor sea `null` **se omiten** del objeto (la respuesta pasa
por un filtro de nulos). El consumidor debe tratar la ausencia de una clave como "el padrón no
informó ese dato", no como error. Caso frecuente: `condicion_iva` ausente cuando la consulta de
constancia falló pero la de identidad funcionó (FR-004).

| Clave | Tipo | Puede faltar | Origen |
|-------|------|--------------|--------|
| `razon_social` | string | sí | padrón A13 |
| `domicilio_fiscal` | string | sí | domicilio tipo FISCAL |
| `localidad_fiscal` | string | sí | domicilio tipo FISCAL |
| `provincia_fiscal` | string | sí | provincia normalizada al catálogo del sistema |
| `condicion_iva` | string | sí | **nombre** de la condición, no el id |
| `activo` | bool | sí | estado en ARCA; informativo, no bloquea |

---

## Garantías

1. **Nunca lanza error por fallas de ARCA**: toda falla se traduce a la forma C.1 con HTTP 200
   (FR-005). El endpoint no devuelve 5xx por causa del servicio externo.
2. **No escribe en la base**: es una operación de sólo lectura.
3. **No consulta ARCA innecesariamente**: los casos A y B cortan antes de tocar la red (FR-002/003).
4. **Paridad con Cliente**: para un mismo CUIT, el bloque `padron` es idéntico al de
   `clientes.verificar-documento` (SC-006).

---

## Contrato con el front (`resources/js/proveedores.js`)

Al recibir la respuesta, el front debe:

1. Pintar el resultado de validez del documento (comportamiento actual, se conserva).
2. Si existe `padron`, autocompletar los campos **no tocados manualmente** (FR-008/FR-009) en este
   orden: `razon_social`, `domicilio_fiscal`, luego `provincia_fiscal` y **recién después**
   `localidad_fiscal` (FR-011), y `condicion_iva_id` por coincidencia exacta de texto de opción.
3. Si un valor no matchea una opción disponible, dejar el campo sin tocar (FR-012).
4. Mostrar un toast según la forma recibida: C.1 → info, C.2 → info, C.3 → success (FR-006).
5. Impedir consultas superpuestas mientras una está en curso (FR-013).
6. Limpiar el resultado visible cuando cambia el número o el tipo de documento (FR-014).
