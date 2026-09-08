# Tasks: Reconocedor de CUIT por ARCA en Proveedores

**Feature**: `100-reconocedor-cuit-proveedor` | **Fecha**: 2026-09-07

**Input**: [spec.md](./spec.md) · [plan.md](./plan.md) · [research.md](./research.md) ·
[data-model.md](./data-model.md) · [contracts/](./contracts/) · [quickstart.md](./quickstart.md)

**Tests**: incluidos. La constitución (Principio IV) los exige para lógica con impacto fiscal, y acá
los datos del padrón alimentan comprobantes de compra. Además son la red de seguridad del refactor.

---

## Estrategia de ejecución

El orden **no** es negociable en un punto: **el refactor (Fase 2) va antes que la funcionalidad
nueva (Fase 3+) y se valida solo**. Motivo (checklist CHK026): el refactor toca tres consumidores en
producción, dos de ellos integraciones que corren por cron. Si se mezcla con la feature nueva y algo
se rompe, no se sabe cuál de las dos causas fue. Aislado, la suite existente debe pasar sin
modificaciones: eso es la prueba de que el refactor no cambió nada.

**MVP**: Fases 1-4 (US1 + US4). Con eso el reconocedor ya funciona y degrada bien — que es el reporte
original del cliente. US2 y US3 son mejoras sobre esa base.

---

## Phase 1: Setup

- [X] T001 Crear la rama `100-reconocedor-cuit-proveedor` desde `main` y confirmar que la suite arranca en verde con `php artisan test` — *nota: se trabajó directamente sobre `main` (mismo patrón que los commits recientes del proyecto), no se creó rama separada*

---

## Phase 2: Foundational — extracción del servicio compartido (BLOQUEANTE)

**Objetivo**: unificar en un solo lugar la consulta al padrón hoy triplicada, sin cambiar ni un ápice
de comportamiento. Ver [contracts/consulta-padron-servicio.md](./contracts/consulta-padron-servicio.md)
y [research.md](./research.md) R1/R2.

**Regla de oro de esta fase**: no se toca ningún test existente. Si un test hay que modificarlo para
que pase, el refactor cambió comportamiento y está mal.

- [X] T002 Crear `app/Services/Arca/ConsultaPadron.php` con el método `consultar(?string $cuit): ?ResultadoConsultaPadron`, replicando exactamente la secuencia actual (normalización a dígitos, guard de 11 dígitos, `CertificadoFiscal::activo()`, consulta a `ws_sr_padron_a13`, y consulta best-effort e independiente a `ws_sr_constancia_inscripcion`), resolviendo colaboradores vía contenedor con `app()->makeWith()` para no invalidar los mocks de los tests existentes
- [X] T003 Agregar a `app/Services/Arca/ConsultaPadron.php` el método `paraModal(string $cuit): array` que traduce el resultado a las tres formas del contrato (`consultado:false` / `encontrado:false` / datos con claves nulas omitidas), portando los textos literales de mensaje desde `ClienteController::consultarPadron()` y resolviendo el **nombre** de la condición de IVA (no el id)
- [X] T004 [P] Migrar `app/Services/MercadoLibre/DerivadorComprobante.php` para que inyecte y use `ConsultaPadron::consultar()`, eliminando su método privado `consultarPadron()`
- [X] T005 [P] Migrar `app/Services/Tiendanube/ResolutorCliente.php` para que inyecte y use `ConsultaPadron::consultar()`, eliminando su método privado `consultarPadron()`
- [X] T006 Migrar `app/Http/Controllers/ClienteController.php` para que `verificarDocumento()` use `ConsultaPadron::paraModal()`, eliminando su método privado `consultarPadron()` y sus `use` que queden huérfanos
- [X] T007 Ejecutar `php artisan test` completo y confirmar verde **sin haber modificado ningún test**, con atención especial a `ClienteVerificarPadronTest` (respalda FR-018/SC-005) y a los tests de Mercado Libre y Tiendanube — verificado: `ClienteVerificarPadronTest`, `MercadoLibreConversionPadronTest` y `TiendanubeConversionPadronTest` en verde sin modificar
- [ ] T008 Commit aislado del refactor, para que sea revertible por separado si una integración fallara tras el deploy (CHK026)

**Checkpoint**: sin este punto en verde no se sigue. Todo lo que viene depende del servicio.

---

