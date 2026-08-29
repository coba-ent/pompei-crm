# Contrato: mapa ruta → permiso(s) exigidos

**Spec**: 090-permisos-granulares-informes · **Fecha**: 2026-08-28

Inventario tomado de `php artisan route:list --path=informes` sobre el código vigente: **65 rutas**.
Este archivo es la fuente de verdad para la reestructuración de `routes/web.php` y para los tests de
`InformesPermisosTest`. Toda ruta listada debe quedar cubierta — FR-014.

## Convención

- **Permiso de informe**: middleware `permiso:informes.<informe>` sobre el sub-grupo.
- **Descarga**: las rutas marcadas `+ exportar` encadenan un segundo middleware
  `permiso:informes.exportar` a nivel de ruta. Se exigen **ambos** (FR-010): el primero de la cadena
  corta si falta el del informe, lo que satisface FR-003 sin lógica adicional.
- Columna **Hoy**: estado actual. `SIN CONTROL` marca las rutas del bug (FR-011).

---

## 1. Ventas — `informes.ventas`

| Método | URI | Permisos exigidos | Hoy |
|---|---|---|---|
| GET | `informes/ventas` | `informes.ventas` | `informes.ver` |
| GET | `informes/ventas/data` | `informes.ventas` | `informes.ver` |
| GET | `informes/ventas/stats` | `informes.ventas` | `informes.ver` |
| GET | `informes/ventas/exportar` | `informes.ventas` + `informes.exportar` | `informes.ver` |
| GET | `informes/ventas/exportar-detallado` | `informes.ventas` + `informes.exportar` | `informes.ver` |
| GET | `informes/ventas/pdf` | `informes.ventas` + `informes.exportar` | `informes.ver` |
| GET | `informes/ventas/pivot/dataset` | `informes.ventas` | `informes.ver` |
| POST | `informes/ventas/pivot/exportar` | `informes.ventas` + `informes.exportar` | `informes.ver` |
| GET | `informes/ventas/pivot/vistas` | `informes.ventas` | `informes.ver` |
| POST | `informes/ventas/pivot/vistas` | `informes.ventas` | `informes.ver` |
| PUT | `informes/ventas/pivot/vistas/{vista}` | `informes.ventas` | `informes.ver` |
| DELETE | `informes/ventas/pivot/vistas/{vista}` | `informes.ventas` | `informes.ver` |
| GET | `informes/ventas/ranking/{dimension}` | `informes.ventas` | `informes.ver` |
| GET | `informes/ventas/vista/{vista}` | `informes.ventas` | `informes.ver` |

> Las cuatro rutas de `pivot/vistas` son de escritura y **no** llevan permiso propio (FR-020): quien
> ve el informe guarda y borra sus cruces. Regla heredada de la spec 069 FR-042.

## 2. Compras — `informes.compras`

| Método | URI | Permisos exigidos | Hoy |
|---|---|---|---|
| GET | `informes/compras` | `informes.compras` | `informes.ver` |
| GET | `informes/compras/data` | `informes.compras` | `informes.ver` |
| GET | `informes/compras/stats` | `informes.compras` | `informes.ver` |
| GET | `informes/compras/exportar` | `informes.compras` + `informes.exportar` | `informes.ver` |
| GET | `informes/compras/pdf` | `informes.compras` + `informes.exportar` | `informes.ver` |
| GET | `informes/compras/pivot/dataset` | `informes.compras` | `informes.ver` |
| POST | `informes/compras/pivot/exportar` | `informes.compras` + `informes.exportar` | `informes.ver` |
| GET | `informes/compras/pivot/vistas` | `informes.compras` | `informes.ver` |
| POST | `informes/compras/pivot/vistas` | `informes.compras` | `informes.ver` |
| PUT | `informes/compras/pivot/vistas/{vista}` | `informes.compras` | `informes.ver` |
| DELETE | `informes/compras/pivot/vistas/{vista}` | `informes.compras` | `informes.ver` |
| GET | `informes/compras/ranking/{dimension}` | `informes.compras` | `informes.ver` |
| GET | `informes/compras/vista/{vista}` | `informes.compras` | `informes.ver` |

## 3. Gastos — `informes.gastos`

| Método | URI | Permisos exigidos | Hoy |
|---|---|---|---|
| GET | `informes/gastos` | `informes.gastos` | `informes.ver` |
| GET | `informes/gastos/data` | `informes.gastos` | `informes.ver` |
| GET | `informes/gastos/stats` | `informes.gastos` | `informes.ver` |
| GET | `informes/gastos/grupo` | `informes.gastos` | `informes.ver` |
| GET | `informes/gastos/exportar` | `informes.gastos` + `informes.exportar` | `informes.ver` |
| GET | `informes/gastos/pdf` | `informes.gastos` + `informes.exportar` | `informes.ver` |

## 4. Stock — `informes.stock` ⚠️ bug

| Método | URI | Permisos exigidos | Hoy |
|---|---|---|---|
| GET | `informes/stock` | `informes.stock` | **SIN CONTROL** |
| GET | `informes/stock/data` | `informes.stock` | **SIN CONTROL** |
| GET | `informes/stock/stats` | `informes.stock` | **SIN CONTROL** |

> El Informe de Stock no tiene exportación ni PDF hoy; si se agregan, deben encadenar
> `informes.exportar`.

