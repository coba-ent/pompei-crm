# Quickstart / validación — spec 115

> ⚠️ Nunca contra la cuenta real del cliente ni en producción (memoria "nunca probar en producción").
> Local con `Http::fake()` en tests; en navegador local con la cuenta ML en **modo sólo lectura** para
> validar bloqueos, o con un usuario de prueba de Mercado Libre para el camino feliz.

## Tests automáticos

```bash
php artisan test --filter=CambioTipoPublicacion
php artisan test --filter=MercadoLibreVinculacionTablaTipo
php artisan test tests/Feature/Integraciones   # regresión de precios/retenciones/observer Premium
```

Casos que deben quedar cubiertos:

1. →Premium feliz: orden de llamadas = PUT precio → POST listing_type; vínculo queda `gold_pro`, `precio_publicado` = precio Premium.
2. →Clásica feliz con caída 31% y corte 084 activo: no se crea retención; orden = POST listing_type → PUT precio.
3. →Premium sin precio en lista Premium / sin lista Premium configurada: 422, cero requests de escritura.
4. →Premium con POST listing_type rechazado: se re-envía el precio anterior; resultado `revertido`; tipo intacto.
5. →Clásica con PUT precio rechazado: resultado `cambiado_precio_pendiente`, `precio_pendiente = 1`.
6. ML ya tenía el tipo destino: `sin_cambios`, sin escrituras.
7. Destino ausente de available_upgrades/downgrades: bloqueado.
8. Modo sólo lectura / función desactivada: bloqueado, sin escrituras.
9. Candado `ml:sincronizar_precios` tomado: 409.
10. Retención abierta: queda `reemplazada` tras el cambio.
11. Lote: mezcla de cambiables/omitidas/fallidas → resumen correcto; un segundo lote en curso → 409.
12. DataTable: filtro por tipo (incluido `sin_clasificar`) y búsqueda por ID/código de producto.
13. Historial: una fila en `ml_cambios_tipo_publicacion` por intento, con usuario.

## Validación manual (local)

1. `php artisan migrate` y `npm run build`.
2. Abrir **Ingresos → Mercado Libre → Vinculaciones**: ver columna Tipo y Producto como `#ID · código — nombre`; filtrar por Premium; recargar y comprobar que el filtro persiste.
3. Menú de una Clásica → "Pasar a Premium": el modal muestra precios antes/después, la lista ML Premium y el aviso de comisión.
4. Probar con un producto sin precio en ML Premium: el botón Confirmar queda deshabilitado con el motivo.
5. Marcar 3 casillas → "Pasar selección a Premium": modal con "Se van a cambiar" / "Omitidas"; confirmar y ver el progreso y el resumen; la tabla se refresca sin recargar.
6. Revisar consola sin errores y que colvis no permite ocultar Selección/Acciones.
