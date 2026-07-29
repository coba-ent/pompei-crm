# Implementation Plan: Verificación de documento fiscal (CUIT/CUIL)

**Branch**: `014-verificacion-documento-fiscal` | **Date**: 2026-07-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/014-verificacion-documento-fiscal/spec.md`

## Summary

Exponer la validación de dígito verificador de CUIT/CUIL que ya existe en el backend
(`App\Rules\CuitValido`) en dos puntos donde hoy falta: (1) un botón "Verificar" + auto-formato con
guiones en el campo N° de Doc de los modales de Cliente y Proveedor (paridad estructural con
Contagram real, confirmada con capturas), y (2) la conversión automática de órdenes de Mercado Libre,
que hoy usa `comprador_doc_numero` sin validarlo cuando deriva el tipo de comprobante por
aproximación (rama sin condición de IVA informada). En ambos casos se reusa el mismo algoritmo ya
existente — no se reimplementa ni se relaja la validación bloqueante que ya corre al guardar.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12), JavaScript (Vite, sin framework de componentes — jQuery +
DataTables + Select2, patrón ya usado en `resources/js/clientes.js`/`proveedores.js`)

**Primary Dependencies**: Laravel (FormRequest, rutas), Bootstrap 5 (NexaDash), jQuery, Toastr — todas
ya presentes en el proyecto; no se agrega ninguna dependencia nueva.

**Storage**: MySQL, sin cambios de esquema (no hay columnas ni tablas nuevas — el campo `cuit` ya
existe en `clientes` y `proveedores`).

**Testing**: PHPUnit/Pest (Laravel), `tests/Feature/` — mismo patrón que
`tests/Feature/Integraciones/MercadoLibreClienteNuevoTest.php` para la parte de Mercado Libre, y un
Feature test nuevo para el endpoint de verificación.

**Target Platform**: Web (mismo stack del resto del CRM), servidor Hostinger compartido para el demo.

**Project Type**: Aplicación web monolítica Laravel + Blade (no aplica la distinción frontend/backend
como proyectos separados).

**Performance Goals**: N/A — la validación es un cálculo aritmético trivial (O(1), 10 dígitos), sin
requisito de performance específico más allá de "instantáneo" (ya cubierto por SC-001).

**Constraints**: Ninguna consulta a servicios externos (ARCA/padrón sigue fuera de alcance, principio
III de la constitución: comprobante se deriva de condición de IVA, no del documento, salvo el
fallback ya existente).

**Scale/Scope**: 2 pantallas existentes modificadas (modal Cliente, modal Proveedor), 1 servicio
existente modificado (`DerivadorComprobante`), 1 servicio existente modificado
(`ResolutorCliente`), 1 endpoint nuevo reusado por ambos modales.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: `docs/documentacion_principal_crm.md`
  §2.1 ya se actualizó (29/07/2026) separando la validación local de dígito verificador (en alcance)
  de la verificación contra ARCA/padrón (fuera de alcance). ✅ Cumple.
- **Principio II (Desarrollo spec-driven)**: esta es la spec de la feature; no se salta directo a
  código. ✅ Cumple.
- **Principio III (Corrección fiscal innegociable)**: el tipo de comprobante se sigue derivando de la
  condición de IVA cuando está disponible; esta feature sólo endurece la rama de aproximación por
  documento (FR-040c de spec 012) para que no confíe en un número matemáticamente imposible. No se
  toca la regla "no se permite emitir sin condición de IVA cargada" — cuando no hay condición de IVA
  NI documento válido, se sigue asumiendo Consumidor Final (ya vigente, FR-040a). ✅ Cumple, refuerza
  el principio en vez de tensionarlo.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: `DerivadorComprobante` y
  `ResolutorCliente` afectan qué comprobante se emite y qué CUIT queda cargado en un Cliente — se
  requieren tests (ver Fase 2/tasks). El endpoint de verificación manual es UX pura sobre una regla ya
  testeada (`CuitValido` ya tiene su propia cobertura); alcanza con un test de Feature liviano.
- **Principio V (Convenciones Laravel + dominio en español)**: nombres de rutas/métodos en español
  (`verificarDocumento`), reuso de `FormRequest`/Blade existentes. ✅ Cumple.
- **Reglas de diseño de CLAUDE.md** (#2 modales AJAX sin recarga, #5 Select2 no aplica acá): el botón
  "Verificar" es una llamada AJAX puntual dentro del modal ya existente, sin recargar la página ni
  cerrar el modal — coherente con la regla #2 aunque no sea un alta/edición en sí.

No hay violaciones que requieran justificar en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/014-verificacion-documento-fiscal/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output (sin entidades nuevas — documenta por qué)
├── quickstart.md         # Phase 1 output
├── contracts/            # Phase 1 output (contrato del endpoint de verificación)
└── tasks.md              # Phase 2 output (/speckit-tasks, no generado por /speckit-plan)
```

### Source Code (repository root)

Proyecto Laravel monolítico existente — no se crea estructura nueva, se modifican/agregan archivos
dentro de los directorios ya establecidos:

```text
app/
├── Http/Controllers/
│   ├── ClienteController.php          # + acción verificarDocumento()
│   └── ProveedorController.php        # + acción verificarDocumento()
├── Rules/
│   └── CuitValido.php                 # SIN CAMBIOS — se reusa esValido() como está
└── Services/MercadoLibre/
    └── DerivadorComprobante.php       # único punto de saneamiento (ver research.md R4);
                                        # ResolutorCliente.php NO se toca — ya consume el array
                                        # ya saneado que devuelve derivar()

resources/
├── views/
│   ├── clientes/_modal_form.blade.php     # botón "Verificar" junto a `name="cuit"`
│   └── proveedores/_modal_form.blade.php  # ídem
└── js/
    ├── clientes.js                    # auto-formato + handler del botón Verificar
    └── proveedores.js                 # ídem

routes/web.php                          # 2 rutas nuevas: clientes/verificar-documento,
                                         # proveedores/verificar-documento

tests/Feature/
├── VerificacionDocumentoClienteTest.php       # nuevo
├── VerificacionDocumentoProveedorTest.php     # nuevo
└── Integraciones/
    └── MercadoLibreClienteNuevoTest.php       # + casos nuevos (CUIT/CUIL inválido)
```

**Structure Decision**: no aplica ninguna de las opciones de proyecto multi-paquete del template — es
una extensión puntual sobre la app Laravel monolítica ya existente, siguiendo exactamente la
convención de controllers/services/views/js que ya usa el resto del CRM (ver `clientes.js`/
`proveedores.js` como referencia directa de patrón, tal como indica CLAUDE.md §5).

## Complexity Tracking

*Sin violaciones — sección no aplica.*
