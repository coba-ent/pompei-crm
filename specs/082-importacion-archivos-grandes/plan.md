# Implementation Plan: Importación por Excel escalable a archivos grandes

**Branch**: `082-importacion-archivos-grandes` | **Date**: 2026-08-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/082-importacion-archivos-grandes/spec.md`

## Summary

El asistente de Importar Datos vuelve a interpretar el archivo Excel **completo en cada tanda** antes
de quedarse con su pedazo. Con el catálogo real (9.632 productos) eso da ~129 s y ~570 MB por tanda,
contra un límite de 60 s del servidor web y 512 MB de memoria — la importación se corta a la mitad
(incidente real del 25/08/2026: 1.000 de 1.117 filas).

**Enfoque técnico**: interpretar el archivo **una sola vez**, al subirlo, volcándolo a un NDJSON
(una fila JSON por línea) junto al temporal que ya existe; cada tanda lee sólo sus líneas. Se baja la
tanda de 1.000 a 250 filas, se agrega reintento automático + retoma en el frontend, y se hace la tanda
idempotente para que un reintento no duplique snapshots de deshacer.

Sin cambios en la base de datos, sin cambios en las reglas de negocio, sin cambios visibles para
archivos chicos.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: `maatwebsite/excel` (PhpSpreadsheet) para interpretar el `.xlsx`;
`SplFileObject` (núcleo de PHP) para leer el NDJSON por líneas. No se agregan dependencias nuevas.

**Storage**: Sin cambios de esquema. Estado transitorio en `storage/app/private/imports/` (disco) +
sesión, como ya define §2.4. Se agrega un archivo `.ndjson` por importación, que se borra junto con el
`.xlsx` temporal.

**Testing**: PHPUnit (Feature + Unit). La suite existente de importación (specs 026/027/074/078) es el
contrato de no-regresión.

**Target Platform**: Linux (VPS de producción, nginx + PHP-FPM 8.2) y XAMPP local.

**Project Type**: Aplicación web Laravel + Blade (monolito).

**Performance Goals**: Una tanda de 250 filas en ~26 s (≥ 2x de margen sobre el límite de 60 s del
servidor web). Catálogo completo (9.632 filas) en menos de 25 minutos.

**Constraints**: Memoria por tanda independiente del tamaño del archivo. Estado transitorio nunca en
base de datos. Cero regresiones sobre las specs 026/027/074/078.

**Scale/Scope**: Hasta 10.000 filas por archivo (límite de tamaño vigente de 10 MB: sobra, ~1,8 MB
proyectados). 3 entidades (Clientes, Proveedores, Productos & Servicios).

## Constitution Check

*GATE: revisado antes de Fase 0 y luego de Fase 1.*

| Principio | Estado | Notas |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ | Se leyó §2.4 de `documentacion_principal_crm.md` antes de especificar. La spec no introduce entidades ni campos nuevos; sí cambia un comportamiento observable (tamaño de tanda, reintento, retoma) que **debe reflejarse en §2.4 antes de `/speckit-tasks`**. |
| **II. Desarrollo spec-driven** | ✅ | Esta cadena. No se implementa nada hasta que el usuario lo pida. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ N/A | La importación no toca comprobantes, CAE ni numeración fiscal. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ | La importación toca **precios, costos y stock** — de lleno en el principio. Los tests son obligatorios y no opcionales: no-regresión de las specs 074/078 + tests nuevos de tandas, idempotencia y retoma. |
| **V. Convenciones Laravel + dominio en español** | ✅ | Nombres en español (`FuenteFilasImportacion`, `volcarFilas`), servicio bajo `app/Services/Import/`, sin pelear contra el framework. |

**Resultado**: sin violaciones. La sección *Complexity Tracking* queda vacía a propósito.

⚠️ **Gate pendiente**: actualizar §2.4 de `docs/documentacion_principal_crm.md` **antes** de
`/speckit-tasks` (principio I). `docs/modelo_datos.md` **no** requiere cambios: no hay tablas ni
columnas nuevas.

## Project Structure

### Documentation (this feature)

```text
specs/082-importacion-archivos-grandes/
├── spec.md              # ✅ creado
├── research.md          # ✅ creado (Fase 0)
├── plan.md              # ✅ este archivo
├── data-model.md        # ✅ creado (Fase 1) — sin cambios de esquema, documenta el formato NDJSON
├── quickstart.md        # ✅ creado (Fase 1) — verificación manual + pasos de despliegue
├── contracts/
│   └── fuente-filas-importacion.md   # ✅ contrato interno de la fuente de filas
└── tasks.md             # ⬅ lo genera /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/Import/
│   ├── ImportadorFilas.php              # MODIFICAR: dejar de interpretar el archivo por tanda;
│   │                                    #   leer desde la fuente de filas; saltear filas ya aplicadas
│   ├── FuenteFilasImportacion.php       # NUEVO: vuelca el Excel a NDJSON y lee rangos de filas
│   └── DefinicionCamposImportables.php  # sin cambios
└── Http/Controllers/
    └── ImportacionController.php        # MODIFICAR: volcar a NDJSON al subir; FILAS_POR_LOTE 1000→250;
                                         #   memory_limit explícito en el paso de tandas; borrar el
                                         #   .ndjson al terminar/cancelar