## 5. Cuenta Corriente Clientes — `informes.cuenta-corriente-clientes` ⚠️ bug

| Método | URI | Permisos exigidos | Hoy |
|---|---|---|---|
| GET | `informes/cuenta-corriente` | `informes.cuenta-corriente-clientes` | **SIN CONTROL** |
| GET | `informes/cuenta-corriente/saldos` | `informes.cuenta-corriente-clientes` | **SIN CONTROL** |
| GET | `informes/cuenta-corriente/movimientos` | `informes.cuenta-corriente-clientes` | **SIN CONTROL** |
| GET | `informes/cuenta-corriente/exportar` | `+ informes.exportar` | **SIN CONTROL** |
| GET | `informes/cuenta-corriente/pdf` | `+ informes.exportar` | **SIN CONTROL** |
| GET | `informes/cuenta-corriente/movimientos/exportar` | `+ informes.exportar` | **SIN CONTROL** |
| GET | `informes/cuenta-corriente/movimientos/pdf` | `+ informes.exportar` | **SIN CONTROL** |

> Las 7 rutas de este bloque son la fuga más grave: exponen y descargan la cuenta corriente de todos
> los clientes a cualquier usuario autenticado.

## 6. Cuenta Corriente Proveedores — `informes.cuenta-corriente-proveedores`

| Método | URI | Permisos exigidos | Hoy |
|---|---|---|---|
| GET | `informes/cuenta-corriente-proveedores` | `informes.cuenta-corriente-proveedores` | `informes.ver` |
| GET | `informes/cuenta-corriente-proveedores/saldos` | `informes.cuenta-corriente-proveedores` | `informes.ver` |
| GET | `informes/cuenta-corriente-proveedores/movimientos` | `informes.cuenta-corriente-proveedores` | `informes.ver` |
| GET | `informes/cuenta-corriente-proveedores/proveedor/{proveedor}` | `informes.cuenta-corriente-proveedores` | `informes.ver` |
| GET | `informes/cuenta-corriente-proveedores/exportar` | `+ informes.exportar` | `informes.ver` |
| GET | `informes/cuenta-corriente-proveedores/pdf` | `+ informes.exportar` | `informes.ver` |
| GET | `informes/cuenta-corriente-proveedores/movimientos/exportar` | `+ informes.exportar` | `informes.ver` |
| GET | `informes/cuenta-corriente-proveedores/movimientos/pdf` | `+ informes.exportar` | `informes.ver` |

## 7. Reporte Final — `informes.reporte-final`

| Método | URI | Permisos exigidos | Hoy |
|---|---|---|---|
| GET | `informes/reporte-final` | `informes.reporte-final` | `informes.ver` |
| GET | `informes/reporte-final/data` | `informes.reporte-final` | `informes.ver` |
| GET | `informes/reporte-final/exportar` | `+ informes.exportar` | `informes.ver` |
| GET | `informes/reporte-final/pdf` | `+ informes.exportar` | `informes.ver` |

## 8. Información para tu Contador — `informes.contador`

| Método | URI | Permisos exigidos | Hoy |
|---|---|---|---|
| GET | `informes/contador` | `informes.contador` | `informes.ver` |
| POST | `informes/contador/ventas/data` | `informes.contador` | `informes.ver` |
| POST | `informes/contador/ventas/stats` | `informes.contador` | `informes.ver` |
| POST | `informes/contador/compras/data` | `informes.contador` | `informes.ver` |
| POST | `informes/contador/compras/stats` | `informes.contador` | `informes.ver` |
| GET | `informes/contador/ventas/exportar` | `+ informes.exportar` | `informes.ver` |
| GET | `informes/contador/compras/exportar` | `+ informes.exportar` | `informes.ver` |
| GET | `informes/contador/iva-digital` | `+ informes.exportar` | `informes.ver` |
| POST | `informes/contador/adjuntos-previstos` | `informes.contador` | `informes.ver` |
| POST | `informes/contador/enviar` | `informes.contador` | `informes.ver` |

> `data`/`stats` van por POST por el incidente 414 de Nginx (spec 077 research §D9) — se mantiene.
> `iva-digital` es una descarga y por eso encadena `informes.exportar` (FR-013). El envío por correo
> (`enviar`, `adjuntos-previstos`) queda bajo `informes.contador` sin exigir `informes.exportar`: no
> es una descarga para el usuario (FR-012).

---

## Contrato de comportamiento

| Situación | Respuesta esperada |
|---|---|
| Sin autenticar | Redirección a login (comportamiento existente del grupo `auth`) |
| Autenticado sin el permiso del informe | **403** |
| Con el permiso del informe, sin `informes.exportar`, pidiendo una descarga | **403** |
| Con `informes.exportar` pero sin el permiso del informe | **403** (corta el primer middleware) |
| Con ambos permisos | **200** y el archivo/dato, idéntico a hoy |
| Rol Admin | **200** en las 65 rutas, sin permisos asignados (`Gate::before` / `esAdmin()`) |

## Cobertura del reparto por rol

Rutas accesibles según el reparto de la User Story 4:

| Rol | Bloques accesibles | Rutas | Descargas |
|---|---|---|---|
| Admin | 1–8 | 65 | Sí |
| Contable | 2, 3, 6, 8 | 37 | Sí |
| Vendedor | ninguno | 0 | No |
