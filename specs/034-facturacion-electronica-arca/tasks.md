# Tasks: Facturación Electrónica (ARCA/AFIP)

**Input**: Design documents from `/specs/034-facturacion-electronica-arca/`
**Prerequisites**: plan.md, research.md, data-model.md, contracts/emision-comprobante.md, quickstart.md

**Tests**: incluidos — Principio IV de la constitución exige tests para lógica de CAE/numeración/
cálculo fiscal; este módulo es el caso central de ese principio.

**Organization**: agrupadas por User Story para permitir entrega incremental (US1 = MVP).

## Phase 1: Setup

- [X] T001 Crear migraciones `puntos_venta`, `certificados_fiscales`, `comprobantes_fiscales`, `arca_logs_auditoria` en `database/migrations/` según `data-model.md`
- [X] T002 [P] Crear modelos `App\Models\PuntoVenta`, `App\Models\CertificadoFiscal` (con accessor/mutator que cifra `ruta_certificado`/`ruta_clave_privada` en disco vía `Crypt`), `App\Models\ComprobanteFiscal` (con relación polimórfica `comprobantable` y `SoftDeletes`), `App\Models\ArcaLogAuditoria`
- [X] T003 [P] Agregar relación `morphOne(ComprobanteFiscal::class, 'comprobantable')` a `App\Models\Venta`, `App\Models\Compra` y `App\Models\NotaCreditoDebito`
- [X] T004 Crear carpeta `app/Services/Arca/` con clases de excepción base en `app/Services/Arca/Excepciones/ArcaException.php`, `CertificadoNoConfiguradoException.php`, `ArcaRechazoException.php`, `ArcaNoDisponibleException.php`
- [X] T005 Configurar `storage/app/arca/` (agregar a `.gitignore` si no está cubierto por `storage/app`) y agregar variables de entorno `ARCA_AMBIENTE`, `ARCA_WSDL_WSAA`, `ARCA_WSDL_WSFEV1` en `.env.example`

**Checkpoint**: estructura base creada, sin lógica de negocio todavía.

## Phase 2: Foundational (bloqueante para todas las user stories)

- [X] T006 Implementar `App\Services\Arca\ClienteWsaa` (genera TRA, firma CMS con `openssl_pkcs7_sign`, llama WSAA, cachea Ticket de Acceso vía `Cache::remember`) en `app/Services/Arca/ClienteWsaa.php`
- [X] T007 Implementar `App\Services\Arca\ClienteWsfev1` (wrapper de `SoapClient` con métodos `solicitarCae`, `consultarUltimoAutorizado`, `consultarComprobante`) en `app/Services/Arca/ClienteWsfev1.php`
- [X] T008 [P] Implementar `App\Services\Arca\MapeadorComprobante` (traduce Venta/Compra/NC-ND del CRM al array esperado por WSFEv1) en `app/Services/Arca/MapeadorComprobante.php`
- [X] T009 [P] Implementar `App\Services\Arca\ValidadorDatosFiscales` (valida CUIT según Tipo de Comprobante, FR-009) en `app/Services/Arca/ValidadorDatosFiscales.php`
- [X] T010 Implementar `App\Services\Arca\EmisorComprobante::emitir()` y `::verificarPendiente()` según `contracts/emision-comprobante.md`, registrando `ArcaLogAuditoria` en cada rama (éxito/rechazo/error) en `app/Services/Arca/EmisorComprobante.php`
- [X] T011 [P] Tests unitarios de `MapeadorComprobante` (mapeo correcto de tipos de comprobante, IVA, totales) en `tests/Unit/Services/Arca/MapeadorComprobanteTest.php`
- [X] T012 [P] Tests unitarios de `ValidadorDatosFiscales` (CUIT requerido para tipo A, opcional para B/C) en `tests/Unit/Services/Arca/ValidadorDatosFiscalesTest.php`

**Checkpoint**: servicios core de ARCA listos y testeados con `SoapClient` mockeado — ninguna user story depende de red real todavía.

---

## Phase 3: User Story 1 - Emitir una Venta con CAE real (Priority: P1) 🎯 MVP

**Goal**: al confirmar el cobro de una Venta, el sistema obtiene CAE real de ARCA y lo muestra en el PDF sin watermark.

**Independent Test**: cobrar una Venta con cliente y productos cargados; verificar CAE + vencimiento + numeración real + PDF sin watermark (quickstart.md Escenario 1).

