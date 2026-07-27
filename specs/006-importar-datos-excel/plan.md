# Implementation Plan: Importar Datos por Excel

**Branch**: `006-importar-datos-excel` | **Date**: 2026-07-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/006-importar-datos-excel/spec.md`

## Summary

Pantalla dedicada "Importar Datos" (3 solapas: Clientes, Proveedores, Productos & Servicios) con un
asistente de 3 pasos por páginas reales (subir archivo → vista previa + mapeo de columnas →
confirmar/resumen), fiel a `docs/informe_contagram_base_de_datos.md` §2.6 y la captura
`capturas/nuevas/28`. Reutiliza las reglas de validación ya existentes de Cliente/Proveedor/Producto
por fila, y el mecanismo de campos personalizados ya construido.

Enfoque técnico: `maatwebsite/excel` (ya está en `composer.json` y **ya instalado en `vendor/`**, sin
paquete nuevo que agregar) para leer el archivo como un array crudo de filas/columnas — sin clases
de importación con encabezados fijos, porque el mapeo de columnas lo define el usuario en pantalla,
no el archivo. El archivo subido se guarda temporalmente en `storage/app/private/imports/` y la
sesión de Laravel guarda la referencia (ruta + entidad + mapeo elegido) entre los pasos del
asistente — se descarta al confirmar o cancelar, nunca se persiste en base de datos.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel Framework 12, Eloquent ORM, Blade; **`maatwebsite/excel` ^3.1**
(ya en `composer.json`, confirmado disponible en `vendor/` — verificado que la clase
`Maatwebsite\Excel\Facades\Excel` resuelve dentro de la app). Sin librerías nuevas.

**Storage**: MySQL (XAMPP local, DB `contagram`) para los registros creados. **Sin tablas nuevas**
— el archivo subido y el mapeo elegido viven en `storage/app/private/imports/` (temporal, se borra
al confirmar/cancelar/expirar) + sesión de Laravel, nunca en base de datos.

**UX/UI**: Única pantalla de la app que navega por **páginas reales** entre pasos (Assumptions del
spec) — excepción documentada a la regla general de "todo por modal". Toda notificación de
resultado (resumen de importación, errores de fila) sigue usando el patrón visual del proyecto
(alertas/toasts de Toastr donde aplique dentro de cada página).

**Testing**: PHPUnit 11 sobre SQLite en memoria. Feature tests para: subir y previsualizar un
archivo, mapear columnas y confirmar creando registros válidos, rechazo de filas inválidas sin
abortar el resto (Principio IV — hay validación de CUIT y campos económicos de Producto de por
medio), resolución de "Proveedor" por nombre en Productos, y cancelación sin dejar registros.

**Target Platform**: Aplicación web (navegador de escritorio, `php artisan serve` en dev).

**Project Type**: Web application monolítica Laravel (backend + Blade en el mismo proyecto).

**Performance Goals**: Procesamiento síncrono aceptable para el volumen esperado del negocio (hasta
unos pocos miles de filas, límite real de archivo 10MB) — sin cola/background job en esta versión
(Assumptions del spec).

**Constraints**: Single-tenant (sin `empresa_id`). Cada fila se valida y se crea de forma
independiente (falla parcial por fila, nunca aborta todo el archivo) — mismo criterio de atomicidad
ya usado en `004-productos-acciones-masivas` research.md §3, aplicado al revés (ahí "todo o nada" era
para el valor único de un lote; acá cada fila es su propio "lote" de una sola operación).

**Scale/Scope**: 1 controlador nuevo (`ImportacionController`, con métodos por paso del asistente),
3 clases de "definición de campos importables" (una por entidad, listando target fields + reglas de
validación reutilizadas), 3 vistas (subir/mapear/resumen, parametrizadas por entidad), 1 entrada de
sidebar/botón "Importar datos" en Clientes/Proveedores/Productos. Sin modelos ni migraciones nuevas.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: ✅ Este plan se basa en
  `docs/documentacion_principal_crm.md` (menciona la falta de "Importar datos" en las 3 pantallas) y
  se actualiza al cierre para reflejar la feature activa.
- **II. Desarrollo spec-driven**: ✅ Se sigue el flujo specify → plan → tasks → analyze → implement.
- **III. Corrección fiscal innegociable (ARCA)**: N/A — no emite comprobantes; reutiliza `CuitValido`
  ya existente sin modificarlo.
- **IV. Testing donde hay dinero o impacto fiscal**: ✅ Se planifican tests para la validación por
  fila (CUIT, campos económicos de Producto) y la atomicidad "por fila, no por archivo".
- **V. Convenciones Laravel + dominio en español**: ✅ Nombres en español (`ImportacionController`,
  rutas `importar-datos`), sin `empresa_id`, reutiliza `ReglasCliente`/`ReglasProveedor`/
  `ReglasProducto` existentes sin duplicar validación.

**Resultado del gate**: PASS. Sin violaciones que justificar (Complexity Tracking vacío).

## Project Structure

### Documentation (this feature)

```text
specs/006-importar-datos-excel/
├── plan.md              # Este archivo
├── spec.md              # Especificación (ya creada)
├── research.md          # Fase 0 (este comando)
├── data-model.md        # Fase 1 (este comando)
├── quickstart.md         # Fase 1 (este comando)
├── contracts/            # Fase 1 (este comando) — contrato de rutas
│   └── importacion-rutas.md
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec (ya creado)
└── tasks.md              # Fase 2 (/speckit-tasks — NO lo crea este comando)
```

### Source Code (repository root)

Monolito Laravel existente. Archivos nuevos/modificados de esta feature:

```text
app/
├── Http/Controllers/
│   └── ImportacionController.php       # NUEVO: index (solapas) / subir / mapear / confirmar / cancelar
├── Http/Requests/
│   └── SubirArchivoImportacionRequest.php  # NUEVO: valida mimes:xls,xlsx,csv + max:10240 (10MB)
└── Services/Import/
    ├── DefinicionCamposImportables.php     # NUEVO: campos destino + reglas por entidad (Cliente/Proveedor/Producto)
    └── ImportadorFilas.php                  # NUEVO: aplica el mapeo elegido, valida y crea fila por fila