## Phase 3: User Story 1 — Autocompletar datos fiscales desde ARCA (P1) 🎯 MVP

**Meta**: que "Verificar" en el modal de Proveedor traiga razón social, domicilio, provincia,
localidad y condición de IVA del padrón.

**Test independiente**: abrir Nuevo Proveedor, ingresar un CUIT real, apretar Verificar y ver los
cinco campos cargados (quickstart M1).

### Tests

- [X] T009 [P] [US1] Crear `tests/Feature/ProveedorVerificarPadronTest.php` como espejo de `ClienteVerificarPadronTest.php`, con el setup de rol Admin, `CondicionIvaSeeder` y `CertificadoFiscal` activo, y los casos: padrón OK (devuelve los cinco datos), padrón sin condición de IVA (devuelve el resto), y documento no-CUIT (`aplica:false`)

### Implementación

- [X] T010 [US1] Extender `ProveedorController::verificarDocumento()` en `app/Http/Controllers/ProveedorController.php` para que, tras validar el dígito verificador con `CuitValido`, agregue la clave `padron` con `ConsultaPadron::paraModal()`, respetando el contrato de [contracts/verificar-documento-proveedor.md](./contracts/verificar-documento-proveedor.md); actualizar el docblock que hoy dice "sin consultar ARCA/padrón"
- [X] T010b [US1] En `resources/js/proveedores.js`, agregar los helpers `normalizarTexto()` y `buscarOpcionPorTexto($select, valor)` (matcheo por texto insensible a mayúsculas y acentos), que **no existen en este archivo** — están en `clientes.js:46-57` y en `proveedor-modal.js:105-116`; copiar la implementación vigente para no divergir
- [X] T011 [US1] En `resources/js/proveedores.js`, agregar `autocompletarDesdePadron(padron)` que complete `razon_social` y `domicilio_fiscal`, y resuelva **primero** `provincia_fiscal` (con `buscarOpcionPorTexto` de T010b) y **recién después** `localidad_fiscal` mediante la función `cargarLocalidades()` ya existente en ese archivo (FR-011), dejando el campo sin tocar si un valor no matchea ninguna opción (FR-012)
- [X] T012 [US1] En `resources/js/proveedores.js`, completar `condicion_iva_id` buscando la opción cuyo texto coincida exactamente con el nombre devuelto, disparando `.trigger('change')` para que se encadene la derivación del comprobante (US3)
- [X] T013 [US1] En `resources/js/proveedores.js`, extender el handler de `.js-verificar-documento` para que, además de pintar el resultado de validez actual, invoque el autocompletado cuando la respuesta traiga `padron`
- [X] T014 [US1] Compilar assets con `npm run build` y validar el escenario M1 de [quickstart.md](./quickstart.md) en el navegador — `npm run build` OK; **validación en navegador pendiente del usuario** (requiere certificado fiscal activo y CUIT real)

---

## Phase 4: User Story 4 — Degradación ante fallas de ARCA (P1) 🎯 MVP

**Meta**: que ninguna falla de ARCA impida dar de alta un proveedor.

**Test independiente**: desactivar el certificado fiscal, apretar Verificar, y confirmar que avisa y
que el proveedor se guarda igual (quickstart M5).

### Tests

- [X] T015 [P] [US4] Extender `tests/Feature/ProveedorVerificarPadronTest.php` con los casos de degradación: sin `CertificadoFiscal` activo, `ArcaNoDisponibleException` en la consulta de padrón, y CUIT no encontrado — verificando en cada uno el `mensaje` literal del contrato y que la respuesta sea HTTP 200
- [X] T016 [P] [US4] Agregar a `tests/Feature/ProveedorVerificarPadronTest.php` un caso que confirme que un CUIT con dígito verificador inválido responde `valido:false` **sin** invocar los servicios de ARCA (FR-002)

### Implementación

- [X] T017 [US4] En `resources/js/proveedores.js`, agregar `mostrarMensajePadron(padron)` que emita el toast correspondiente según la forma recibida: `consultado:false` → info, `encontrado:false` → info, éxito → success (FR-006), usando el helper de toast ya presente en el archivo
- [X] T018 [US4] En `resources/js/proveedores.js`, garantizar que ante una respuesta de falla **no se modifique ningún campo** del formulario (FR-007) — `autocompletarDesdePadron()` retorna de inmediato si `!padron.encontrado`
- [ ] T019 [US4] Validar los escenarios M5 y M6 de [quickstart.md](./quickstart.md) en el navegador, confirmando que el proveedor se guarda igual en ambos casos — **pendiente del usuario** (verificación manual en navegador)

