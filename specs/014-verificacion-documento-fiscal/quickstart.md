# Quickstart: Verificación de documento fiscal (CUIT/CUIL)

## Prerrequisitos

- Servidor local corriendo (`php artisan serve` o XAMPP) con la base `contagram` migrada.
- Sesión iniciada con un usuario que tenga permiso sobre Clientes y/o Proveedores.

## Escenario 1 — Botón "Verificar" en Cliente (User Story 1)

1. Ir a Base de Datos → Clientes → "Nuevo Cliente".
2. En el bloque "Datos de Facturación", tipear `30712345678` en el campo N° de Doc (tipo CUIT).
   **Esperado**: el campo se auto-formatea a `30-71234567-8` mientras se tipea.
3. Cambiar el número para que el último dígito quede incorrecto (ej. terminar en un dígito distinto
   al verificador real) y hacer clic en "Verificar".
   **Esperado**: aparece "El CUIT ingresado no es válido." en rojo (mismo texto que ya usa la
   validación de guardado), sin recargar la página ni cerrar el modal.
4. Corregir el número a uno válido y hacer clic en "Verificar" de nuevo.
   **Esperado**: el mensaje de error desaparece / se confirma que es válido.
5. Sin tocar "Verificar", volver a cargar un número inválido y hacer clic directo en "Crear".
   **Esperado**: el guardado se bloquea igual, con el mismo mensaje (comportamiento ya existente,
   sin cambios — confirma FR-004).
6. Repetir 1-5 en Base de Datos → Proveedores → "Nuevo Proveedor" (User Story 1, Proveedor).

## Escenario 2 — Conversión automática de Mercado Libre con documento inválido (User Story 2)

Requiere el entorno de pruebas de Mercado Libre ya documentado en `CREDENCIALES_ACCESO.txt`
(cuenta vendedor/comprador de test) o, más simple, un test de Feature (ver `tasks.md`).

Vía test automatizado (forma recomendada, no requiere pegarle a la API real de Mercado Libre):

1. Simular una orden con `comprador_condicion_iva = null`, `comprador_doc_tipo = "CUIT"` y
   `comprador_doc_numero` con dígito verificador incorrecto (mismo patrón que
   `tests/Feature/Integraciones/MercadoLibreClienteNuevoTest.php`).
2. Ejecutar la conversión automática.
3. **Esperado**:
   - La Venta se crea igual (no queda "Requiere atención").
   - El comprobante generado es tipo B (Consumidor Final), no A.
   - El Cliente nuevo creado para ese comprador tiene `cuit = null`.
4. Repetir con `comprador_doc_tipo = "CUIL"` — mismo resultado esperado.
5. Repetir con un `comprador_doc_numero` **válido** — el comportamiento no debe cambiar respecto al
   actual (comprobante A, CUIT persistido).

## Verificación rápida por consola (sin UI)

```bash
php artisan tinker
>>> App\Rules\CuitValido::esValido('30712345678')  // ajustar al DV real, esto es sólo de ejemplo
```

Para el endpoint AJAX, con sesión iniciada (cookie de navegador) o vía `curl` con cookies guardadas:

```bash
curl -s -b cookies.txt "http://localhost:8000/clientes/verificar-documento?tipo_documento=CUIT&numero=30-71234567-8"
```
