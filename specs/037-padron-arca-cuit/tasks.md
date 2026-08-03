# Tasks: Consulta al Padrón Fiscal de ARCA

**Input**: Design documents from `/specs/037-padron-arca-cuit/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Tests**: incluidos — Constitución IV (Testing donde hay dinero o impacto fiscal) exige tests para
la determinación del tipo de comprobante, que es lógica fiscal directa.

**Organization**: tareas agrupadas por historia de usuario (spec.md), en orden de prioridad.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: se puede ejecutar en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: US1 o US2 — historia a la que pertenece

---

## Phase 1: Setup

- [X] T001 Agregar entrada `ws_sr_padron_a13` (WSDL homologación/producción) a `config/arca.php`, mismo patrón que `wsfev1`
- [X] T002 [P] Confirmar en `docs/documentacion_principal_crm.md` §7 y `docs/modelo_datos.md` §8.bis que las notas de spec 037 quedaron registradas (ya aplicado en este plan; sólo verificar consistencia antes de implementar)

## Phase 2: Foundational (bloqueante para ambas historias)

**Objetivo**: el wrapper SOAP de padrón y el mapeo de condición de IVA, base de la que dependen US1 y US2.

- [X] T003 Crear `App\Services\Arca\ClientePadron` en `app/Services/Arca/ClientePadron.php`: constructor con `CertificadoFiscal` inyectado, método público `consultarConstancia(array $ticketAcceso, string $cuit): object` que llama al método SOAP de A13 (research.md R2), `connection_timeout` de 8s (research.md R3), mismo `SECLEVEL=1` que `ClienteWsfev1`, captura `SoapFault`/`Throwable` → `ArcaNoDisponibleException`
- [X] T004 [P] Crear `App\Services\Arca\ResultadoConsultaPadron` (DTO/value object, `app/Services/Arca/ResultadoConsultaPadron.php`) con los campos de data-model.md (`cuit`, `encontrado`, `razon_social`, `domicilio_fiscal`, `localidad_fiscal`, `condicion_iva_raw`, `condicion_iva_id`, `activo`), con un método estático que parsea la respuesta cruda de `ClientePadron::consultarConstancia()` y aplica el mapeo de condición de IVA (research.md R6, reusando/extendiendo `MAPEO_CONDICION_IVA_CRM` ya existente en `App\Services\MercadoLibre\DerivadorComprobante`)
- [X] T005 [P] Escribir test unitario `tests/Unit/Services/Arca/ClientePadronTest.php`: mockea `SoapClient` para simular respuesta exitosa, CUIT no encontrado, y `SoapFault` → verifica que en el último caso se lanza `ArcaNoDisponibleException` y nunca una excepción sin capturar
- [X] T006 [P] Escribir test unitario `tests/Unit/Services/Arca/ResultadoConsultaPadronTest.php`: verifica el mapeo de condición de IVA (research.md R6) para los valores conocidos y el caso "no matchea ninguno conocido" (debe resultar en `condicion_iva_id = null`, tratado como no determinado)

**Checkpoint**: con T003-T006 en verde, ambas historias de usuario pueden implementarse en paralelo.

---

## Phase 3: User Story 1 - Verificar CUIT contra ARCA al cargar/editar un cliente (Priority: P1) 🎯 MVP

**Goal**: el botón "Verificar" del modal de cliente consulta el padrón real cuando el documento es CUIT/CUIL y ofrece autocompletar datos fiscales, editables, sin bloquear el guardado si ARCA no responde.

**Independent Test**: abrir el modal de cliente, cargar un CUIT real válido, click en "Verificar", confirmar que los campos se autocompletan y siguen editables; repetir con un CUIT inexistente en el padrón y con ARCA no disponible, confirmar que no bloquea el guardado.

### Tests para User Story 1

- [X] T007 [P] [US1] Test de feature `tests/Feature/ClienteVerificarPadronTest.php`: request a `GET /clientes/verificar-documento` con tipo CUIT válido — casos: padrón encuentra el contribuyente (respuesta incluye `padron.encontrado: true` con los campos de contracts/verificar-documento.md), padrón no encuentra el CUIT (`padron.encontrado: false`), ARCA no disponible/certificado inactivo (`padron.consultado: false`), tipo de documento no es CUIT/CUIL (sin clave `padron`, igual que hoy)

### Implementación de User Story 1

- [X] T008 [US1] Extender `ClienteController::verificarDocumento()` en `app/Http/Controllers/ClienteController.php`: tras la validación local exitosa de CUIT/CUIL, resolver `CertificadoFiscal::activo()`; si existe, obtener ticket de acceso vía `ClienteWsaa::obtenerTicketAcceso('ws_sr_padron_a13')` y llamar `ClientePadron::consultarConstancia()`; envolver en try/catch de `ArcaNoDisponibleException` para devolver `padron.consultado: false` sin romper la respuesta 200 (contracts/verificar-documento.md)
- [X] T009 [US1] Extender `resources/js/clientes.js`: en el handler `.js-verificar-documento`, leer la clave `padron` de la respuesta; si `encontrado: true`, completar `razon_social`, `domicilio_fiscal`, `localidad_fiscal` y el `<select>` de condición de IVA **sólo si el usuario no los editó manualmente desde la última consulta** (research.md R5 — trackear un flag "tocado" por campo, reseteado al abrir el modal/cambiar de cliente); si `consultado: false` o `encontrado: false`, mostrar toast informativo sin bloquear; **deshabilitar el botón "Verificar" (o ignorar clics) mientras una consulta anterior sigue en curso**, rehabilitándolo al recibir la respuesta o ante error (FR-012)
- [ ] T010 [US1] Verificar manualmente en navegador (Configuración & Ajustes con certificado de homologación activo): flujo completo de `quickstart.md` Escenario 1 — CUIT válido encontrado, CUIT válido no encontrado, ARCA no disponible, edición manual no pisada

**Checkpoint**: User Story 1 funciona de forma independiente — entregable como MVP.

---

## Phase 4: User Story 2 - Determinar tipo de comprobante con el padrón al convertir una orden en venta (Priority: P2)

**Goal**: al convertir una orden de Tiendanube o MercadoLibre (manual o automática), cuando el cliente es nuevo o no tiene condición de IVA cargada y la orden trae CUIT, se consulta el padrón para determinar el tipo de comprobante y completar los datos fiscales del Cliente creado, degradando al comportamiento actual si el padrón no responde.

**Independent Test**: convertir una orden con CUIT de un Responsable Inscripto real → Factura A y Cliente con datos completos; convertir una orden con cliente ya existente con condición de IVA cargada → se respeta esa condición sin consultar el padrón; convertir sin CUIT o con ARCA caída → comportamiento actual sin bloqueo.

### Tests para User Story 2

- [X] T011 [P] [US2] Test de feature `tests/Feature/Integraciones/TiendanubeConversionPadronTest.php`: mockea `ClientePadron` — casos: cliente nuevo + CUIT válido confirmado Responsable Inscripto → Venta tipo A y Cliente con datos fiscales completados (FR-007, FR-007b); cliente nuevo + CUIT no encontrado en padrón → fallback a aproximación por documento (FR-008); cliente existente con `condicion_iva_id` ya cargado → padrón NO se consulta, prevalece la condición existente (FR-007a); orden sin CUIT → sin cambios; ARCA no disponible → fallback sin excepción (FR-008); lote con una orden fallando el padrón no afecta a las demás (FR-009, sobre `convertirTodasLasListas()`)
- [X] T012 [P] [US2] Test de feature `tests/Feature/Integraciones/MercadoLibreConversionPadronTest.php`: mismos casos que T011, adaptados a `App\Services\MercadoLibre\DerivadorComprobante` (la rama FR-040c "sin condición de IVA pero con documento CUIT" es el punto de integración del padrón, en vez de la rama "sin ningún dato fiscal")

### Implementación de User Story 2

- [X] T013 [US2] Extender `App\Services\Tiendanube\ResolutorCliente::tipoComprobante()` en `app/Services/Tiendanube/ResolutorCliente.php`: cuando `$cliente` es `null` o no tiene `condicion_iva_id`, y el documento de la orden tiene forma de CUIT (11 dígitos), consultar `ClientePadron` (vía `ClienteWsaa`) antes de caer a `tipoComprobantePorDocumento()`; si el padrón responde con condición de IVA mapeada, usarla para derivar A/B y devolver también el `ResultadoConsultaPadron` para que `resolver()`/`completarDatosFiscalesSinPisar()` lo use al completar el Cliente (FR-007b); cualquier falla del padrón cae al comportamiento actual sin propagar excepción (contracts/conversion-orden.md)
- [X] T014 [US2] Aplicar el mismo cambio de patrón a `App\Services\MercadoLibre\DerivadorComprobante::derivar()` en `app/Services/MercadoLibre/DerivadorComprobante.php`, en la rama FR-040c (documento presente sin condición de IVA de Mercado Libre): consultar el padrón antes de aproximar sólo por `doc_tipo === 'CUIT'`, con el mismo criterio de precedencia y fallback que T013
- [ ] T015 [US2] Verificar manualmente en navegador/homologación: flujo completo de `quickstart.md` Escenario 2 y "Validar resiliencia en lote" — conversión manual y automática, con y sin CUIT, con cliente existente vs. nuevo, y con ARCA simulada como no disponible

**Checkpoint**: User Story 2 funciona de forma independiente, sin romper US1 ni el comportamiento previo de conversión.

---

## Phase 5: Polish & Cross-Cutting

- [X] T016 [P] Revisar que ningún log/`arca_logs_auditoria` existente se vea afectado por las nuevas llamadas a padrón (confirmar si corresponde loguear también estas consultas, dado que esa tabla es específica de WSAA/WSFEv1 — decisión de implementación, no bloqueante)
- [X] T017 [P] Ejecutar la suite completa de tests (`php artisan test` o equivalente) y confirmar que no se rompió ningún test existente de spec 014, 034, 019/017 (Tiendanube) ni 011/012/013 (MercadoLibre)
- [ ] T018 Revisar los ítems del checklist `specs/037-padron-arca-cuit/checklists/fiscal-resiliencia.md` contra la implementación final antes de dar la feature por terminada

---

## Dependencies & Execution Order

- **Setup (Phase 1)** → **Foundational (Phase 2)**: bloquean todo lo demás (T001, T003 son prerequisito de T008 y T013/T014).
- **User Story 1 (Phase 3)** y **User Story 2 (Phase 4)** son independientes entre sí una vez completada la Fase 2 — pueden implementarse en paralelo o en cualquier orden; US1 es MVP por prioridad (P1).
- **Polish (Phase 5)** depende de que ambas historias estén implementadas.

## Parallel Example

```
# Tras completar T001-T006 (Setup + Foundational):
Task T007 (test US1) y T011/T012 (tests US2) se pueden lanzar en paralelo — archivos de test distintos.
Task T008 (US1, ClienteController) y T013/T014 (US2, ResolutorCliente/DerivadorComprobante) tocan
archivos distintos y no comparten estado — paralelizables entre sí una vez que T003/T004 (Foundational) están listos.
```

## Implementation Strategy

**MVP first**: implementar sólo User Story 1 (T001-T010) entrega valor de forma independiente — el
botón "Verificar" del modal de cliente queda funcional con consulta real a ARCA, sin tocar la
conversión de órdenes. User Story 2 (T011-T015) se puede sumar después sin retrabajo, reusando el
mismo `ClientePadron`/`ResultadoConsultaPadron` de la Fase 2.
