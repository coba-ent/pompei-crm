# Contrato: `GET /clientes/verificar-documento` (extendido, sobre lo ya extendido por spec 037)

Mismo endpoint que `specs/037-padron-arca-cuit/contracts/verificar-documento.md`. No cambia la ruta, el
verbo ni los parámetros de entrada, ni la forma general de la respuesta — sólo se corrige que la clave
`condicion_iva` efectivamente pueda aparecer poblada (spec 037 la contemplaba pero nunca llegaba a
completarse, ver research.md R1 de esta spec).

## Request

Sin cambios:

```
GET /clientes/verificar-documento?tipo_documento=CUIT&numero=20131204699
```

## Response — comportamiento nuevo

### Caso: ambas consultas (`ws_sr_padron_a13` + `ws_sr_constancia_inscripcion`) responden

```json
{
  "aplica": true,
  "valido": true,
  "padron": {
    "consultado": true,
    "encontrado": true,
    "razon_social": "MAURICIO MACRI",
    "domicilio_fiscal": "CORRIENTES AV. 545 Piso:10 Dpto:CF",
    "localidad_fiscal": "CIUDAD AUTONOMA BUENOS AIRES",
    "condicion_iva": "Responsable Inscripto",
    "activo": true
  }
}
```

### Caso: `ws_sr_padron_a13` responde pero `ws_sr_constancia_inscripcion` falla/no disponible

Igual que el caso anterior pero **sin** la clave `condicion_iva` — razón social/domicilio/activo se
completan igual, la condición de IVA queda ausente (no se envía `null` explícito, se omite la clave, mismo
criterio ya usado por el endpoint para claves opcionales):

```json
{
  "aplica": true,
  "valido": true,
  "padron": {
    "consultado": true,
    "encontrado": true,
    "razon_social": "MAURICIO MACRI",
    "domicilio_fiscal": "CORRIENTES AV. 545 Piso:10 Dpto:CF",
    "localidad_fiscal": "CIUDAD AUTONOMA BUENOS AIRES",
    "activo": true
  }
}
```

### Casos ya definidos por spec 037 (sin cambios)

- `ws_sr_padron_a13` no encuentra el CUIT o no está disponible → mismo comportamiento que hoy
  (`padron.encontrado: false` o `padron.consultado: false`), independientemente de lo que devuelva
  `ws_sr_constancia_inscripcion`.
- `tipo_documento` no es CUIT/CUIL, o falla la validación local de dígito verificador → sin cambios.

## Reglas

- Las dos consultas SOAP son independientes (research.md R5): el éxito o fallo de una no condiciona la
  presencia de los datos que aporta la otra en la respuesta.
- `padron.condicion_iva` sigue siendo el nombre ya mapeado al catálogo `condiciones_iva` del CRM, nunca el
  texto/estructura cruda de ARCA (ahora derivado según research.md R4, no un texto directo de la respuesta).