resources/views/
└── importacion/
    ├── index.blade.php       # NUEVO: solapas + "Seleccionar Archivo" + paneles "Acerca de"/"Notas Técnicas"
    ├── mapear.blade.php       # NUEVO: vista previa + selects de mapeo por columna
    └── resumen.blade.php      # NUEVO: resultado (importados / fallidos con motivo)

resources/views/clientes/index.blade.php     # MODIFICADO: agrega el botón "Importar datos" (no existe hoy)
resources/views/proveedores/index.blade.php  # MODIFICADO: ídem
resources/views/productos/index.blade.php    # MODIFICADO: ídem

routes/web.php                  # MODIFICADO: agrega
                                 # GET importar-datos/{entidad}
                                 # POST importar-datos/{entidad}/subir
                                 # GET importar-datos/{entidad}/mapear
                                 # POST importar-datos/{entidad}/confirmar
                                 # POST importar-datos/{entidad}/cancelar

tests/
└── Feature/
    └── ImportacionDatosTest.php   # NUEVO: subir+preview, mapeo+confirmación, filas inválidas,
                                   # resolución de Proveedor por nombre, cancelación
```

**Structure Decision**: Un controlador + dos clases de servicio parametrizadas por entidad
(`DefinicionCamposImportables` describe QUÉ campos existen y cómo validarlos por entidad;
`ImportadorFilas` aplica el mapeo elegido fila por fila, reutilizando esas definiciones) en vez de
tres controladores/servicios duplicados — reflejar en código que las 3 solapas son la misma
mecánica con distinto "diccionario de campos", tal como está especificado (US1 construye el
mecanismo, US2/US3 lo reutilizan).

## Complexity Tracking

> No aplica — el Constitution Check pasó sin violaciones. Sin desviaciones que justificar.
