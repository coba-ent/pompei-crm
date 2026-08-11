# Contract: Toggle %/monto fijo del Descuento General

Aplica de forma simétrica a los 4 módulos: `ventas`, `presupuestos`, `compras`,
`notas/{venta|compra}` (NC/ND).

## Payload de alta/edición (extiende el payload ya existente de cada módulo)

Campos nuevos enviados por el formulario (JSON, mismo body que hoy usan `store`/`update` de cada
módulo):

```jsonc
{
  // ... resto de los campos ya existentes del comprobante (items, conceptos, etc.) ...
  "descuento_general_tipo": "porcentaje",   // "porcentaje" | "monto" — default "porcentaje" si se omite
  "descuento_general_pct": 15.0,            // presente sólo si tipo = "porcentaje"
  "descuento_general_monto": null           // presente sólo si tipo = "monto"
}
```

Regla: el frontend envía siempre los 3 campos; el que no corresponde al modo activo va en `null` (no
se omite la clave), para que el backend pueda limpiar explícitamente el campo que dejó de aplicar al
editar (ej. pasar de % a $ en una edición debe borrar el `descuento_general_pct` viejo).

## Respuesta de error (FR-007 — monto fijo mayor al subtotal)

Mismo formato de error de validación ya usado por los FormRequest del proyecto (422, estructura
estándar de Laravel):

```jsonc
// HTTP 422
{
  "message": "El descuento general no puede ser mayor al subtotal del comprobante.",
  "errors": {
    "descuento_general_monto": ["El descuento general no puede ser mayor al subtotal del comprobante."]
  }
}
```

## Respuesta de éxito (alta/edición) — campos nuevos en el recurso devuelto

Los endpoints que ya devuelven el comprobante creado/actualizado (`venta`, `presupuesto`, `compra`,
`nota`) incluyen los 3 campos nuevos en el JSON de respuesta, igual que ya incluyen
`descuento_general_pct` hoy:

```jsonc
{
  "id": 123,
  // ...
  "descuento_general_tipo": "monto",
  "descuento_general_pct": null,
  "descuento_general_monto": 5000.00,
  "subtotal_sin_descuento": 33333.33,
  "descuento": 5000.00,
  "subtotal_con_descuento": 28333.33,
  "total": 34283.33
}
```

## Contrato de UI (no-HTTP, pero parte del comportamiento observable)

- El botón inline junto al campo "Descuento General" alterna entre mostrar `%` y `$` como su
  contenido/ícono.
- Al alternar, el `<input>` de descuento general:
  - cambia su `step`/formato de despliegue si corresponde (opcional, no bloqueante),
  - se limpia (`''`),
  - dispara el mismo evento de recálculo de totales que ya dispara hoy al tipear en el campo.
- Al cargar el formulario de edición, el botón se inicializa mostrando el modo persistido
  (`descuento_general_tipo`) y el input con el valor correspondiente (`descuento_general_pct` o
  `descuento_general_monto`), sin requerir interacción del usuario.
- Al cargar el formulario de alta (sin datos previos), el botón inicia en modo `%` (default, FR-002).
