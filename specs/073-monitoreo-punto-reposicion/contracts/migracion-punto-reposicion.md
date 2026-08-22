# Contrato: comando de migración del Punto de Reposición

**Feature**: 073-monitoreo-punto-reposicion

Migra el dato real del negocio desde la lista de precios "Punto Reposición" hacia
`productos.punto_reposicion` y elimina esa lista. Toca **datos reales**, así que el contrato es
conservador por diseño.

## Comando

```
php artisan migracion:punto-reposicion [--aplicar] [--eliminar-lista]
```

| Flag | Efecto |
|---|---|
| *(sin flags)* | **Dry-run**: informa qué haría, no escribe nada. Es el modo por defecto |
| `--aplicar` | Escribe `productos.punto_reposicion`. **No** borra la lista |
| `--eliminar-lista` | Sólo con `--aplicar`. Tras migrar, verifica referencias y borra la lista |

El dry-run por defecto no es ceremonia: es la lección de un incidente real de este proyecto, donde
un comando sobre datos de producción corrió sin verificar antes.

## Salida (los tres modos imprimen el mismo resumen — FR-008)

```
Lista de precios encontrada: id 14 "Punto Reposición"

  Productos con valor en la lista ....... 6.312
    → migrados con valor entero ......... 6.298
    → redondeados (tenían decimales) ....    11
    → no interpretables (negativo/nulo) .     3
  Productos sin valor (quedan en null) .. 2.104

  Productos no interpretables:
    #1204  Codo PVC 110mm            valor: -1.00
    #3390  Rejilla 15x15             valor:  0.00   → se deja en null (0 = sin control)
    #7712  Flexible 1/2"             valor: null

Verificación de referencias a la lista 14:
    clientes.lista_precio_id ................... 0
    ventas.lista_precio_id ..................... 0
    presupuestos.lista_precio_id ............... 0
    ml_configuracion.lista_precio_id ........... 0
    ml_configuracion.lista_precio_id_premium ... 0
    tiendanube_configuracion.lista_precio_id ... 0
    empresa.lista_precio_id .................... 0
  ✔ Nada la referencia: se puede eliminar.

MODO DRY-RUN — no se escribió nada. Volvé a correr con --aplicar.
```

## Reglas

1. **Identificación de la lista**: por nombre `Punto Reposición` (comparación insensible a mayúsculas
   y acentos). Si no encuentra exactamente una, aborta e informa — no adivina por id, porque el 14 es
   de esta base y no tiene por qué serlo en otra.
2. **Conversión** (data-model §3): entero → tal cual; decimal → redondeo al entero más cercano,
   contado aparte; negativo/nulo → el producto queda en `null` y se lista.
3. **Cero es `null`**: un `0` en la lista significa "sin control", igual que no tener valor. No se
   guarda `0` para no arrastrar un valor que la aplicación interpreta idéntico a `null`.
4. **Verificación previa al borrado (FR-007)**: si **cualquiera** de las consultas de referencia
   devuelve filas, el borrado no se hace y el comando termina con código distinto de cero, informando
   qué la referencia. **No existe un `--forzar`**: lo que se rompería del otro lado son precios de
   venta reales.
5. **Transacción**: la escritura de `punto_reposicion` y el borrado de la lista van en una sola
   transacción. Si algo falla, no queda un estado intermedio con la mitad migrada.
6. **Idempotencia**: correr `--aplicar` dos veces no cambia nada la segunda vez. Correrlo después de
   `--eliminar-lista` informa que no hay lista que migrar y termina bien, sin tocar los valores ya
   cargados a mano.
7. **No pisa valores cargados a mano**: si un producto ya tiene `punto_reposicion` distinto de
   `null`, el comando lo respeta y lo cuenta como "ya tenía valor". La migración es para poblar, no
   para sobrescribir.

## Tests que fijan el contrato

| Test | Qué fija |
|---|---|
| Dry-run no escribe | Correr sin flags deja `punto_reposicion` en `null` y la lista en pie |
| Migra valores enteros | Un producto con 6.00 en la lista queda con `punto_reposicion = 6` |
| Redondea decimales | 5.60 → 6, y aparece en el conteo de redondeados |
| Negativo y cero → `null` | Ninguno de los dos deja un valor cargado |
| Aborta con referencias | Con un cliente apuntando a la lista, `--eliminar-lista` no borra nada y sale con error |
| Elimina limpio | Sin referencias, borra `precios_producto` de esa lista y la fila de `listas_precio` |
| Idempotente | Segunda corrida no cambia conteos ni valores |
| No pisa lo cargado a mano | Un producto con valor previo conserva el suyo |