**Checkpoint MVP**: con las fases 1-4 el reporte original del cliente queda resuelto.

---

## Phase 5: User Story 2 — No pisar lo editado a mano (P2)

**Meta**: que el autocompletado respete lo que el usuario ya escribió en esa sesión del modal.

**Test independiente**: escribir una razón social a mano, apretar Verificar y ver que sobrevive
mientras el resto se completa (quickstart M2).

- [X] T020 [US2] En `resources/js/proveedores.js`, agregar la constante `CAMPOS_PADRON` con los seis campos autocompletables (`razon_social`, `domicilio_fiscal`, `provincia_fiscal`, `localidad_fiscal`, `condicion_iva_id`, `tipo_comprobante_defecto`), el objeto `tocadoPadron` y los listeners `input change` que marcan cada campo como editado a mano
- [X] T021 [US2] En `resources/js/proveedores.js`, agregar `resetearTocadoPadron()` e invocarla desde la función de reseteo del formulario que corre al abrir el modal, de modo que el estado no se arrastre entre proveedores (FR-010)
- [X] T022 [US2] En `resources/js/proveedores.js`, condicionar cada asignación del autocompletado (T011/T012) a que el campo correspondiente no esté marcado como tocado (FR-009)
- [ ] T023 [US2] Validar los escenarios M2, M4 y M7 de [quickstart.md](./quickstart.md); en M7 confirmar que al **editar** un proveedor existente los campos precargados **sí** se sobrescriben, que es el comportamiento acordado (aclaración 2026-09-07) — **pendiente del usuario** (verificación manual en navegador)

---

## Phase 6: User Story 3 — Derivación del comprobante por defecto (P3)

**Meta**: sugerir el comprobante que el proveedor nos emite, con la regla de compra A/C/B.

**Test independiente**: cambiar la condición de IVA a mano y ver cómo cambia el comprobante
propuesto, sin consultar el padrón (quickstart M3).

- [X] T024 [US3] En `resources/js/proveedores.js`, agregar `derivarComprobantePorCondicionIva()` con la regla **de compra**: `Responsable Inscripto` → `A`, `Monotributista` → `C`, cualquier otra → `B`; incluir un comentario que explique por qué difiere de la de Cliente (en Proveedor el comprobante es el que **recibimos**) para que un refactor futuro no las unifique (FR-017)
- [X] T025 [US3] En `resources/js/proveedores.js`, enganchar esa función al evento `change` del select de condición de IVA, y hacerla respetar `tocadoPadron.tipo_comprobante_defecto` para no pisar una elección manual (FR-016)
- [ ] T026 [US3] Validar el escenario M3 de [quickstart.md](./quickstart.md) recorriendo las cinco condiciones de IVA, con foco en que **Monotributista dé Factura C** (es la diferencia deliberada respecto de Cliente) — **pendiente del usuario** (verificación manual en navegador)

---

## Phase 6b: Alta rápida de proveedor en el formulario de Compra (P1 — hallazgo de analyze)

**Contexto**: `resources/js/proveedor-modal.js` (el alta rápida de proveedor dentro del formulario de
Compra) **ya tiene** el autocompletado del padrón completo, pero hoy es código muerto porque el
endpoint no devuelve `padron`. **T010 lo activa solo.** Y su regla de comprobante es la de Cliente
(A/B), así que al activarse propondría Factura B para un Monotributista. Ver [research.md](./research.md) R7.

**Prioridad P1 pese al número de fase**: es la pantalla donde el dato se usa para registrar compras.
No puede quedar para después de US2/US3.

- [X] T026a [US3] Corregir `derivarComprobantePorCondicionIva()` en `resources/js/proveedor-modal.js:156-162` para que use la regla **de compra** (`Responsable Inscripto` → `A`, `Monotributista` → `C`, resto → `B`), reemplazando el comentario que hoy cita "docs §2.1" por la explicación de por qué difiere de Cliente (FR-020)
- [ ] T026b [US1] Verificar en el navegador que el autocompletado de `proveedor-modal.js` funciona correctamente al activarse con T010 (pasa de código muerto a código vivo sin haberse modificado): abrir un formulario de Compra, dar de alta un proveedor nuevo con CUIT y confirmar que completa los cinco campos fiscales — **pendiente del usuario**
- [X] T026c Confirmar que `resources/js/cliente-modal.js` conserva su regla A/B intacta —ahí **sí** es la correcta— y que ningún cambio de esta feature la tocó (FR-018) — verificado por lectura directa del archivo, sin cambios

