# Quickstart — Validación de Gestión de Depósitos

Guía para validar la feature de punta a punta. No incluye código de implementación (eso va en
`tasks.md`/implementación); son los pasos y resultados esperados que prueban que el módulo funciona.

## Prerrequisitos

- Módulo Productos (`002-productos`) ya implementado, con al menos un depósito sembrado por
  `DepositoSeeder` y un producto con stock cargado en ese depósito.
- XAMPP con MySQL corriendo, DB `contagram`. Dependencias PHP/JS instaladas.

## Setup

```bash
npm run build                   # compila configuracion-depositos.js
php artisan serve               # levanta la app en http://127.0.0.1:8000
```

## Escenarios de validación

### US1 — Administrar el catálogo de Depósitos

1. Navegar a Configuración & Ajustes → Depósitos. Verificar que la nueva entrada existe en el
   sidebar y que la pantalla carga sin errores.
2. Abrir el modal "Configuración de Depósitos" → verificar que lista los depósitos ya sembrados
   (nombre, checkbox de activo, íconos de editar/eliminar).
3. Click en "+ Agregar Depósito" → aparece una fila nueva con nombre sugerido "Depósito N" → editar
   el nombre → guardar esa fila → toast de éxito, sin recargar la página.
4. Ir a `/productos`, abrir el panel de Filtros → verificar que el depósito recién creado aparece
   en el selector "Depósito".
5. Volver a Depósitos, desmarcar el checkbox de activo de ese depósito nuevo → toast confirma →
   volver a Productos → el depósito ya NO aparece en el filtro ni en el selector de Ajuste de Stock.
6. Reactivar el depósito → vuelve a aparecer en ambos selectores.
7. Intentar eliminar el depósito que tiene stock cargado (del prerrequisito) → rechazado (409 /
   toast) con el mensaje de que tiene stock/movimientos asociados.
8. Eliminar el depósito nuevo (sin stock) → se elimina correctamente, desaparece de la lista.
9. Agregar una fila nueva sin completar el nombre y guardar → se rechaza esa fila con mensaje de
   validación, sin afectar las demás filas ya guardadas.

## Tests automatizados

```bash
php artisan test --filter=Deposito
```

Debe quedar en verde antes de dar la feature por terminada (Principio IV de la constitución: la
protección de eliminación con stock/movimientos asociados tiene impacto en integridad de
valorización de inventario).

## Consistencia de documentación (antes de cerrar la feature)

- Actualizar `docs/documentacion_principal_crm.md` §2.2: reemplazar "el alta/baja de depósitos hoy
  se maneja vía seeder/DB directa... se retoma cuando se rehaga ese módulo" por una sección activa
  nueva describiendo la pantalla de Configuración & Ajustes → Depósitos.
- Agregar en `docs/modelo_datos.md` la nota sobre `Deposito::tieneOperaciones()`.

## Criterios de aceptación cubiertos

SC-001 (alta de depósito <10s, visible en el filtro de Productos sin recargar), SC-002 (protección
de eliminación 100% consistente), SC-003 (estructura fiel a Contagram real salvo la divergencia
documentada de la advertencia de operación larga).
