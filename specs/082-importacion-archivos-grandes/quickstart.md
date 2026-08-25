# Quickstart: verificación de la importación escalable

**Spec**: [spec.md](./spec.md) | **Fecha**: 2026-08-25

## Regla previa

**Las pruebas funcionales van en LOCAL, nunca en producción.** El VPS está en uso real. La validación
post-despliegue en el VPS es **sólo de lectura** (contar filas, mirar logs), salvo autorización
explícita y puntual del usuario.

## 1. Preparar un archivo de prueba grande

Exportar Productos desde la app local (Productos → Exportar) para obtener una planilla con el formato
real, incluidas las columnas dinámicas de listas de precios y depósitos.

Si la base local no tiene volumen suficiente, multiplicar las filas del export conservando los `Id`
reales (para que sean actualizaciones, no altas) hasta superar las 9.000 filas.

## 2. Verificar el caso principal (SC-001, SC-002)

1. Importar Datos → Productos & Servicios → subir el archivo.
2. Mapear **Id** + las columnas a actualizar.
3. Confirmar.

**Qué tiene que pasar**:
- El progreso avanza de forma continua, tanda por tanda (39 tandas para 9.632 filas).
- Termina **completo**: el resumen reporta el total de filas del archivo.
- Tarda menos de 25 minutos.
- La pantalla **nunca** queda colgada en "Preparando la importación…".

**Qué mirar además**: que la memoria del proceso PHP no crezca tanda a tanda (debe quedar plana).

## 3. Verificar que reimportar sin cambios no hace nada (SC-005)

Reimportar **el mismo archivo** inmediatamente después, con el mismo mapeo.

**Qué tiene que pasar**: cero eventos de auditoría de precio y cero movimientos de stock nuevos.

```sql
SELECT COUNT(*) FROM logs_auditoria  WHERE created_at >= '<inicio de la reimportación>';
SELECT COUNT(*) FROM movimientos_stock WHERE created_at >= '<inicio de la reimportación>';
-- ambos deben dar 0
```

## 4. Verificar el corte y la retoma (SC-004, US2)

Forzar el fallo de una tanda intermedia. Formas simples en local:

- Cortar la red del navegador (DevTools → Network → Offline) durante una tanda y volver a activarla.
- O parar el servidor un momento y volver a levantarlo.

**Qué tiene que pasar**:
- Si el corte es breve → el reintento automático (2/4/8 s) lo absorbe y la importación sigue sola.
- Si el corte persiste → aparece el error y el botón **"Reanudar desde la fila N"**.
- Al reanudar → se procesan **sólo** las filas pendientes.
- Al terminar → los totales son los mismos que sin corte, sin filas duplicadas ni salteadas.

**Verificación clave del deshacer (FR-010)**: la importación cortada y retomada tiene que quedar como
**una sola** corrida.

```sql
SELECT id, filas_creadas, filas_actualizadas, filas_fallidas
FROM importacion_corridas ORDER BY id DESC LIMIT 3;
-- una sola corrida nueva, con el total completo

SELECT COUNT(*), COUNT(DISTINCT numero_fila)
FROM importacion_filas_snapshot WHERE importacion_corrida_id = <id>;
-- ambos numeros deben ser IGUALES: ningun numero_fila duplicado
```

Ese segundo query es el que detecta el problema de idempotencia (Decisión 5 de research): si un
reintento reprocesó filas ya aplicadas, habría más snapshots que `numero_fila` distintos.

## 5. Verificar que no hay regresiones (SC-007)

```bash
php artisan test tests/Unit/ImportadorFilasParseoTest.php \
                 tests/Unit/ImportadorFilasResolucionIdTest.php \
                 tests/Feature/ImportacionProductosStockTest.php \
                 tests/Feature/DeshacerImportacionProductosTest.php \
                 tests/Feature/AuditoriaPrecioProductoTest.php
```

Tienen que pasar **sin modificar sus expectativas**. Si hay que tocar una expectativa, es una
regresión disfrazada: revisar antes de cambiarla.

Además, probar un archivo **chico** (menos de 250 filas) y confirmar que el comportamiento y el
resumen son idénticos a los de antes (FR-018).

## 6. Verificar Clientes y Proveedores (SC-006)

Repetir el caso principal con una planilla grande de Clientes y otra de Proveedores, confirmando que
sus reglas propias siguen funcionando (en Clientes: CUIT/DNI en dos columnas, saldo inicial con fecha,
lista de precios por nombre).

## 7. Despliegue

### Código

Deploy normal al VPS (`git pull` con la deploy key, limpiar y recachear, `reload php8.2-fpm`).
**Requiere autorización explícita del usuario**: el VPS está en uso real.

Sin migraciones (esta feature no toca el esquema), pero conviene confirmarlo antes:

```bash
php artisan migrate:status | grep -i pending   # no debe listar nada de esta feature
```

### nginx (opcional, margen extra — NO ejecutar sin autorización)

El `server` block del VPS no define `fastcgi_read_timeout`, así que corre con el default de 60 s. Con
esta feature las tandas bajan a ~26 s, así que **60 s ya alcanza**. Subirlo es margen para el
crecimiento del catálogo:

```nginx
# /etc/nginx/sites-enabled/contagram, dentro del bloque location ~ \.php$
fastcgi_read_timeout 300;
```

```bash
nginx -t && systemctl reload nginx
```

### Verificación post-despliegue (sólo lectura)

```sql
SELECT id, archivo_original, confirmado_en, filas_creadas, filas_actualizadas, filas_fallidas
FROM importacion_corridas ORDER BY id DESC LIMIT 5;
```

Y revisar que no aparezcan timeouts nuevos:

```bash
grep 'upstream timed out' /var/log/nginx/error.log | tail
```

## Referencia: el incidente que motivó todo esto

| | Antes (25/08/2026) | Después (objetivo) |
|---|---|---|
| Filas aplicadas de 1.117 | 1.000 | 1.117 |
| Filas por tanda | 1.000 | 250 |
| Tiempo por tanda (9.632 filas) | ~129 s ❌ | ~26 s ✅ |
| Interpretaciones del archivo | 1 por tanda | 1 por importación |
| Memoria por tanda (9.632 filas) | ~570 MB ❌ | plana ✅ |
| Ante un corte | se pierde el resto | reintenta y se puede retomar |
