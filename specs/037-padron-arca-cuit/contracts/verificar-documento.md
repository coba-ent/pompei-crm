# Contrato: `GET /clientes/verificar-documento` (extendido)

Endpoint ya existente (`ClienteController::verificarDocumento`), extendido por esta feature. No
cambia la ruta, el verbo ni los parámetros de entrada — sólo la respuesta cuando `tipo_documento`
es CUIT/CUIL y la validación local es exitosa.

## Request

Sin cambios:

```
GET /clientes/verificar-documento?tipo_documento=CUIT&numero=20123456789
```

## Response — comportamiento nuevo

Cuando `tipo_documento` es CUIT o CUIL, `numero` pasa la validación local de dígito verificador,
y el certificado fiscal activo permite consultar el padrón:

### Caso: padrón encuentra el contribuyente

```json
{
  "aplica": true,
  "valido": true,
  "padron": {
    "consultado": true,
    "encontrado": true,
    "razon_social": "ACME SA",
    "domicilio_fiscal": "AV CORRIENTES 1234",
    "localidad_fiscal": "CABA",
    "condicion_iva": "Responsable Inscripto",
    "activo": true
  }
}
```

### Caso: padrón no encuentra el CUIT, o el valor de condición de IVA no mapea a ningún registro conocido

```json
{
  "aplica": true,
  "valido": true,
  "padron": {
    "consultado": true,
    "encontrado": false,
    "mensaje": "No se encontró el CUIT en el padrón de ARCA."
  }
}
```

### Caso: ARCA no disponible / timeout / certificado no configurado

```json
{
  "aplica": true,
  "valido": true,
  "padron": {
    "consultado": false,
    "mensaje": "No se pudo consultar el padrón de ARCA en este momento."
  }
}
```

### Caso: `tipo_documento` no es CUIT/CUIL, o la validación local de dígito verificador falla

Sin cambios respecto del contrato actual (no se agrega la clave `padron`):

```json
{ "aplica": false }
```

```json
{ "aplica": true, "valido": false, "mensaje": "El CUIT ingresado no es válido." }
```

## Reglas

- La clave `padron` sólo aparece cuando `aplica: true` y `valido: true`.
- `padron.consultado: false` cubre indistintamente: certificado no configurado/vencido, timeout,
  o cualquier error de comunicación con ARCA (FR-004, FR-011) — el frontend los trata igual (toast
  informativo, sin bloquear).
- El endpoint NUNCA devuelve error HTTP 5xx por una falla de ARCA — siempre 200 con
  `padron.consultado: false` en ese caso (consistente con "no bloquea el guardado").
- `padron.condicion_iva` es el **nombre ya mapeado al catálogo `condiciones_iva` del CRM**
  (research.md R6), no el texto crudo que devuelve ARCA — si el valor crudo no matchea ningún
  nombre conocido, se trata igual que "no encontrado" (sin la clave `condicion_iva`).
