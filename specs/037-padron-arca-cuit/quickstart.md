# Quickstart: Validar la Consulta al Padrón Fiscal de ARCA

## Prerrequisitos

- Certificado fiscal activo configurado (`CertificadoFiscal::activo()`), el mismo que ya se usa
  para Facturación Electrónica (spec 034) — Configuración & Ajustes.
- El certificado debe tener habilitado el servicio de padrón (`ws_sr_padron_a13`) en el portal de
  ARCA para el CUIT del negocio, además de WSFEv1 (verificar en homologación primero).
- Ambiente configurado en `config('arca.ambiente')` — recomendable probar en `homologacion` con un
  CUIT de prueba antes de habilitar en `produccion`.

## Escenario 1 — Verificar CUIT en el modal de cliente

1. Ir a Base de Datos > Clientes > Agregar cliente.
2. Tipo de documento: CUIT. Cargar un CUIT real y válido (dígito verificador correcto).
3. Clic en "Verificar".
4. **Resultado esperado**: en menos de 5 segundos (SC-001), aparece un indicador de éxito y se
   completan razón social, domicilio fiscal y condición frente al IVA — todos editables.
5. Modificar manualmente uno de esos campos (p. ej. razón social) y guardar.
6. **Resultado esperado**: se guarda el valor editado por el usuario, no el que trajo el padrón
   (research.md R5).
7. Repetir con un CUIT válido en dígito verificador pero inexistente en el padrón (o con ARCA
   deshabilitado/certificado vencido).
8. **Resultado esperado**: toast informando que no se pudo verificar contra el padrón; el
   formulario sigue completable y guardable manualmente (no se bloquea).

## Escenario 2 — Conversión de orden con CUIT determina Factura A automáticamente

1. Tener una orden de Tiendanube o MercadoLibre (real o de prueba en homologación) cuyo comprador
   sea nuevo en el CRM (sin `Cliente` ya emparejado) y traiga un CUIT válido de un contribuyente
   Responsable Inscripto real.
2. Convertir la orden (manualmente desde la pantalla de conversión, o vía el proceso de conversión
   automática/"Transformar todas en Venta").
3. **Resultado esperado**: la Venta se genera con `tipo_comprobante = 'A'` (SC-002), y el `Cliente`
   creado queda con razón social/domicilio/condición de IVA completados desde el padrón (FR-007b).
4. Repetir con una orden cuyo comprador YA existe en el CRM con `condicion_iva_id` ya cargado
   manualmente (distinto del que devolvería el padrón).
5. **Resultado esperado**: se respeta la condición ya cargada en el Cliente; el padrón no se
   consulta para esta decisión (FR-007a).
6. Repetir con una orden sin CUIT de comprador, y luego con ARCA deshabilitada/certificado vencido.
7. **Resultado esperado**: en ambos casos, comportamiento idéntico al actual (aproximación por
   longitud de documento / Factura B), sin errores visibles ni conversión bloqueada (SC-003).

## Validar resiliencia en lote

1. Tener varias órdenes "Listas" para conversión automática, incluyendo al menos una con CUIT que
   dispare consulta al padrón y falle (CUIT inexistente o ARCA momentáneamente caída simulada).
2. Ejecutar "Transformar todas en Venta".
3. **Resultado esperado**: todas las órdenes se procesan; la que tuvo falla de padrón cae al
   fallback de aproximación por documento sin afectar a las demás (FR-009).

## Referencias

- Contratos: [contracts/verificar-documento.md](./contracts/verificar-documento.md),
  [contracts/conversion-orden.md](./contracts/conversion-orden.md)
- Modelo de datos: [data-model.md](./data-model.md)
- Decisiones técnicas: [research.md](./research.md)
