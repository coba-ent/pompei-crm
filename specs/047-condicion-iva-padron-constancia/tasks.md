# Tasks: Condición de IVA en el autocompletado del Padrón de ARCA

**Input**: Design documents from `/specs/047-condicion-iva-padron-constancia/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Tests**: incluidos — Constitución IV (Testing donde hay dinero o impacto fiscal) exige tests para
la condición de IVA, que determina directamente el tipo de comprobante (A/B).

**Organization**: tareas agrupadas por historia de usuario (spec.md), en orden de prioridad.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: se puede ejecutar en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: US1 o US2 — historia a la que pertenece

---

## Phase 1: Setup

- [ ] T001 Agregar entrada `ws_sr_constancia_inscripcion` (WSDL `personaServiceA5`, homologación/producción) a `config/arca.php`, mismo patrón que `ws_sr_padron_a13` (research.md R2)

## Phase 2: Foundational (bloqueante para ambas historias)

**Objetivo**: el wrapper SOAP de constancia de inscripción y la extensión de `ResultadoConsultaPadron`, base de la que dependen US1 y US2.

- [ ] T002 Crear `App\Services\Arca\ClienteConstanciaInscripcion` en `app/Services/Arca/ClienteConstanciaInscripcion.php`: mismo molde que `ClientePadron` (constructor con `CertificadoFiscal` inyectado, método público `consultarConstancia(array $ticketAcceso, string $cuit): object` que llama a `getPersona` de `personaServiceA5`, `SOAP_1_1`, `connection_timeout` 8s, `SECLEVEL=1`, captura `SoapFault`/`Throwable` → `ArcaNoDisponibleException`) — research.md R2/R6
- [ ] T003 Extender `App\Services\Arca\ResultadoConsultaPadron` en `app/Services/Arca/ResultadoConsultaPadron.php`: agregar método estático `conCondicionIva(self $resultado, ?object $respuestaConstancia): self` que, dado el resultado ya construido desde `ws_sr_padron_a13`, fusiona `condicionIvaRaw`/`condicionIvaId` derivados de la respuesta de constancia (`datosRegimenGeneral.impuesto[]`/`datosMonotributo`, regla de derivación de research.md R4) sin tocar `razonSocial`/`domicilioFiscal`/`localidadFiscal`/`activo` — si `$respuestaConstancia` es `null` o no trae `datosGenerales`, devuelve `$resultado` sin cambios
- [ ] T004 [P] Escribir test unitario `tests/Unit/Services/Arca/ClienteConstanciaInscripcionTest.php`: mockea `SoapClient` para simular respuesta exitosa (con `datosRegimenGeneral.impuesto` conteniendo "IVA"/`AC`), CUIT no encontrado, y `SoapFault` → verifica que el último caso lanza `ArcaNoDisponibleException` y nunca una excepción sin capturar (mismo patrón que `ClientePadronTest`)
- [ ] T005 [P] Extender el test unitario `tests/Unit/Services/Arca/ResultadoConsultaPadronTest.php`: agregar casos para `conCondicionIva()` con fixtures basadas en la estructura real (research.md R3) — impuesto "IVA" con `estadoImpuesto: AC` → Responsable Inscripto; `datosMonotributo` presente → Monotributista; sin `datosRegimenGeneral` ni `datosMonotributo` → `condicionIvaId: null`; respuesta de constancia `null` → el resultado original de A13 queda intacto (regla de derivación completa, research.md R4)

**Checkpoint**: con T001-T005 en verde, ambas historias de usuario pueden implementarse en paralelo.

---

## Phase 3: User Story 1 - Completar condición de IVA al verificar un CUIT en el modal de cliente (Priority: P1) 🎯 MVP

**Goal**: el botón "Verificar" del modal de cliente, además de razón social y domicilio (ya funcionando), completa también la condición frente al IVA, consultando `ws_sr_constancia_inscripcion` de forma independiente y best-effort.

**Independent Test**: abrir el modal de cliente, cargar el CUIT de un contribuyente real Responsable Inscripto, click en "Verificar", confirmar que también se completa la condición de IVA; repetir simulando que sólo esa consulta falla y confirmar que razón social/domicilio se completan igual sin bloquear.

### Tests para User Story 1

- [ ] T006 [P] [US1] Extender el test de feature `tests/Feature/ClienteVerificarPadronTest.php`: agregar casos — ambas consultas responden (`padron.condicion_iva` presente, contracts/verificar-documento.md caso 1); sólo `ws_sr_padron_a13` responde y `ws_sr_constancia_inscripcion` falla (razón social/domicilio presentes, sin la clave `condicion_iva`, contracts/verificar-documento.md caso 2)

### Implementación de User Story 1

- [ ] T007 [US1] Extender `ClienteController::consultarPadron()` en `app/Http/Controllers/ClienteController.php` (línea ~86-106): tras obtener `$resultado` de `ws_sr_padron_a13`, si `$resultado->encontrado`, intentar además el ticket de acceso `ws_sr_constancia_inscripcion` y `ClienteConstanciaInscripcion::consultarConstancia()`; envolver en su propio try/catch de `ArcaNoDisponibleException` (independiente del de A13, research.md R5) y aplicar `ResultadoConsultaPadron::conCondicionIva()`; incluir `condicion_iva` en el JSON de respuesta sólo cuando quedó resuelto (mismo patrón `array_filter` ya usado). FR-007 (evitar consultas duplicadas simultáneas) queda cubierto sin cambios adicionales: ambas consultas ocurren dentro de la misma request síncrona que ya protege el frontend (spec 037 FR-012/T009 — debounce del botón "Verificar")
- [ ] T008 [US1] Verificar en `resources/js/cliente-modal.js` que el manejo de la clave `padron.condicion_iva` en la respuesta de `/clientes/verificar-documento` ya completa el `<select>` de condición de IVA (funcionalidad ya implementada por spec 037, T009) sin sobrescribir ediciones manuales — ajustar sólo si se detecta que no maneja la ausencia de la clave (caso "sólo se completó razón social/domicilio") de forma explícita
- [ ] T009 [US1] Verificar manualmente en navegador (certificado con `ws_sr_constancia_inscripcion` adherido, ya confirmado en producción 05/08/2026): flujo completo de `quickstart.md` Escenario 1 — condición de IVA se completa junto con razón social y domicilio, y respeta ediciones manuales

**Checkpoint**: User Story 1 funciona de forma independiente — entregable como MVP.

---

## Phase 4: User Story 2 - Usar la condición de IVA confirmada para determinar el tipo de comprobante en la conversión de órdenes (Priority: P2)

**Goal**: al convertir una orden de Tiendanube o MercadoLibre (manual o automática), cuando el cliente resuelto es nuevo o no tiene condición de IVA cargada y la orden trae CUIT, la determinación de tipo de comprobante ya prevista en la spec 037 funciona de punta a punta porque el dato de condición de IVA ahora llega.

**Independent Test**: convertir una orden con el CUIT de un Responsable Inscripto real → Factura A (hoy no ocurre por el bug de R1); convertir con un CUIT sin inscripciones relevadas o Monotributista → Factura B; convertir con la nueva consulta fallando → fallback a la aproximación por documento, sin bloquear.

### Tests para User Story 2

- [ ] T010 [P] [US2] Extender el test de feature `tests/Feature/Integraciones/TiendanubeConversionPadronTest.php`: ajustar el mock de `ClientePadron` (que hoy ya simula condición de IVA vía la estructura corregida de A13 tras el fix del 05/08/2026) para que la condición de IVA salga en cambio de un mock de `ClienteConstanciaInscripcion` — caso Responsable Inscripto confirmado → Venta tipo A y Cliente con condición completada; caso `ClienteConstanciaInscripcion` falla pero `ClientePadron` responde → razón social/domicilio del Cliente se completan igual, tipo de comprobante cae a la aproximación por documento
- [ ] T011 [P] [US2] Extender el test de feature `tests/Feature/Integraciones/MercadoLibreConversionPadronTest.php`: mismos casos que T010, adaptados a `App\Services\MercadoLibre\DerivadorComprobante`

### Implementación de User Story 2

- [ ] T012 [US2] Extender `App\Services\Tiendanube\ResolutorCliente::consultarPadron()` en `app/Services/Tiendanube/ResolutorCliente.php` (línea ~133-152): tras obtener `$resultadoPadron` de `ws_sr_padron_a13`, si `$resultadoPadron` no es `null`, intentar además `ws_sr_constancia_inscripcion` (mismo patrón try/catch independiente que T007) y aplicar `ResultadoConsultaPadron::conCondicionIva()` antes de devolver el resultado — el resto de `tipoComprobante()`/`completarDatosFiscalesSinPisar()` no cambia (ya consume `condicionIvaId` del resultado)
- [ ] T013 [US2] Aplicar el mismo cambio a `App\Services\MercadoLibre\DerivadorComprobante::consultarPadron()` en `app/Services/MercadoLibre/DerivadorComprobante.php` (línea ~111-127), con el mismo criterio que T012
- [ ] T014 [US2] Verificar manualmente en navegador/homologación: flujo completo de `quickstart.md` Escenario 3 — conversión manual y automática con CUIT de Responsable Inscripto real generando Factura A, y con la nueva consulta simulada como no disponible sin bloquear la conversión

**Checkpoint**: User Story 2 funciona de forma independiente, activando el criterio de la spec 037 que hoy queda incompleto por el bug de R1.

---

## Phase 5: Polish & Cross-Cutting

- [ ] T015 [P] Ejecutar la suite completa de tests (`php artisan test`) y confirmar que no se rompió ningún test existente de spec 037, 034, 019/017 (Tiendanube) ni 011/012/013 (MercadoLibre)
- [ ] T016 Revisar los ítems del checklist `specs/047-condicion-iva-padron-constancia/checklists/fiscal.md` contra la implementación final antes de dar la feature por terminada
- [ ] T017 [P] Confirmar que `docs/documentacion_principal_crm.md` (ya actualizado durante `/speckit-plan`, ver §2 y §5) sigue siendo consistente con la implementación final; ajustar si algo cambió durante la implementación

---

## Dependencies & Execution Order

- **Setup (Phase 1)** → **Foundational (Phase 2)**: bloquean todo lo demás (T001 es prerequisito de T002; T002/T003 son prerequisito de T007 y T012/T013).
- **User Story 1 (Phase 3)** y **User Story 2 (Phase 4)** son independientes entre sí una vez completada la Fase 2 — pueden implementarse en paralelo o en cualquier orden; US1 es MVP por prioridad (P1).
- **Polish (Phase 5)** depende de que ambas historias estén implementadas.

## Parallel Example

```
# Tras completar T001-T005 (Setup + Foundational):
Task T006 (test US1) y T010/T011 (tests US2) se pueden lanzar en paralelo — archivos de test distintos.
Task T007 (US1, ClienteController) y T012/T013 (US2, ResolutorCliente/DerivadorComprobante) tocan
archivos distintos y no comparten estado — paralelizables entre sí una vez que T002/T003 (Foundational) están listos.
```

## Implementation Strategy

**MVP first**: implementar sólo User Story 1 (T001-T009) entrega valor de forma independiente — el
botón "Verificar" del modal de cliente completa condición de IVA sin tocar la conversión de órdenes.
User Story 2 (T010-T014) se puede sumar después sin retrabajo, reusando el mismo
`ClienteConstanciaInscripcion`/`ResultadoConsultaPadron::conCondicionIva()` de la Fase 2.
