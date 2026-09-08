# Implementation Plan: Reconocedor de CUIT por ARCA en Proveedores

**Branch**: `100-reconocedor-cuit-proveedor` | **Date**: 2026-09-07 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/100-reconocedor-cuit-proveedor/spec.md`

## Summary

El botón "Verificar" del modal de Proveedor existe pero sólo valida el dígito verificador del CUIT:
nunca consulta el padrón de ARCA ni autocompleta datos fiscales, a diferencia de Cliente. Esta
feature lleva Proveedor a paridad funcional con Cliente y agrega una regla propia de derivación del
comprobante por defecto, adaptada a que en Proveedor el comprobante es el que **recibimos**, no el
que emitimos.

**Enfoque técnico** (de [research.md](./research.md)): en lugar de copiar por cuarta vez el bloque de
consulta al padrón, se extrae a un servicio `App\Services\Arca\ConsultaPadron` que centraliza tanto
la consulta (dos llamadas best-effort a ARCA) como su traducción a la respuesta JSON del modal. Los
tres consumidores actuales (`ClienteController`, `DerivadorComprobante` de Mercado Libre,
`ResolutorCliente` de Tiendanube) migran a él sin cambio de comportamiento, respaldados por sus
tests existentes. `ProveedorController` pasa a ser el cuarto consumidor. En el front se porta el
autocompletado a `proveedores.js` con la tabla A/C/B propia.

No hay cambios en el modelo de datos, ni migraciones, ni campos nuevos.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12) + JavaScript (jQuery, sin build de framework)

**Primary Dependencies**: Eloquent; SOAP para ARCA (`ws_sr_padron_a13`, `ws_sr_constancia_inscripcion`
vía WSAA); Bootstrap 5 (NexaDash); Toastr; Vite para assets

**Storage**: MySQL. **Esta feature no lee ni escribe tablas nuevas**: consume `certificados_fiscales`,
`condiciones_iva` y `provincias` ya existentes, y no persiste la respuesta del padrón.

**Testing**: PHPUnit (`tests/Feature/`). El JS del proyecto no tiene runner de navegador; la
verificación del front es manual vía `quickstart.md` (precedente: spec 048).

**Target Platform**: aplicación web servida por Laravel; navegador de escritorio

**Project Type**: aplicación web monolítica (Blade + controladores + servicios), sin separación
frontend/backend

**Performance Goals**: no aplica un objetivo numérico propio. La operación es un click manual que
dispara dos llamadas SOAP a un servicio externo cuya latencia no controlamos; el requisito real es
que una demora o caída **nunca bloquee el guardado** (FR-005), no que responda en un tiempo dado.

**Constraints**:
- Degradación obligatoria ante fallas de ARCA (Constitución III; FR-005/FR-006/FR-007)
- Sin recarga de página: modal Bootstrap + AJAX + toasts (CLAUDE.md, especificaciones de diseño 2 y 3)
- No regresión en Cliente (FR-018, SC-005)

**Scale/Scope**: 1 pantalla afectada (modal de Proveedor); 1 servicio nuevo; 3 consumidores migrados;
1 endpoint existente extendido; 0 migraciones.

## Constitution Check

*GATE: revisado antes de Phase 0 y re-evaluado tras Phase 1.*

| Principio | Estado | Justificación |
|-----------|--------|---------------|
| **I. Documentación de dominio como fuente de verdad** | ⚠️ Acción requerida | `docs/documentacion_principal_crm.md` §2.3 afirma hoy que Proveedores "reutiliza la misma validación de CUIT" que Cliente — cierto para el dígito verificador, falso para el padrón. Esa imprecisión es la que generó la expectativa incumplida del reporte. **Debe corregirse antes de `/speckit-tasks`**, distinguiendo ambas verificaciones y documentando la regla A/C/B. `docs/modelo_datos.md` no requiere cambios (no hay campos nuevos). |
| **II. Desarrollo spec-driven** | ✅ Pasa | Feature de negocio, con spec → clarify → plan antes de implementar. |
| **III. Corrección fiscal innegociable** | ✅ Pasa | Ver análisis abajo. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Los datos del padrón alimentan comprobantes de compra: hay test de Feature nuevo (6 escenarios) y los existentes quedan como red de regresión (research.md R5). |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Servicio inyectable en `App\Services\Arca\` (namespace ya existente), nombres en español (`ConsultaPadron`), sin pelear el framework. |

### Análisis del Principio III (el punto delicado)

El Principio III exige que "el tipo de comprobante (A/B/C/E) se deriva de la condición de IVA del
cliente y del emisor; no se permite (...) elegir el tipo 'a mano' salteando esa regla".

FR-015/FR-016 permiten al usuario sobreescribir el comprobante por defecto del Proveedor. **No hay
violación**, porque el principio regula la **emisión**: el comprobante que el negocio emite es una
decisión fiscal propia y no puede saltearse. El campo `tipo_comprobante_defecto` de Proveedor es
otra cosa: es una **anotación sobre lo que un tercero nos emite**, un dato informado que el usuario
transcribe de la factura que recibe. Si el proveedor nos manda una C, el CRM debe poder registrar una
C, aunque una tabla diga otra cosa.

La derivación acá es una **sugerencia de UI** que ahorra tipeo, no una regla de validación: no
bloquea el guardado ni se aplica en el backend. La emisión real de comprobantes (ventas, notas de
crédito/débito) sigue derivando su tipo por las reglas ya implementadas, que esta feature no toca.

**Conclusión**: sin violaciones que justificar. La única acción pendiente es la actualización
documental del Principio I, agendada antes de `tasks`.

### Re-evaluación post-Phase 1

Sin cambios. El diseño no introdujo entidades, capas ni dependencias nuevas: un servicio en un
namespace existente, que consolida código ya presente. La sección *Complexity Tracking* queda vacía
por no haber desvíos que justificar.

## Project Structure

### Documentation (this feature)

```text
specs/100-reconocedor-cuit-proveedor/
├── plan.md              # Este archivo
├── spec.md              # Qué y por qué
├── research.md          # Decisiones técnicas (R1-R6)
├── data-model.md        # Entidades tocadas (ninguna modificada)
├── quickstart.md        # Guía de validación manual y automatizada
├── contracts/
│   ├── verificar-documento-proveedor.md   # Contrato del endpoint
│   └── consulta-padron-servicio.md        # Contrato del servicio extraído
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec
└── tasks.md             # Generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/
│   └── Arca/
│       ├── ConsultaPadron.php              # NUEVO: servicio compartido (R1, R2)
│       ├── ClientePadron.php               # Sin cambios (consumido por el servicio)
│       ├── ClienteConstanciaInscripcion.php# Sin cambios
│       ├── ClienteWsaa.php                 # Sin cambios
│       └── ResultadoConsultaPadron.php     # Sin cambios (R6)
├── Http/Controllers/
│   ├── ProveedorController.php             # MODIFICADO: verificarDocumento() consulta el padrón
│   └── ClienteController.php               # MODIFICADO: delega en el servicio (sin cambio de conducta)
└── Services/
    ├── MercadoLibre/DerivadorComprobante.php   # MODIFICADO: delega en el servicio
    └── Tiendanube/ResolutorCliente.php         # MODIFICADO: delega en el servicio

