# Data Model: Verificación de documento fiscal (CUIT/CUIL)

## Sin entidades ni columnas nuevas

Esta feature **no agrega tablas, columnas ni migraciones**. Reusa campos que ya existen:

| Entidad | Campo | Uso en esta feature |
|---|---|---|
| `Cliente` | `tipo_documento` (`CUIT`/`CUIL`/`DNI`/`Pasaporte`/`CDI`) | Determina si el botón "Verificar" y el auto-formato aplican (sólo CUIT/CUIL). Sin cambios de esquema. |
| `Cliente` | `cuit` (string, sólo dígitos tras `normalizarCuit()`) | Valor que se verifica. Sin cambios de esquema. |
| `Proveedor` | `tipo_documento`, `cuit` | Idéntico a Cliente. |
| `MercadoLibreOrden` | `comprador_doc_tipo`, `comprador_doc_numero` | Datos crudos informados por Mercado Libre (`billing_info.identification.type`/`.number`), usados hoy sin validar en la rama de aproximación (FR-040c de spec 012). Esta feature valida estos valores antes de usarlos; no cambia su tipo de dato ni cómo se persisten en `ml_ordenes`. |

## Regla de validación (ya existente, reusada)

`App\Rules\CuitValido::esValido(string $numero): bool` — 11 dígitos, prefijo válido
(`20,23,24,27,30,33,34`), dígito verificador módulo 11. Sin cambios en esta feature (ver
[research.md](./research.md) R1).

## Nuevo estado derivado (no persistido)

Ninguno de los tres puntos de esta feature introduce un campo persistido nuevo:

- El botón "Verificar" es un chequeo *ad-hoc* que no guarda nada — sólo muestra feedback en el modal.
- La validación en `DerivadorComprobante`/`ResolutorCliente` decide, en memoria, si usa o descarta
  `doc_tipo`/`doc_numero` antes de continuar con la lógica ya existente (FR-040a/FR-040c de spec 012)
  — no hay un nuevo campo tipo `documento_invalido` en `ml_ordenes`.