---

## Phase 7: Polish & verificación cruzada

- [X] T027 [P] En `resources/js/proveedores.js`, agregar el flag `verificacionEnCurso` que impida consultas superpuestas por clicks repetidos, deshabilitando el botón mientras la consulta corre para dar feedback visible (FR-013; cubre además la brecha menor de accesibilidad CHK044)
- [X] T028 [P] Confirmar que el resultado visible de la verificación se limpia al cambiar el número o el tipo de documento, reutilizando `limpiarResultadoVerificacion()` que ya existe en `resources/js/proveedores.js` (FR-014)
- [X] T029 Ejecutar `php artisan test` completo y confirmar verde, incluidos los tests de Cliente, Mercado Libre y Tiendanube sin modificaciones (FR-018, SC-005)
- [ ] T030 Recorrer el checklist de aceptación completo de [quickstart.md](./quickstart.md), incluido **M9** (no regresión de Cliente: ahí Monotributista debe seguir proponiendo **Factura B**, no C) — **pendiente del usuario** (verificación manual en navegador)
- [X] T031 Revisar que `docs/documentacion_principal_crm.md` §2.3 refleje lo efectivamente implementado (ya actualizado en la fase de planificación: corrección de la afirmación sobre "misma validación de CUIT", regla A/C/B y servicio compartido)

---

## Dependencias

```text
Phase 1 (Setup)
   ↓
Phase 2 (Refactor del servicio) ◄── BLOQUEANTE para todo lo demás
   ↓
   ├─► Phase 3 (US1: autocompletado)     ─┐
   │        ↓                              │ MVP
   ├─► Phase 4 (US4: degradación)        ─┘
   │        ↓
   ├─► Phase 5 (US2: no pisar lo tocado)  ← depende del autocompletado de US1
   │        ↓
   └─► Phase 6 (US3: comprobante A/C/B)   ← depende del select de condición de IVA (US1/T012)
            ↓
        Phase 7 (Polish)
```

**Dependencias reales entre historias** (no son totalmente independientes, y conviene decirlo):

- **US4 es independiente de US1**: los caminos de falla se pueden probar sin que el camino feliz esté
  terminado. Van juntas en el MVP porque juntas resuelven el reporte del cliente.
- **US2 depende de US1**: no se puede "no pisar" lo que todavía no se completa.
- **US3 depende de T012**: necesita que el select de condición de IVA dispare `change`. Pero su regla
  A/C/B se puede probar a mano sin el padrón.

## Paralelismo

- **T004 y T005** son archivos distintos y sin relación entre sí: se pueden hacer en paralelo.
- **T009, T015 y T016** son casos del mismo archivo de test; marcados `[P]` porque se pueden escribir
  en paralelo, pero se integran en un solo archivo — coordinar al unir.
- **T027 y T028** tocan zonas distintas de `proveedores.js`: paralelizables con cuidado de merge.
- **T006 no es paralelizable con T002/T003**: consume el servicio que esas tareas crean.

## Resumen

| Fase | Tareas | Historia |
|------|--------|----------|
| 1. Setup | T001 | — |
| 2. Refactor (bloqueante) | T002-T008 | — |
| 3. Autocompletado | T009-T014 (incl. T010b) | US1 (P1) |
| 4. Degradación | T015-T019 | US4 (P1) |
| 5. No pisar lo tocado | T020-T023 | US2 (P2) |
| 6. Comprobante A/C/B | T024-T026 | US3 (P3) |
| 6b. Modal de Compra | T026a-T026c | US1/US3 (P1) |
| 7. Polish | T027-T031 | — |

**Total**: 35 tareas. **MVP**: T001-T019 + **T026a** (la corrección de la regla en el modal de Compra
entra al MVP: T010 activa ese modal aunque nadie lo toque, y dejarlo con la regla A/B introduciría un
error fiscal nuevo en la pantalla de Compra).

**Sin tareas de migración ni de modelo de datos**: esta feature no toca el esquema
([data-model.md](./data-model.md)).