resources/views/importacion/
└── mapear.blade.php                     # MODIFICAR: reintento con backoff + botón "Reanudar desde la fila N"

tests/
├── Unit/
│   └── FuenteFilasImportacionTest.php   # NUEVO: volcado, lectura por rango, conteo, encabezados
└── Feature/
    ├── ImportacionPorTandasTest.php     # NUEVO: archivo multi-tanda completo; offset/límite; idempotencia
    └── (existentes: ImportacionProductosStockTest, DeshacerImportacionProductosTest,
        ImportadorFilasParseoTest, ImportadorFilasResolucionIdTest)  # deben pasar sin tocar expectativas
```

**Structure Decision**: monolito Laravel existente. La lógica nueva entra como un servicio en
`app/Services/Import/` (junto a `ImportadorFilas`, que ya vive ahí), sin crear capas ni proyectos
nuevos. El controlador y la vista Blade se modifican en su lugar.

## Fases de implementación

### Fase A — Fuente de filas (backend, base de todo)

1. `FuenteFilasImportacion`: `volcar(rutaXlsx): rutaNdjson` (interpreta una vez), `total()`,
   `encabezados()`, `leerRango(offset, limite): iterable`.
2. Tests unitarios de la fuente: volcado correcto, lectura de rangos, bordes (archivo vacío, 1 fila,
   rango que excede el final).

### Fase B — Enganchar el importador

3. `ImportadorFilas::importar()` deja de hacer `Excel::toArray()` cuando recibe una fuente ya volcada;
   mantiene el camino actual para `$limite = null` (tests/CLI) — ver Decisión 7 de research.
4. Test de no-regresión: la suite existente pasa sin cambios de expectativas.

### Fase C — Idempotencia de la tanda (sólo Productos)

5. Antes de procesar, saltear los `numero_fila` que ya tienen snapshot en esa corrida.
6. Test: reprocesar el mismo offset dos veces no duplica snapshots ni recuenta filas.

### Fase D — Controlador

7. `subir()`: volcar a NDJSON después de guardar el temporal; guardar total y encabezados en sesión.
8. `FILAS_POR_LOTE` 1.000 → 250; `ini_set('memory_limit')` explícito en `confirmarLote()`.
9. `cancelar()` y el cierre de `confirmarLote()`: borrar también el `.ndjson`.

### Fase E — Frontend

10. Reintento con backoff 2/4/8 s ante fallo de red o 5xx (no ante 422).
11. Botón "Reanudar desde la fila N" tras agotar los reintentos.
12. Chequeo de encabezados al retomar (edge case del mapeo desactualizado).

### Fase F — Verificación end-to-end

13. Prueba manual con archivo real de 9.000+ filas **en local** (nunca en producción, regla vigente).
14. Documentar el paso de despliegue de nginx en `quickstart.md` (sin ejecutarlo).

## Complexity Tracking

> Sin violaciones de la constitución que justificar. Sección vacía a propósito.
