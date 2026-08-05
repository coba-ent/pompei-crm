# Quickstart: Validar la derivación de Comprobante por defecto

## Prerrequisitos

- Sesión iniciada en el CRM con acceso a Base de Datos > Clientes.
- Catálogo `condiciones_iva` sembrado (incluye "Responsable Inscripto", ya requerido por spec 037).

## Escenario 1 — Alta manual: selección de Condición de IVA deriva el comprobante

1. Ir a Base de Datos > Clientes > "Nuevo Cliente".
2. En Condición de IVA, seleccionar "Responsable Inscripto".
3. **Resultado esperado**: "Comprobante por defecto" pasa a "Factura A" sin que el usuario lo haya
   tocado.
4. Cambiar la Condición de IVA a "Monotributista".
5. **Resultado esperado**: "Comprobante por defecto" pasa a "Factura B".

## Escenario 2 — El usuario edita el comprobante a mano y no se le pisa

1. Repetir los pasos 1-3 del Escenario 1 (queda "Factura A").
2. Editar manualmente "Comprobante por defecto" a "Factura C".
3. Cambiar la Condición de IVA a "Exento".
4. **Resultado esperado**: "Comprobante por defecto" sigue en "Factura C" (no se sobrescribe).

## Escenario 3 — Autocompletado por padrón de ARCA también dispara la derivación

1. "Nuevo Cliente" > Tipo de documento CUIT, cargar el CUIT de un contribuyente Responsable Inscripto
   real.
2. Clic en "Verificar".
3. **Resultado esperado**: además de completarse razón social/domicilio/Condición de IVA (spec
   037/047), "Comprobante por defecto" se completa con "Factura A".

## Escenario 4 — Edición de cliente existente no recalcula al abrir

1. Editar un cliente ya existente que tenga "Comprobante por defecto" = "Factura E" (o cualquier valor
   manual) y una Condición de IVA que normalmente derivaría "B".
2. Abrir el modal de edición.
3. **Resultado esperado**: "Comprobante por defecto" muestra "Factura E" tal cual, sin recalcularse.
4. Cambiar la Condición de IVA a "Responsable Inscripto".
5. **Resultado esperado**: recién ahí "Comprobante por defecto" pasa a "Factura A" (el cambio explícito
   del usuario dispara la derivación).

## Referencias

- Spec: [spec.md](./spec.md)
- Decisiones técnicas: [research.md](./research.md)
- Criterio ya vigente en backend: `App\Services\Tiendanube\ResolutorCliente`,
  `App\Services\MercadoLibre\DerivadorComprobante`
