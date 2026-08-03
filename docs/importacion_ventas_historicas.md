# Importación de Ventas históricas (Excel Contagram 2021-2025)

Registro de cómo se migró el historial de Ventas del Contagram viejo al CRM (03/08/2026), para
repetir el proceso si aparece un año nuevo para importar o hay que corregir algo.

## Origen de los datos

`public/imports/ventas/Ventas {año}.xlsx` — un archivo por año (2021 a 2025), una sola hoja
("Hoja 1"), 44 columnas, una fila por línea de producto de un comprobante (el campo `Id` se repite
para comprobantes con más de un ítem). **No están versionados en git** (binarios grandes) — hay que
subirlos a mano al VPS (`scp` a `public/imports/ventas/`) antes de correr el importador ahí.

## Comando

```bash
php artisan ventas:importar-historicas [--dry-run] [--anio=2021]
```

- `--dry-run`: no escribe nada, solo reporta estadísticas (clientes/productos que se crearían,
  categorías sin match, ejemplos de código sin match). **Correr siempre primero.**
- `--anio=YYYY`: procesa un solo año en vez de los 5 (útil para depurar o para un año nuevo).
- Idempotente: cada venta importada queda con `legacy_id = "{año}-{Id del Excel}"`. Si el `legacy_id`
  ya existe, esa venta se saltea. **El `Id` del Excel no es único entre archivos de años distintos**
  (se repite, ej. `Id=17` existe en 2021 y en 2022) — por eso `legacy_id` incluye el año.

## Gotchas de los archivos de origen (no repetir el diagnóstico)

- **`Ventas 2023.xlsx` no tiene fila de headers al principio.** Los datos arrancan directo en la
  fila 0, y la fila de headers real quedó pegada **al final** (última fila del archivo). Leerlo con
  header por defecto (fila 0) pierde una fila real y rompe el mapeo de columnas. El comando detecta
  esto solo (usa el header de otro año como canónico, y descarta la fila de header literal donde
  aparezca).
- **La columna "Tipo" está duplicada** en el header (una vez para el tipo de comprobante A/B/C/E,
  otra para el rubro del producto — ej. "Repuestos", "Griferías"). `array_combine()` con nombres de
  columna repetidos se queda solo con el último, perdiendo el A/B silenciosamente. El comando
  desduplica el header igual que hace pandas (`Tipo`, `Tipo.1`) antes de mapear filas.
- **Las fechas vienen como serial numérico de Excel** (`Excel::toArray()` sin formateo devuelve
  `44365.0`, no una fecha), no como string. Pasarlas directo a un campo `date` de Eloquent produce
  fechas absurdas (se vio `1970-01-01 12:19:29`: interpretó el serial como segundos desde epoch). Hay
  que convertir con `PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject()`.
- **`Cliente` es "Nombre + Teléfono" concatenado sin separador fijo** (ej. `"Patricia Orlando
  1163505845"`), igual que en la base ya migrada — el matching contra `clientes.nombre` es por
  **igualdad de string exacta**, no hace falta parsear nombre/teléfono por separado.
- **`Código` de producto trae espacios extra al final** (`"28795 REP-RV-610-00 VS610    "`) — hay que
  normalizar espacios (`trim` + colapsar múltiples espacios) antes de comparar contra
  `productos.codigo`. Para servicios de mano de obra sin `codigo` propio en la tabla, el primer token
  numérico del código del Excel suele ser el `id` del producto viejo (ej. `"12690 12690"` → producto
  id 12690) — el comando prueba ese fallback antes de dar por no encontrado.
- **`"Punto de Venta"-"N° Factura"` no es único entre años** (choca con la unique
  `(tipo_comprobante, nro_comprobante)` de `ventas`) — se decidió (03/08/2026) dejar
  `nro_comprobante = null` en las ventas históricas; el campo ya está documentado en el modelo como
  "dato sin emisión fiscal", y `legacy_id` permite rastrear el comprobante original si hace falta.

## Decisiones de negocio tomadas (03/08/2026)

- **Notas de crédito/débito históricas (`NC/NCA/NCB/ND/NDA/NDB`) quedan fuera de esta importación.**
  El Excel no registra a qué venta original corrigen, y `notas_credito_debito.venta_id` es `NOT
  NULL` en el esquema (se crean siempre desde la pantalla de una venta existente). Se cuentan y
  loguean (`nc_nd_salteadas`), no se pierden silenciosamente. Pendiente: decidir en el futuro si se
  importan igual permitiendo `venta_id` nulo, o quedan definitivamente fuera.
- **Comprobantes "FC" (sin sufijo A/B) con la columna `Tipo` vacía se asumen tipo `B`** (consumidor
  final) — pasa en ~45% de los "FC" del historial. Es una asunción, no un dato certero del Excel.
- **Filas sin ningún `Tipo de Comprobante`** (columna vacía, ~1.400 en total) se saltean sin crear
  nada — son casi todas del cliente sentinela `"NO USAR MAS"` con `ARCA = "---"`, no ventas reales.
- **Categorías del canal de venta** (`Local`, `Mercadolibre`, `Online`, `Web`, `Obras`, `Redes`) se
  crearon a mano en `categorias` (tipo `venta`) antes de correr el importador — no existían.
- **Vendedores**: el Excel trae 10 nombres (`Edgardo, Hernan, Ivana, Juan I, Lean, Leo, Mauricio,
  Pablo, Salvador, Tania, Tommy`) que se cargaron a mano en `vendedores` antes de importar (solo
  existían `Administrador` y `Lean`).

## Resultado de la corrida completa (03/08/2026, VPS producción)

18.598 ventas / 28.897 items / 59 clientes nuevos (de 18.598 — 99,7% ya matcheaba) / 2.980
productos nuevos (de 28.897 — 89,7% ya matcheaba por código) / 532 NC-ND salteadas / 1.437
comprobantes sin tipo salteados / total facturado histórico $658.250.798,60 / rango 2021-06-18 a
2025-12-31.

## Para un año nuevo (ej. 2026 a fin de año)

1. Exportar el Excel del año desde el sistema viejo (si sigue en paralelo) con las mismas 44
   columnas y nombres de header.
2. Revisar a mano si el archivo nuevo tiene los mismos problemas de formato de 2023 (header al
   final, etc.) — si los tiene, el comando ya los maneja; si aparece un problema nuevo, agregarlo acá.
3. Subir el archivo a `public/imports/ventas/` en el VPS (`scp`).
4. Correr `--dry-run --anio=2026` primero, revisar categorías/vendedores sin match antes de correr
   en serio (crearlos a mano si faltan, mismo paso que se hizo para 2021-2025).
5. Correr sin `--dry-run`. Es idempotente — si se corta a mitad de camino, se puede volver a correr
   sin duplicar lo ya importado.
