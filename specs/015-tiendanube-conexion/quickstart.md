# Quickstart — Validación de la conexión con Tiendanube

**Feature**: `015-tiendanube-conexion`

Guía para validar la feature de punta a punta. A diferencia de
`011-mercadolibre-conexion-oauth/quickstart.md`, acá **no hace falta un entorno publicado**: al no
haber redirect de autorización, la conexión real puede probarse incluso en local, siempre que la
tienda de Tiendanube y su Aplicación personalizada ya existan.

---

## Parte 1 — Validación local con la API simulada

### Preparación

```bash
php artisan migrate
php artisan db:seed --class=FuncionAvanzadaSeeder
```

Confirmar que el seeder dejó la tarjeta "Tiendanube" con `disponible = true` (antes de esta spec estaba
en `false` — ver tasks.md, tarea de actualizar el seeder).

### Ejecutar la suite

```bash
php artisan test --filter=Tiendanube
```

**Debe pasar en verde**, incluyendo:

| Test | Qué prueba | Criterio |
|---|---|---|
| `TiendanubeConfiguracionTest` | Guardado de credenciales, cifrado, token nunca expuesto, validaciones (FR-001..006b) | FR-001..006b |
| `TiendanubeConexionTest` | "Probar conexión" contra `Http::fake()`, mapeo de datos de tienda, estados No configurada/Conectada/Caída | FR-007..012 |
| `TiendanubeManejoErroresTest` | 401/404/429/5xx según la política de reintentos (research.md §R5) | FR-012..014 |
| `TiendanubeModoSoloLecturaTest` | Escrituras bloqueadas y registradas; lecturas permitidas | SC-003 |
| `TiendanubeFuncionDesactivadaTest` | Toda operación rechazada mientras la función esté apagada, sin alterar el estado de la conexión | FR-006b |

### Verificación manual de la pantalla (con `Http::fake()` o mock)

1. Entrar a **Configuración & Ajustes → Funciones Avanzadas**.
2. Confirmar que la tarjeta **"Tiendanube"** ya no aparece deshabilitada y se puede activar.
3. Activar la función → entrar a su configuración → estado **"No configurada"**.
4. Cargar un `store_id` y un token cualquiera → guardar → toast de confirmación, **sin recarga de
   página** (SC-006), acción "Probar conexión" habilitada.
5. Volver a entrar a la pantalla → el `store_id` se ve, el token **no** (sólo indicador de que está
   cargado) — SC-005.

### Verificación de que no se filtra el token (SC-005)

```bash
php artisan tinker --execute="dd(App\Models\Integraciones\TiendanubeConfiguracion::actual()->toArray())"
```

`access_token` no debe aparecer en claro (el cast `encrypted` + `$hidden` lo protegen). Revisar también
`tn_operaciones_log` tras un intento fallido: el `mensaje_error` no debe contener el token.

---

## Parte 2 — Validación contra la API real de Tiendanube

### Prerrequisitos

- Una tienda de Tiendanube (real o de prueba, según lo que Tiendanube ofrezca al momento de
  implementar).
- Una Aplicación personalizada creada desde el panel de esa tienda (o el Partner Portal), con el
  `access_token` generado y el `store_id` visible.

### Pasos

1. Cargar `store_id` + `access_token` reales en la pantalla de configuración del CRM.
2. Presionar **"Probar conexión"** → debe mostrar nombre, dominio, país y moneda reales de la tienda
   (SC-002), y el estado debe pasar a **"Conectada"**.
3. Verificar en el panel de Tiendanube que no se registró ninguna escritura (esta spec sólo lee).
4. Activar **"Modo sólo lectura"** → confirmar que "Probar conexión" sigue funcionando (es lectura,
   FR-018) y que el aviso permanente aparece en pantalla (FR-019).
5. Regenerar el token desde el panel de Tiendanube (invalidando el que tiene el CRM) → presionar
   "Probar conexión" de nuevo → debe pasar a **"Caída"** con la acción de recargar credenciales visible
   (User Story 4).
6. Cargar el token nuevo → "Probar conexión" → vuelve a **"Conectada"** sin tener que recrear el resto
   de la configuración.
7. Presionar **"Desconectar"**, confirmar → estado **"Desconectada"**, `nombre_tienda`/`dominio`/
   `pais`/`moneda` siguen visibles para trazabilidad (FR-011), pero el token ya no existe.

### Anotar en este archivo tras la primera corrida real

- Ubicación exacta donde Tiendanube mostró el `store_id` y generó el `access_token` (research.md §R1).
- Si la cabecera `Authentication` (no `Authorization`) siguió siendo necesaria (research.md §R3).
- Cualquier campo de `GET /store` que difiera de lo documentado en `contracts/api-tiendanube.md` §3.