- [X] T013 [P] [US1] Test feature: cobro de Venta obtiene CAE y persiste `ComprobanteFiscal` aprobado en `tests/Feature/EmisionComprobanteVentaTest.php`
- [X] T014 [US1] Conectar la confirmación de cobro existente a `EmisorComprobante::emitir()`, capturando `CertificadoNoConfiguradoException` para aplicar el fallback FR-014 en `app/Http/Controllers/VentaController.php`
- [X] T015 [US1] Actualizar el generador de PDF de "Ver Detalle" de Venta para incluir CAE, vencimiento de CAE y código de barras/QR fiscal, y ocultar el watermark "NO VÁLIDO COMO FACTURA" cuando el `ComprobanteFiscal` está `aprobado`, en la vista/servicio de PDF existente de Ventas
- [X] T016 [US1] Crear pantalla "Configuración de Facturación Electrónica" (modal Bootstrap + AJAX, Select2 si aplica) para cargar certificado, CUIT, ambiente y gestionar Puntos de Venta, en `resources/views/configuracion/facturacion-electronica/index.blade.php` + `app/Http/Controllers/FacturacionElectronicaController.php`
- [X] T017 [US1] Agregar el bloque de configuración de Facturación Electrónica al sidebar/menú de Configuración & Ajustes en `resources/views/elements/sidebar.blade.php`

**Checkpoint**: User Story 1 completa y demostrable de forma independiente — MVP entregable.

---

## Phase 4: User Story 4 - Manejo de errores y caída del servicio ARCA (Priority: P1)

**Goal**: rechazos y caídas de ARCA se comunican claramente sin dejar la Venta en estado ambiguo ni duplicar comprobantes.

**Independent Test**: simular rechazo de WSFEv1 y timeout; verificar toast de error, Venta sin comprobante fiscal, sin numeración consumida (quickstart.md Escenario 2 y 3).

