# Contrato: reordenamiento de cuentas de tesorería

**Feature**: 085-orden-cuentas-tesoreria

## Endpoint

```
PATCH /tesoreria/cuentas/orden
```

- **Nombre de ruta**: `tesoreria.cuentas.orden`
- **Middleware**: `auth` + `permiso:tesoreria.editar` (el grupo de tesorería está bajo
  `permiso:tesoreria.ver`; esta ruta lo eleva a edición — ver research.md Decisión 7)
- **Controlador**: `TesoreriaController::reordenarCuentas(ReordenarCuentasRequest $request)`
- **CSRF**: requerido (`X-CSRF-TOKEN`, ya configurado globalmente en `tesoreria.js`)

**Declaración**: debe registrarse **antes** de `Route::get('cuentas/{cuenta}', ...)` dentro del
grupo `tesoreria`. No hay colisión real de métodos (una es PATCH y la otra GET), pero declararla
antes evita que un futuro `Route::patch('cuentas/{cuenta}')` capture `orden` como id.

## Request

```json
{
  "tipo": "efectivo",
  "ids": [7, 3, 12, 5]
}
```

| Campo | Tipo | Reglas |
|-------|------|--------|
| `tipo` | string | requerido; uno de `efectivo`, `banco`, `a_cobrar`, `a_pagar` |
| `ids` | array de enteros | requerido; mínimo 1 elemento; sin repetidos (`distinct`); cada elemento debe existir en `cuentas_tesoreria` |

`ids` viene en el **orden final deseado**: la primera posición del array es la que se mostrará
primera dentro del bloque.

## Validación de conjunto (FR-008)

Además de las reglas de forma, el controlador compara el conjunto recibido contra el conjunto real:

```
conjunto_recibido  = ids del request
conjunto_esperado  = CuentaTesoreria::porTipo($tipo)->pluck('id')
```

Si los dos conjuntos **no son idénticos** (ignorando el orden), la operación se rechaza entera con
**409** y no se escribe nada. Esto cubre en un solo chequeo:

- un id que pertenece a otro tipo (intento de mover entre bloques),
- un id faltante (cuenta creada en paralelo desde otra sesión, o lista incompleta),
- un id sobrante (cuenta borrada en paralelo desde otra sesión).

## Efecto

Dentro de una transacción, para cada `ids[i]` se asigna `orden = i + 1` (1..N). Ningún otro campo
de la cuenta se modifica. Si algo falla, la transacción hace rollback y ninguna fila queda escrita
(FR-007).

## Responses

### 200 OK — reordenamiento aplicado

```json
{
  "ok": true,
  "mensaje": "Orden actualizado con éxito.",
  "saldos": { }
}
```

`saldos` es el mismo payload que devuelve `tesoreria.saldos.data` (a la fecha de hoy), incluido para
que el front pueda repintar las cards sin un segundo request. El front puede ignorarlo y llamar a
`TesoreriaSaldos.recargar()` si necesita respetar una fecha de corte distinta de hoy.

### 422 Unprocessable Entity — payload inválido de forma

Formato estándar de validación de Laravel. Casos: `tipo` ausente o fuera del enum, `ids` vacío o no
array, ids repetidos, id inexistente.

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "ids": ["El listado de cuentas no es válido."]
  }
}
```

### 409 Conflict — el bloque cambió

```json
{
  "ok": false,
  "mensaje": "El listado de cuentas cambió mientras reordenabas. Se actualizó la lista, volvé a intentarlo."
}
```

### 403 Forbidden — sin permiso `tesoreria.editar`

Respuesta estándar del middleware de permisos.

## Comportamiento esperado del cliente

| Respuesta | Acción del front |
|-----------|------------------|
| 200 | Toast de éxito; repintar las cards con `saldos` (o `TesoreriaSaldos.recargar()`) |
| 409 | Toast de error con el `mensaje`; **recargar el listado del modal** (`cargarConfigCuentas()`) para mostrar el estado real |
| 422 | Toast de error genérico; revertir el bloque al orden previo al arrastre |
| 403 / 5xx / red caída | Toast de error; revertir el bloque al orden previo al arrastre |
| request abortado (`statusText === 'abort'`) | **No hacer nada**: fue reemplazado por un arrastre posterior (FR-015) |

El front **no envía el request** cuando el arrastre no cambió la posición efectiva (el orden de ids
resultante es idéntico al previo) — FR-005 exige que no haya toast en ese caso.
