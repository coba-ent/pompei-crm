# Contrato interno: `ValidadorFilasImportacion`

**Spec**: [../spec.md](../spec.md) | **Research**: [../research.md](../research.md) Decisión 2

No es una API HTTP. Es la costura que garantiza FR-003: que la prevalidación y la importación real
apliquen **las mismas reglas**.

## Responsabilidad

Dada una fila cruda y un mapeo, decir **qué haría** con ella: alta, actualización o error con motivo.
**No escribe nada.**

## La invariante que sostiene todo

> **El validador no tiene forma de escribir.** No recibe el `StockService`, no llama a `create()`,
> `update()` ni `save()`. FR-002 se cumple **por construcción**, no por disciplina de quien lo use.

Si algún día alguien necesita que el validador escriba, eso es señal de que el diseño se rompió, no de
que haya que pasarle una dependencia más.

## Superficie

```php
namespace App\Services\Import;

final class ValidadorFilasImportacion
{
    /**
     * Qué haría el importador con esta fila, sin hacerlo.
     *
     * @param  array<int, mixed>  $celdas   fila cruda, celdas por índice
     * @param  array<int|string, string>  $mapeo  índice de columna => campo destino
     * @return array{
     *     modo: 'alta'|'actualizacion'|'error',
     *     motivos: array<int, string>,          // en español, nombrando columnas por su etiqueta visible
     *     advertencias: array<int, string>,     // no bloquean (ej. proveedor no encontrado)
     *     registro_id: int|null,                // el existente, si es actualización
     *     campos: array<int, string>            // campos que esta fila escribiría, por su etiqueta visible (FR-005b)
     * }
     */
    public function evaluar(array $celdas, string $entidad, array $mapeo, array $personalizados, array $columnasOriginales): array;
}
```

## Invariantes

| # | Invariante | Por qué importa |
|---|---|---|
| I1 | No escribe en la base bajo ninguna entrada. | FR-002. Es la razón de existir del servicio. |
| I2 | `modo` coincide **siempre** con lo que después hace `ImportadorFilas` sobre la misma fila y el mismo estado de base. | FR-003. Es lo que hace que la prevalidación sea creíble. |
| I3 | Todo `motivo` está en español y nombra la columna por su etiqueta visible. | FR-018, FR-019. |
| I4 | Devuelve **todos** los motivos de la fila, no sólo el primero. | FR-020. Hoy se corta en el primero (`errors()->first()`). |
| I5 | Una advertencia nunca bloquea; un motivo siempre sí. | Distinción ya vigente: "Proveedor no encontrado" no invalida el producto. |
| I6 | Una celda con una fórmula no evaluable produce **error**, nunca un valor. | FR-012, FR-013. |
| I7 | En una actualización, `campos` lista **exactamente** los campos que se van a escribir — los mapeados con valor no vacío en esa fila. | FR-005b: es lo que el modal suma para decir "Costo: 100 registros". Si lista de más, el modal miente. |

## Casos borde

| Caso | Comportamiento |
|---|---|
| Fila sin Id mapeado | `alta`, sin id forzado. |
| Id numérico con match | `actualizacion` + `registro_id`. |
| Id numérico sin match | `alta` (preservando ese id — regla de la spec 027). |
| Id no numérico | `error`: *"La columna Id tiene el valor «abc», que no es un id válido."* |
| Celda no numérica en columna numérica | `error` nombrando la columna: *"AHORA 3 tiene que ser un número."* |
| Fórmula sin evaluar | `error` nombrando la columna, **nunca** el texto de la fórmula como valor. |
| Proveedor/Categoría inexistente | `advertencia`, no error (comportamiento vigente). |
| Fila con menos celdas que el encabezado | Se tolera; los índices ausentes se leen vacíos. |
| Fila entera vacía | `error` si falta el campo obligatorio, como hoy. |

## Relación con `ImportadorFilas`

`ImportadorFilas` pasa a ser **el validador + persistir**: llama a `evaluar()` y, según el modo,
escribe. Deja de tener su propia copia de las reglas de mapeo y validación.

**Test que fija I2**: correr el validador y el importador sobre el mismo archivo y comparar fila por
fila que el modo previsto coincide con el aplicado. Es el test que impide que los dos caminos se
desincronicen con el tiempo — el riesgo real de esta arquitectura.
