# Quickstart — validación de Lista de Precios en la configuración de Mercado Libre (spec 016)

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md)

Guía de validación end-to-end. No contiene código de implementación: eso vive en `tasks.md` y en la fase
de implementación.

---

## Prerrequisitos

1. **Todo lo de `specs/012-ventas-mercadolibre/quickstart.md`** ya validado: cuenta conectada, función
   activa, al menos un producto vinculado a una publicación.
2. **Al menos dos Listas de Precios activas** cargadas en Configuración & Ajustes → Listas de Precios.
3. **Una orden de Mercado Libre** sincronizada y lista para convertir (estado "Lista", spec 012).

```bash
php artisan migrate
npm run build
```

---

## Escenario 1 — Configurar una Lista de Precios para Mercado Libre (US1)

1. Ir a Configuración & Ajustes → Integraciones → Mercado Libre.
2. En la sección "Configuración de Ventas", abrir el selector "Lista de Precios" (Select2) y elegir una
   de las Listas de Precios activas.
3. Guardar.

**Esperado**: notificación toast de éxito, sin recargar la página; al recargar la pantalla el selector
sigue mostrando la Lista de Precios elegida (FR-001, SC-001).

**Verificar opcionalidad**: repetir dejando el selector vacío ("Sin lista de precios") y guardar.

**Esperado**: se guarda sin error (FR-002).

---

## Escenario 2 — La Venta convertida queda etiquetada, sin cambiar precios (US2)

1. Con una Lista de Precios configurada (Escenario 1), ir a Ingresos → Mercado Libre y convertir una
   orden pendiente en Venta.
2. Abrir la Venta creada.

**Esperado**:
- El campo Lista de Precios de la Venta muestra la Lista configurada (FR-003, SC-002).
- El total y el precio de cada línea son exactamente los que ya mostraba el sistema antes de esta spec
  (importe pagado en Mercado Libre, IVA desagregado) — comparar contra el detalle de la orden en la
  pantalla de Mercado Libre, no contra `precios_producto` (FR-005, SC-003).

**Verificar sin configuración** (FR-004, SC-004): vaciar el selector de Lista de Precios (Escenario 1),
convertir otra orden, y confirmar que la Venta se crea igual que antes de esta spec, sin Lista de
Precios asignada y sin error.

**Verificar no retroactividad** (FR-006): cambiar la Lista de Precios configurada y revisar que las
Ventas ya convertidas anteriormente conservan la Lista de Precios que tenían, no la nueva.

---

## Regresión mínima

- Convertir una orden con **conversión automática** activa (spec 012) y confirmar que también queda
  etiquetada igual que la manual (Edge Cases del spec).
- Correr la suite de tests de `tests/Feature/Integraciones/` relacionada a `ConversorOrdenAVenta` y
  confirmar que sigue en verde — es la garantía de que FR-005 no introdujo una regresión de cálculo.