resources/
├── js/
│   ├── proveedores.js                      # MODIFICADO: autocompletado + regla A/C/B (pantalla Proveedores)
│   ├── proveedor-modal.js                  # MODIFICADO: sólo la regla → A/C/B (alta rápida en Compra; ver research.md R7)
│   ├── clientes.js                         # Sin cambios (FR-018)
│   └── cliente-modal.js                    # Sin cambios — conserva su regla A/B, correcta para Cliente (FR-018)
└── views/proveedores/
    └── _modal_form.blade.php               # Sin cambios previstos (los campos ya existen; lo comparten ambas pantallas)

routes/web.php                              # Sin cambios (la ruta ya existe)

tests/Feature/
├── ProveedorVerificarPadronTest.php        # NUEVO: espejo del de Cliente
├── ClienteVerificarPadronTest.php          # Sin cambios — red de no-regresión (FR-018)
└── VerificacionDocumentoProveedorTest.php  # Sin cambios — sigue cubriendo el dígito verificador

docs/
└── documentacion_principal_crm.md          # A ACTUALIZAR antes de /speckit-tasks (Principio I)
```

**Structure Decision**: se mantiene la estructura monolítica vigente del proyecto (controladores en
`app/Http/Controllers`, lógica de integración en `app/Services/<Integración>/`, JS por pantalla en
`resources/js/`, vistas Blade por módulo). El único elemento nuevo es una clase de servicio dentro
del namespace `App\Services\Arca` ya existente, que agrupa a los demás clientes de ARCA. No se
introducen carpetas ni capas nuevas.

## Complexity Tracking

> Sin violaciones a la constitución que justificar. El plan reduce complejidad neta: consolida tres
> implementaciones duplicadas en una y evita crear una cuarta.
