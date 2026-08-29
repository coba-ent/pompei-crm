# Quickstart: validación de permisos granulares por informe (spec 090)

**Spec**: [spec.md](./spec.md) · **Contrato de rutas**: [contracts/rutas-permisos.md](./contracts/rutas-permisos.md)

Guía para verificar que la feature funciona de punta a punta. **Todo se valida en local** — hay
memoria explícita del proyecto de no probar en producción, y el VPS está en uso real.

## Prerrequisitos

- XAMPP corriendo, base `contagram` local.
- Migraciones aplicadas: `php artisan migrate`.
- `CREDENCIALES_ACCESO.txt` a mano. **Si en la validación manual se resetea alguna contraseña, hay
  que anotarla ahí en el mismo cambio** (regla del CLAUDE.md).

## 1. Verificación automatizada

```bash
php artisan test --filter=InformesPermisos
```

Esperado: verde, cubriendo

- **403 sin el permiso del informe** en las 65 rutas del contrato, con foco en las 10 que hoy están
  sin control (3 de Stock + 7 de Cuenta Corriente Clientes).
- **403 en descarga** con el permiso del informe pero sin `informes.exportar`.
- **403 en descarga** con `informes.exportar` pero sin el permiso del informe.
- **200** con ambos permisos.
- **Aislamiento**: un usuario con un solo permiso de informe no entra a los otros siete.
- **Reparto por rol** tras la migración, y que `informes.ver` ya no existe en el catálogo.

Suite completa de informes, para confirmar que no se rompió nada existente:

```bash
php artisan test --filter=Informe
```

## 2. Verificación del reparto en la base

```bash
php artisan tinker --execute="foreach(\App\Models\Rol::with('permisos')->get() as \$r){ echo \$r->nombre.': '.\$r->permisos->where('modulo','informes')->pluck('codigo')->implode(', ').PHP_EOL; }"
```

Esperado:

| Rol | Salida esperada |
|---|---|
| Admin | los 9 códigos |
| Contable | `informes.compras, informes.contador, informes.cuenta-corriente-proveedores, informes.exportar, informes.gastos` |
| Vendedor | *(vacío)* |

Y que el permiso viejo desapareció:

```bash
php artisan tinker --execute="echo \App\Models\Permiso::where('codigo','informes.ver')->exists() ? 'TODAVIA EXISTE - MAL' : 'OK, retirado';"
```

## 3. Verificación de cobertura de rutas (el bug)

Ninguna ruta del módulo puede quedar sin middleware de permiso (FR-014):

```bash
php artisan route:list --path=informes --json | php -r '$r=json_decode(file_get_contents("php://stdin"),true); $malas=[]; foreach($r as $x){ $m=implode(",",(array)($x["middleware"]??[])); if(strpos($m,"permiso:informes.")===false) $malas[]=$x["uri"]; } echo $malas ? "SIN PERMISO:".PHP_EOL.implode(PHP_EOL,array_unique($malas)).PHP_EOL : "OK: las 65 rutas tienen permiso".PHP_EOL;'
```

Esperado: `OK: las 65 rutas tienen permiso`.

## 4. Verificación manual en el navegador

Regla del proyecto: la suite verde no alcanza (MySQL estricto vs. SQLite en tests), hay que mirar la
pantalla.

### 4.1 Como Admin

1. Entrar con un usuario Admin.
2. Sidebar → **Informes**: deben verse los 8 ítems.
3. Abrir cada informe y confirmar que carga la tabla y las estadísticas igual que antes.
4. Exportar a Excel y generar el PDF de al menos Ventas y Cta Cte Clientes: deben descargar bien.

### 4.2 Como Vendedor

1. Entrar con un usuario del rol Vendedor.
2. Sidebar: **el bloque "Informes" no debe aparecer** (FR-016).
3. Menú de fila de un Cliente: **no** debe ofrecer "Cuenta Corriente" (FR-017).
4. Pegar a mano en la barra de direcciones, uno por uno — **los 5 deben dar 403**:
   - `/informes/stock`
   - `/informes/stock/data`
   - `/informes/cuenta-corriente`
   - `/informes/cuenta-corriente/exportar`
   - `/informes/reporte-final`

   > Estos son exactamente los accesos que **hoy funcionan** para un vendedor. Antes de aplicar la
   > feature conviene comprobarlo, para ver el bug con los propios ojos y confirmar después que se
   > cerró.

### 4.3 Como Contable

1. Crear/activar un usuario con rol Contable (anotar la credencial en `CREDENCIALES_ACCESO.txt`).
2. Sidebar → Informes: deben verse **sólo 4** ítems — Compras, Gastos, Cuenta Corriente Proveedores,
   Información para tu Contador.
3. Entrar a los 4 y confirmar que cargan y exportan.
4. Pegar a mano `/informes/ventas`, `/informes/reporte-final`, `/informes/stock` y
   `/informes/cuenta-corriente`: **403 en los 4**.
5. Menú de fila de un **Proveedor**: sí ofrece "Cuenta Corriente". Menú de fila de un **Cliente**: no
   la ofrece.

### 4.4 Rol de prueba: sólo consulta, sin descarga

1. Configuración → Roles y Permisos → crear rol "Consulta Ventas" con **únicamente**
   `informes.ventas` marcado (sin `informes.exportar`).
2. Asignarlo a un usuario de prueba y entrar.
3. El Informe de Ventas se ve completo, pero **los botones de exportar y PDF no aparecen** (FR-018).
4. Pegar a mano `/informes/ventas/exportar`: **403** (FR-010).

Esto valida SC-003 y de paso confirma que la pantalla de Roles lista los 9 permisos nuevos agrupados
bajo Informes de forma legible (SC-006).

## 5. Checklist de cierre

- [ ] `php artisan test --filter=Informe` en verde.
- [ ] El chequeo de cobertura de rutas devuelve "OK: las 65 rutas tienen permiso".
- [ ] El reparto por rol en la base coincide con la tabla del punto 2.
- [ ] `informes.ver` ya no existe en el catálogo.
- [ ] Las 5 URLs del punto 4.2 dan 403 con un vendedor (antes daban 200).
- [ ] `docs/documentacion_principal_crm.md` actualizado con el catálogo de permisos nuevo.
- [ ] `CREDENCIALES_ACCESO.txt` actualizado si se crearon o cambiaron accesos de prueba.
