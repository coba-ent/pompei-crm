# Data Model: Reconocedor de CUIT por ARCA en Proveedores

**Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md) | **Fecha**: 2026-09-07

## Resumen

**Esta feature no modifica el modelo de datos**: cero migraciones, cero campos nuevos, cero tablas
nuevas, cero cambios de relaciones. Todos los campos que el autocompletado escribe ya existen en
`proveedores` desde el spec 003, y el catálogo que consume ya está poblado.

Se documentan acá las entidades **tocadas** (leídas o escritas) para trazabilidad y para que
`/speckit-tasks` no genere tareas de migración.

---

## Entidades escritas

### Proveedor (`proveedores`)

Campos que el autocompletado del padrón completa en el formulario. Se persisten sólo si el usuario
confirma el modal; la consulta al padrón por sí sola no escribe nada en la base.

| Campo | Origen del padrón | Notas |
|-------|-------------------|-------|
| `razon_social` | `razonSocial` | Texto libre |
| `domicilio_fiscal` | domicilio tipo FISCAL → `direccion` | Texto libre |
| `provincia_fiscal` | domicilio FISCAL → provincia normalizada | Debe existir en el catálogo de provincias; si no, no se asigna (FR-012) |
| `localidad_fiscal` | domicilio FISCAL → `localidad` | Depende de la provincia ya seleccionada (FR-011) |
| `condicion_iva_id` | derivada de la constancia de inscripción | FK a `condiciones_iva`; si no matchea, no se asigna (FR-012) |
| `tipo_comprobante_defecto` | **no** viene del padrón | Derivado en el front de la condición de IVA (FR-015) |

**Sin cambios de esquema.** El tipo, nulabilidad y validación de cada uno quedan como están.

**Reglas de negocio nuevas asociadas** (no estructurales):

- El comprobante por defecto se **sugiere** según la condición de IVA con lógica de compra:
  `Responsable Inscripto → A`, `Monotributista → C`, resto → `B`. Es editable y no se valida en el
  backend (ver plan.md, análisis del Principio III).
- Ningún campo se sobrescribe si el usuario lo editó tras abrir el modal (FR-009); los valores
  precargados al editar **sí** son sobrescribibles (FR-010, aclaración de la sesión 2026-09-07).

---

## Entidades leídas (sin modificación)

### CondicionIva (`condiciones_iva`)

Catálogo fijo de cinco filas: Consumidor Final, Exento, Monotributista, No Categorizado, Responsable
Inscripto. Se usa para dos cosas: resolver el `condicion_iva_id` que informa ARCA, y como entrada de
la regla de derivación del comprobante. **No se agregan ni renombran filas.**

### Provincia (`provincias`)

Catálogo de provincias argentinas. El nombre que devuelve ARCA se normaliza contra este catálogo
mediante el mapeo ya existente en `ResultadoConsultaPadron` (research.md R6).

### CertificadoFiscal (`certificados_fiscales`)

Se lee el certificado activo para autenticar contra ARCA. Su ausencia degrada la funcionalidad con
un mensaje, sin bloquear el alta (FR-005).

### Localidades

Se consultan por provincia a través del endpoint ya existente que alimenta el select linkeado del
modal. Sin cambios.

---

## Entidad transitoria (no persistida)

### ResultadoConsultaPadron

DTO ya existente (`App\Services\Arca\ResultadoConsultaPadron`). Representa la respuesta de ARCA para
un CUIT: identidad, domicilio fiscal desagregado, condición de IVA y estado de actividad.

- **No tiene tabla y no se persiste**: vive lo que dura el request de verificación.
- **No se modifica** en esta feature (research.md R6).
- Atributos consumidos: `encontrado`, `razonSocial`, `domicilioFiscal`, `localidadFiscal`,
  `provinciaFiscal`, `condicionIvaId`, `activo`.

---

## Diagrama de flujo de datos

```text
Usuario (modal Proveedor)
   │ click "Verificar" con CUIT
   ▼
ProveedorController::verificarDocumento()
   │ 1. valida dígito verificador (local, sin red)
   │ 2. si es válido ─────────────────────┐
   ▼                                       ▼
respuesta {aplica, valido}        ConsultaPadron::paraModal(cuit)
                                           │ lee CertificadoFiscal::activo()
                                           │ consulta ws_sr_padron_a13      ──► identidad + domicilio
                                           │ consulta constancia (best effort) ──► condición de IVA
                                           ▼
                                  ResultadoConsultaPadron (transitorio)
                                           │
                                           ▼
                              respuesta JSON {consultado, encontrado, mensaje, datos}
                                           │
                                           ▼
                              proveedores.js: autocompleta campos no tocados
                                           │ deriva comprobante (A/C/B)
                                           ▼
                              Usuario revisa → Guardar → INSERT/UPDATE en `proveedores`
```

La escritura en base ocurre **sólo** en el submit del formulario, por el flujo de guardado ya
existente. La verificación es de sólo lectura contra ARCA.

---

## Impacto en `docs/modelo_datos.md`

**Ninguno.** No hay cambios de esquema que reflejar. La actualización documental pendiente
(Principio I) es sobre `docs/documentacion_principal_crm.md` §2.3, que describe comportamiento de
pantalla, no estructura de datos.
