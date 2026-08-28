# Implementation Plan: IVA Digital — archivos del régimen RG 3685 (spec 086)

**Fecha**: 2026-08-27 · **Spec**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

---

## Resumen técnico

Se agrega un **generador de archivos de ancho fijo** que consume las queries del Libro IVA ya
construidas por la spec 077 y produce los cuatro TXT del régimen RG 3685 más el ZIP que los agrupa.

No hay migraciones, no hay tablas nuevas y no se toca ninguna regla de cálculo existente: es una
**salida nueva sobre datos ya derivados**. Toda la complejidad está concentrada en un punto —
convertir un valor de dominio en una tira de caracteres en la posición exacta— y ahí es donde va el
esfuerzo de test.

**Constitución aplicable**: principio III (corrección fiscal innegociable) y principio IV (testing
donde hay impacto fiscal). Esta feature es el caso central de ambos: un archivo mal formado es un
rechazo de ARCA.

---

## Arquitectura

```
InformeContadorController::ivaDigital()        ← endpoint nuevo, delgado
        │
        ▼
IvaDigitalPaquete                              ← orquesta: 4 archivos → ZIP
        │
        ├── ComprobantesVentasWriter   (266)   ┐
        ├── AlicuotasVentasWriter      (62)    │  cada uno declara su layout
        ├── ComprobantesComprasWriter  (325)   │  como una lista de campos
        └── AlicuotasComprasWriter     (84)    ┘
                    │
                    ▼
            RegistroAnchoFijo                  ← padding, truncado, centavos, latin-1
                    │
                    ▼
        LibroIvaVentasQuery / LibroIvaComprasQuery   (spec 077, sin cambios)
```

**Por qué un writer por archivo y no uno genérico parametrizado**: los cuatro layouts se parecen lo
suficiente como para tentar a unificarlos, y difieren lo suficiente como para que unificarlos sea un
error. Alícuotas Ventas y Alícuotas Compras tienen los mismos campos conceptuales pero **el de compras
lleva 22 caracteres extra** de documento del vendedor en el medio. Un writer genérico con banderas
haría que ese detalle viva en un `if`, que es exactamente donde no se lo ve al revisarlo. Con un writer
por archivo, cada layout se lee como una tabla y se contrasta contra el fixture de un vistazo.

---

## Componentes

### 1. `RegistroAnchoFijo` (nuevo — `app/Support/ArchivosFiscales/`)

El único lugar del sistema que sabe convertir un valor en caracteres posicionados.

Responsabilidades:
- `numerico($valor, $ancho)` — entero alineado a derecha con ceros.
- `importe($valor, $ancho)` — **centavos**: multiplica por 100, redondea, padea. Sin signo ni separador.
- `alfanumerico($valor, $ancho)` — alineado a izquierda, espacios a derecha, **truncado** al ancho.
- `fecha($valor)` — `AAAAMMDD`.
- `alicuota($codigo)` — 4 dígitos.
- `linea(array $campos)` — concatena y **verifica el ancho total**, lanzando si no coincide.

Esa verificación de ancho en `linea()` es deliberada: convierte el modo de falla más peligroso (un
archivo silenciosamente corrido que ARCA rechaza recién en la presentación) en un error inmediato y
ruidoso en el momento de generar.

La conversión a latin-1 ocurre acá, **antes** del padding, por lo dicho en research §2.

### 2. Writers (nuevos — `app/Services/Informes/IvaDigital/`)

Cuatro clases, una por archivo. Cada una:
- declara su layout como una lista ordenada de `(campo, tipo, ancho)`, espejo de las tablas de research §1;
- recibe las filas ya derivadas por las queries de la 077;
- emite líneas a un handle de escritura (research §7).

Los writers de comprobantes reciben, además, el **conteo de alícuotas ya calculado** por el writer de
alícuotas correspondiente — así FR-016 se cumple por construcción y no por un cálculo paralelo.

### 3. `IvaDigitalPaquete` (nuevo — `app/Services/Informes/IvaDigital/`)

Orquesta el período: pide las filas, corre los cuatro writers, arma el ZIP con los nombres de FR-002 y
FR-003, y lo devuelve como descarga. Es el único componente que conoce los nombres de archivo y el mes
en castellano.

### 4. Extensión de `MapeadorComprobante`

Se agrega lo que falta para compras y para documento sin identificar (research §6). Se **extiende**,
no se duplica.

### 5. Endpoint y UI

- Ruta nueva `informes/contador/iva-digital` (GET, descarga).
- En la pantalla de la 077, la acción de descarga se habilita **sólo con mes elegido** (FR-004).

---

## Estrategia de test

Es el corazón de esta feature, no un apéndice.

**Fixture**: los archivos de `contador/` se copian a `tests/Fixtures/IvaDigital/` para que la suite no
dependa de una carpeta de trabajo del usuario.

1. **Test posicional contra el fixture (FR-021)** — el test principal. Para cada uno de los 4 archivos,
   se parsea el fixture **campo por campo según la tabla de research §1** y se compara contra la salida
   del generador campo por campo, reportando `(línea, campo, esperado, obtenido)`. Deliberadamente
   **no** es un `assertEquals` de archivos completos: eso dice "el archivo cambió" sin decir dónde, que
   es inútil cuando el archivo tiene 266 columnas.

2. **Excepción nombrada (FR-022)** — los dos comprobantes de MercadoLibre se testean **explícitamente
   al revés**: se afirma que el generador emite `1` donde el fixture dice `0`. Así la corrección queda
   fijada como comportamiento buscado y no puede revertirse por accidente.

3. **Invariantes de formato (FR-008 a FR-014)** — todas las líneas del ancho correcto; todas terminan
   en CRLF incluida la última; el archivo decodifica en latin-1; sin BOM ni encabezado.

4. **Consistencia cruzada (FR-016 a FR-018)** — cantidad declarada = filas reales; sin alícuotas
   huérfanas; crédito fiscal = suma del IVA de las alícuotas.

5. **Acentos y `Ñ` (FR-023)** — un proveedor con `Ñ` y acentos conserva el ancho **en bytes**. Este
   test es el que atrapa una regresión a UTF-8.

6. **Determinismo (SC-005)** — generar dos veces el mismo período produce bytes idénticos.

7. **Período vacío (FR-005)** — ZIP con 4 archivos de 0 bytes, sin excepción.

> **Nota sobre el entorno de test**: la suite corre en SQLite y producción en MySQL. Como estos tests
> ejercitan **formato de salida** sobre filas ya derivadas, el riesgo de divergencia por motor es bajo;
> pero el armado del período sigue pasando por las queries de la 077, así que la verificación final
> del período completo se hace además a mano contra el fixture en local con MySQL (memoria del
> proyecto: la suite verde no garantiza el comportamiento en MySQL).

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Un carácter corrido rompe el archivo entero en ARCA | `linea()` verifica el ancho total y falla al generar; test posicional campo por campo |
| Regresión silenciosa a UTF-8 al refactorizar | Test dedicado con `Ñ` y acentos que mide **bytes** |
| Divergencia entre el informe en pantalla y el TXT | Ambos consumen las mismas queries de la 077 (research §3) |
| Códigos ARCA duplicados que se desincronizan | Se extiende `MapeadorComprobante`, no se crea tabla nueva |
| Confundir el layout de Alícuotas Ventas con el de Compras (22 chars) | Un writer por archivo, layout declarado como tabla; test posicional propio para cada uno |

---

## Fuera de alcance

Envío por correo y su modal (spec 087); XLSX de IVA Ventas/Compras (ya existen, spec 077);
presentación automática ante ARCA.
