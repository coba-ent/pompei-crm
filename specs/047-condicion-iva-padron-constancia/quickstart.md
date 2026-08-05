# Quickstart: Validar la Condición de IVA en el Autocompletado del Padrón

## Prerrequisitos

- Certificado fiscal activo configurado (`CertificadoFiscal::activo()`), mismo que ya usan Facturación
  Electrónica (spec 034) y la consulta a `ws_sr_padron_a13` (spec 037).
- El certificado debe tener habilitado el servicio **"Consulta de constancia de inscripción"**
  (`ws_sr_constancia_inscripcion`) en el Administrador de Relaciones de Clave Fiscal de ARCA, para el
  CUIT del negocio, además de `wsfe`/`wsfev1` y `ws_sr_padron_a13`. **Nota**: en el certificado usado en
  producción de este CRM ya estaba adherido al momento de especificar esta feature (confirmado el
  05/08/2026) — si se usa un certificado nuevo o distinto, adherirlo desde ARCA → Administrador de
  Relaciones de Clave Fiscal → Adherir Servicio → buscar por el texto **"Consulta de constancia de
  inscripción"** (no "A5").
- Ambiente configurado en `config('arca.ambiente')` — recomendable probar en `homologacion` primero.

## Escenario 1 — Condición de IVA se completa junto con razón social y domicilio

1. Ir a Base de Datos > Clientes > Agregar cliente.
2. Tipo de documento: CUIT. Cargar el CUIT de un contribuyente real Responsable Inscripto.
3. Clic en "Verificar".
4. **Resultado esperado**: en menos de 5 segundos (SC-001), se completan razón social, domicilio fiscal
   **y** condición frente al IVA (antes sólo se completaban los dos primeros — ver research.md R1 de esta
   spec para el porqué).
5. Modificar manualmente la condición de IVA antes de guardar.
6. **Resultado esperado**: se guarda el valor editado por el usuario, no el que trajo la consulta
   (mismo principio ya vigente para el resto de los campos, spec 037 R5).

## Escenario 2 — Degradación cuando sólo una de las dos consultas responde

1. Simular (en homologación, o mediante un CUIT que sólo exista en uno de los dos padrones) que
   `ws_sr_padron_a13` responde pero `ws_sr_constancia_inscripcion` falla.
2. Clic en "Verificar".
3. **Resultado esperado**: razón social y domicilio se completan igual; la condición de IVA queda sin
   completar; no aparece ningún error bloqueante, sólo se omite ese dato puntual (contracts/verificar-documento.md).

## Escenario 3 — Conversión de orden con CUIT genera Factura A automáticamente

1. Tener una orden de Tiendanube o MercadoLibre cuyo comprador sea nuevo en el CRM y traiga el CUIT de
   un contribuyente Responsable Inscripto real.
2. Convertir la orden (manual o automática).
3. **Resultado esperado**: la Venta se genera con `tipo_comprobante = 'A'` (SC-002 de esta spec, que
   reactiva el criterio ya definido en spec 037 SC-002), y el `Cliente` creado queda con condición de IVA
   completada.
4. Repetir con un CUIT de un contribuyente Monotributista o sin inscripciones relevadas.
5. **Resultado esperado**: comprobante tipo B, igual que si no se hubiese encontrado el CUIT.

## Verificar contra ARCA real (no sólo mocks)

Para confirmar en un ambiente real que el servicio sigue respondiendo con la estructura esperada (research.md
R3), correr una consulta puntual vía `php artisan tinker` con el certificado activo, usando
`App\Services\Arca\ClienteConstanciaInscripcion::consultarConstancia()` con un CUIT real conocido, y
verificar que la respuesta trae `datosRegimenGeneral.impuesto[]` o `datosMonotributo` según corresponda al
contribuyente consultado.

## Referencias

- Contrato: [contracts/verificar-documento.md](./contracts/verificar-documento.md)
- Modelo de datos: [data-model.md](./data-model.md)
- Decisiones técnicas y estructura real de la respuesta SOAP: [research.md](./research.md)
- Spec base que esta feature corrige/extiende: `specs/037-padron-arca-cuit/`
