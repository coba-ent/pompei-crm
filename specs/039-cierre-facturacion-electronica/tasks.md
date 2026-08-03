# Tasks: Cierre de Facturación Electrónica — PDF NC/ND, Mi Perfil y Recibos

**Input**: Design documents from `/specs/039-cierre-facturacion-electronica/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, quickstart.md

**Tests**: incluidos para User Story 1 (impacto fiscal — Principio IV de la constitución) y para
la persistencia de Mi Perfil; no se generan tests exhaustivos de Recibos por ser un documento no
fiscal (mismo criterio que otros documentos imprimibles ya existentes en el proyecto sin test
dedicado).

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [X] T001 Migración `create_datos_empresa_table` (`razon_social`, `cuit`, `domicilio_fiscal`, `condicion_iva`, `ingresos_brutos`, `ruta_logo`, timestamps) en `database/migrations/`
- [X] T002 Configurar disco `public` para `storage/app/public/empresa/` si no existe symlink (`php artisan storage:link` documentado en quickstart.md, sin cambios de código si ya existe de specs previas)

**Checkpoint**: esquema listo, sin dependencias de negocio todavía.

---

## Phase 2: Foundational

- [X] T003 Implementar `App\Models\DatosEmpresa` con `fillable`, y método estático `instancia(): ?self` en `app/Models/DatosEmpresa.php`
- [X] T004 [P] Crear partial Blade reutilizable `resources/views/pdf/partials/encabezado-emisor.blade.php` que recibe `$datosEmpresa` (nullable) y renderiza Razón Social/CUIT/Domicilio Fiscal/Condición de IVA/logo, u omite el bloque si es `null` (FR-008)

**Checkpoint**: modelo y partial de encabezado listos — ninguna user story depende de más infraestructura compartida.

---

## Phase 3: User Story 1 - Ver el PDF de una Nota de Crédito/Débito con su CAE (Priority: P1) 🎯 MVP

**Goal**: cerrar el pendiente T027 de spec 034 — cada NC/ND con CAE tiene su propio documento imprimible.

**Independent Test**: sobre una Venta con CAE, crear una NC de Crédito y abrir su "Ver Detalle"; verificar CAE propio, vencimiento y referencia al comprobante ajustado (quickstart.md Escenario 2).

- [X] T005 [P] [US1] Test feature: el PDF de una NC/ND con `ComprobanteFiscal` aprobado expone CAE, vencimiento y referencia al comprobante de Venta ajustado; sin CAE muestra watermark, en `tests/Feature/PdfNotaCreditoDebitoTest.php`
- [X] T006 [US1] Crear vista `resources/views/notas-credito-debito/pdf.blade.php` (misma estructura que `resources/views/ventas/pdf.blade.php`: watermark condicional, bloque CAE/vencimiento/QR fiscal vía `NotaCreditoDebito::comprobanteFiscal`, bloque nuevo "Comprobante que ajusta" con tipo/número/fecha del `ComprobanteFiscal` de `NotaCreditoDebito::venta`) incluyendo el partial `encabezado-emisor` (T004)
- [X] T007 [US1] Agregar acción `pdf(NotaCreditoDebito $notaCreditoDebito)` a `app/Http/Controllers/NotaCreditoDebitoController.php` y su ruta, devolviendo el PDF con `Content-Disposition: inline` (regla de diseño CLAUDE.md #4)
- [X] T008 [US1] Agregar la acción "Ver Detalle" a la sección de Notas de Crédito/Débito del Detalle de Venta, abriendo el PDF vía `window.AppPdf.abrir(url, titulo)` (regla de diseño CLAUDE.md #4), en la vista de Detalle de Venta existente (spec 008)

**Checkpoint**: User Story 1 completa y demostrable de forma independiente — cierra T027 de spec 034.

---

## Phase 4: User Story 2 - Cargar los datos fiscales del negocio en "Mi Perfil" (Priority: P1)

**Goal**: encabezado emisor real disponible para los PDFs de Venta y NC/ND.

**Independent Test**: cargar Mi Perfil con datos y logo; verificar que aparecen en el PDF de una Venta ya emitida (quickstart.md Escenario 1).

- [X] T009 [P] [US2] Test feature: guardar Mi Perfil persiste los datos y el logo; rechaza un archivo de logo inválido con error claro (FR-014), en `tests/Feature/MiPerfilTest.php`
- [X] T010 [US2] Crear `app/Http/Controllers/MiPerfilController.php` con `index()` y `guardar()` (valida CUIT 11 dígitos, tipo de archivo del logo; usa `DatosEmpresa::instancia()`/`updateOrCreate` dado el patrón single-row)
- [X] T011 [US2] Agregar rutas de Mi Perfil bajo `Route::middleware('permiso:configuracion.funciones')` (mismo esquema de permisos que Facturación Electrónica, spec 034) en `routes/web.php`
- [X] T012 [US2] Crear pantalla `resources/views/configuracion/mi-perfil/index.blade.php` (modal Bootstrap + AJAX, Select2 en Condición de IVA reutilizando el catálogo de Cliente/Proveedor, preview de logo) + `resources/js/mi-perfil.js`
- [X] T013 [US2] Agregar el link "Mi Perfil" al sidebar de Configuración & Ajustes en `resources/views/elements/sidebar.blade.php`
- [X] T014 [US2] Actualizar `resources/views/ventas/pdf.blade.php` para incluir el partial `encabezado-emisor` (T004) con `DatosEmpresa::instancia()`
- [X] T015 [US2] Incluir el partial `encabezado-emisor` en `resources/views/notas-credito-debito/pdf.blade.php` (creada en T006) — si T006 ya se hizo primero, sólo verificar que ya lo incluye

**Checkpoint**: User Story 1 + 2 combinadas — los PDFs de Venta y NC/ND ya muestran el encabezado real del negocio emisor.

---

## Phase 5: User Story 3 - Imprimir un Recibo de Cobro o Pago (Priority: P2)

**Goal**: documento no fiscal para Cobranzas y Pagos, con la estructura documentada como mejor esfuerzo (sin capturas reales de Contagram).

**Independent Test**: sobre una Venta con una Cobranza registrada, abrir "Ver Recibo"; repetir con un Pago a Proveedor (quickstart.md Escenario 3).

- [X] T016 [P] [US3] Crear vista `resources/views/recibos/pdf.blade.php` recibiendo un contexto genérico (emisor vía `encabezado-emisor`, contraparte —Cliente o Proveedor—, medio, monto, fecha, número `REC-{id}`)
- [X] T017 [US3] Agregar acción `reciboCobranza(Cobranza $cobranza)` a `app/Http/Controllers/VentaController.php` (o controlador de Cobranzas existente) + ruta, devolviendo el PDF inline
- [X] T018 [US3] Agregar acción `reciboPago(Pago $pago)` a `app/Http/Controllers/CompraController.php` (o controlador de Pagos existente) + ruta, devolviendo el PDF inline
- [X] T019 [US3] Agregar la acción "Ver Recibo" en la tabla de Cobranzas del Detalle de Venta y en la tabla de Pagos del Detalle de Compra, abriendo el PDF vía `window.AppPdf.abrir` (regla de diseño CLAUDE.md #4)
- [X] T020 [US3] Manejar el caso de Cobranza/Pago eliminado o inexistente devolviendo un error claro vía toast (Edge Case de spec.md) en las acciones de T017/T018

**Checkpoint**: las 3 user stories completas — módulo funcionalmente cerrado según spec.

---

## Phase 6: Polish & Cross-Cutting

- [X] T021 [P] Actualizar `docs/documentacion_principal_crm.md` §5 y §7 quitando Mi Perfil/Recibos de "pendientes", documentando el cierre de T027 (spec 034) y dejando anotada la brecha de relevamiento con capturas para Mi Perfil y Recibos (Principio I de la constitución)
- [X] T022 [P] Actualizar `docs/modelo_datos.md` con la tabla `datos_empresa`
- [ ] T023 Ejecutar quickstart.md Escenario 1, 2 y 3 manualmente en el navegador y documentar resultados

## Dependencies & Execution Order

- **Setup (Phase 1)** → **Foundational (Phase 2)**: bloquean todo lo demás.
- **User Story 1 (Phase 3, P1)**: depende sólo de Foundational (el partial de encabezado se incluye vacío si Mi Perfil no está cargado, FR-008) — puede implementarse y demostrarse antes que US2.
- **User Story 2 (Phase 4, P1)**: depende sólo de Foundational. T014/T015 requieren que T006 (vista de NC/ND) y la vista de Venta ya existan — T015 es trivial si T006 ya incluyó el partial.
- **User Story 3 (Phase 5, P2)**: depende de Foundational (partial de encabezado). Independiente de US1.
- **Polish (Phase 6)**: depende de todas las user stories.

## Parallel Execution Examples

- Dentro de Foundational: T003 y T004 en paralelo (archivos distintos).
- Entre user stories: US1 (Phase 3) y US3 (Phase 5) pueden avanzar en paralelo — no comparten controladores ni vistas.
- Dentro de US1: T005 (test) en paralelo con el desarrollo de T006 (vista, mismo enfoque que spec 034: test primero, implementación valida contra él).
- Dentro de US2: T009 (test) en paralelo con T010-T013.

## Implementation Strategy

**MVP = User Story 1** (P1): cierra el pendiente explícito T027 de spec 034, el más urgente porque
ya hay NC/ND con CAE real sin documento imprimible en producción. User Story 2 (Mi Perfil) es P1
también pero puede seguir en paralelo — mejora el encabezado de PDFs ya funcionales, no bloquea su
uso (FR-008 garantiza degradación elegante). User Story 3 (Recibos) es P2, extensión independiente
que se entrega después.