- [X] T018 [P] [US4] Test feature: rechazo de ARCA por CUIT inválido no crea `ComprobanteFiscal` aprobado y deja la Venta en "A Cobrar" en `tests/Feature/EmisionComprobanteRechazoTest.php`
- [X] T019 [P] [US4] Test feature: timeout simulado + reintento manual reconcilia vía `verificarPendiente()` sin duplicar comprobante, en `tests/Feature/EmisionComprobanteRechazoTest.php` (caso adicional) o archivo nuevo `EmisionComprobanteReintentoTest.php`
- [X] T020 [US4] Manejar `ArcaRechazoException`/`ArcaNoDisponibleException` en el flujo de cobro de Venta (T014) mostrando el mensaje vía toast Toastr (regla de diseño CLAUDE.md #3), sin recargar la página
- [X] T021 [US4] Implementar el banner de "certificado ARCA vencido/no configurado" en la pantalla de Configuración de Facturación Electrónica (T016), bloqueando el guardado de nueva configuración si el certificado cargado ya está vencido

**Checkpoint**: User Story 1 + 4 combinadas cubren un circuito de Ventas robusto ante fallos — listo para uso real controlado.

---

## Phase 5: User Story 2 - Emitir una Compra con datos fiscales reales (Priority: P2)

**Goal**: el circuito de Compras (spec 030) registra los datos fiscales del comprobante recibido del Proveedor, sin watermark cuando corresponde.

**Independent Test**: registrar una Compra con Tipo de Comprobante A; verificar que el documento imprimible deja de mostrar el watermark cuando el comprobante tiene datos fiscales completos (quickstart.md, adaptado a Compras).

- [X] T022 [P] [US2] Test feature: guardar una Compra con datos fiscales completos crea `ComprobanteFiscal` (sin solicitar CAE propio) en `tests/Feature/RegistroComprobanteCompraTest.php`
- [X] T023 [US2] Conectar el guardado de Compra existente para crear el `ComprobanteFiscal` asociado a partir de los datos ya cargados en el formulario (Tipo, Punto de Venta, Número, CAE si el proveedor lo declara), sin llamar a `EmisorComprobante::emitir()` (FR-015), en `app/Http/Controllers/CompraController.php`
- [X] T024 [US2] Actualizar el documento imprimible de "Detalle de Compra" para ocultar el watermark "NO VÁLIDO COMO FACTURA" cuando el `ComprobanteFiscal` asociado tiene datos fiscales completos

**Checkpoint**: Compras alineadas con Ventas en materia de comprobante fiscal, sin duplicar el circuito de emisión (que es exclusivo de Ventas).

---

## Phase 6: User Story 3 - Emitir Notas de Crédito/Débito con CAE (Priority: P2)

**Goal**: el wizard de NC/ND de Ventas (spec 008) obtiene CAE real, referenciando el comprobante original.

**Independent Test**: sobre una Venta con CAE ya emitido, crear una NC que afecta stock y verificar que obtiene su propio CAE referenciando el comprobante original (quickstart.md, adaptado).

- [X] T025 [P] [US3] Test feature: crear NC de Crédito sobre Venta con CAE obtiene su propio CAE referenciando `comprobante_ajustado_id`, en `tests/Feature/EmisionComprobanteNotaCreditoDebitoTest.php`
- [X] T026 [US3] Conectar el wizard existente "Crear NC/ND" (Paso 2, al guardar) a `EmisorComprobante::emitir()`, pasando `comprobante_ajustado_id` = el `ComprobanteFiscal` de la Venta original, en el controlador de NC/ND existente (spec 008)
- [ ] T027 [US3] Actualizar el PDF de NC/ND para mostrar su propio CAE y vencimiento, con referencia visible al comprobante que ajusta — **pendiente**: no existe todavía una vista PDF/imprimible propia de NC/ND en el proyecto (fuera del alcance de specs previas); la emisión de CAE para NC/ND ya está conectada y testeada (T025/T026), sólo falta el documento imprimible cuando se releve/cree esa pantalla

**Checkpoint**: las 4 user stories completas — módulo funcionalmente cerrado según spec.

---

## Phase 7: Polish & Cross-Cutting

- [X] T028 [P] Implementar bloqueo de inmutabilidad (FR-012): impedir editar Tipo de Comprobante/cliente/ítems de una Venta/Compra cuyo `ComprobanteFiscal` está `aprobado`, en los FormRequests de edición existentes de Ventas/Compras
- [X] T028b [P] Test feature: intentar editar Tipo de Comprobante/ítems de una Venta con `ComprobanteFiscal` aprobado es rechazado (FR-012), en `tests/Feature/ComprobanteInmutableTest.php`
- [X] T029 [P] Comando artisan `arca:avisar-vencimiento-certificado` (o integración con el cron existente) que revisa `certificados_fiscales.fecha_vencimiento` y genera el aviso de FR-016, en `app/Console/Commands/ArcaAvisarVencimientoCertificado.php`
- [X] T030 Actualizar `docs/documentacion_principal_crm.md` §7 y `docs/modelo_datos.md` §9 quitando Facturación Electrónica de "pendientes" y documentando el modelo final (Principio I de la constitución)
- [ ] T031 Ejecutar quickstart.md Escenario 1 y 2 manualmente contra el ambiente de homologación de ARCA y documentar resultados

## Dependencies & Execution Order

- **Setup (Phase 1)** → **Foundational (Phase 2)**: bloquean todo lo demás; sin excepciones.
- **User Story 1 (Phase 3, P1)**: depende sólo de Foundational. Es el MVP.
- **User Story 4 (Phase 4, P1)**: depende de User Story 1 (necesita el flujo de emisión ya conectado para interceptar sus errores). Recomendado completarla junto con US1 antes de considerar el módulo usable en producción.
- **User Story 2 (Phase 5, P2)**: depende sólo de Foundational (no depende de US1 — Compras no llama a `EmisorComprobante::emitir()`). Puede desarrollarse en paralelo a US1/US4 por un equipo/sesión distinta.
- **User Story 3 (Phase 6, P2)**: depende de User Story 1 (necesita comprobantes de Venta con CAE ya existentes para poder ajustarlos).
- **Polish (Phase 7)**: depende de todas las user stories relevantes que toca (T028 depende de US1+US2, T030 depende de todo el módulo).

## Parallel Execution Examples

- Dentro de Setup: T002 y T003 en paralelo (modelos distintos archivos).
- Dentro de Foundational: T008 y T009 en paralelo; T011 y T012 en paralelo una vez T008/T009 listos.
- Entre user stories: US2 (Phase 5) puede avanzar en paralelo a US1/US4 (Phase 3/4) porque no comparte archivos de controlador ni depende de `EmisorComprobante::emitir()`.
- Dentro de US1: T013 (test) en paralelo con el desarrollo de T016/T017 (pantalla de configuración, archivos distintos); T014/T015 son secuenciales entre sí (T015 depende de que T014 ya persista el `ComprobanteFiscal`).

## Implementation Strategy

**MVP = User Story 1 + User Story 4** (ambas P1): sin el manejo robusto de errores (US4), el
módulo no es seguro de usar con clientes reales — están agrupadas como el primer incremento
entregable, no sólo US1 sola. User Story 2 (Compras) y User Story 3 (NC/ND) son extensiones P2 que
se entregan después, cada una independientemente demostrable.
