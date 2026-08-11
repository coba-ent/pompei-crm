# Contrato: Rutas de la página completa de NC/ND

Rutas nuevas (`GET`, sólo navegación) — las `POST`/`PUT`/`DELETE` ya existen desde spec 057, sin cambios.

## Ventas

```
GET ventas/{venta}/notas/nueva                          NotaCreditoDebitoController@create        name: ventas.notas.create
GET ventas/{venta}/notas/{notaCreditoDebito}/editar      NotaCreditoDebitoController@edit          name: ventas.notas.edit
```

## Compras

```
GET compras/{compra}/notas/nueva                        NotaCreditoDebitoController@createCompra   name: compras.notas.create
GET compras/{compra}/notas/{notaCreditoDebito}/editar    NotaCreditoDebitoController@editCompra     name: compras.notas.edit
```

Los 4 métodos renderizan la misma vista `notas-credito-debito.form`, pasando `$venta`/`$compra` (uno
de los dos, nunca ambos) y `$notaCreditoDebito` (`null` en `create`/`createCompra`).

## Query string de `create`/`createCompra` (opcional, precarga desde el modal de paso 1)

```
GET ventas/{venta}/notas/nueva?tipo=credito&documento_ajusta=&afecta_stock=1&deposito_id=5&mes_imputacion=2026-08
```

Si se accede sin query string (navegación directa, FR-010), el formulario muestra los mismos
controles de Tipo/Documento que Ajusta/Stock/Mes vacíos/con default, editables ahí mismo.

## Formulario — envío (sin cambios respecto a spec 057)

`POST ventas/{venta}/notas` (crear) / `PUT ventas/{venta}/notas/{nota}` (editar) — mismo payload y
respuestas ya documentados en `specs/057-editar-eliminar-ncnd/contracts/rutas-ncnd.md`. Al recibir
`200`/`201`, el JS redirige (`window.location.href`) al detalle de la Venta/Compra de origen (FR-007)
en vez de cerrar un modal.
