<?php

namespace App\Http\Requests\Concerns;

use App\Models\ConfiguracionVentas;

/**
 * Completa `vendedor_id` con el vendedor por defecto cuando el request no lo trae.
 *
 * ## Por qué
 *
 * Toda venta/presupuesto tiene que quedar asignada a alguien — es una regla del negocio, no un
 * detalle de formulario. Pero `ventas.vendedor_id` es nullable en la base (por los registros
 * migrados de Contagram, que no traían vendedor) y hay tres caminos de alta que no pasan por el
 * formulario: la conversión de órdenes de Mercado Libre, la de Tiendanube y la conversión de
 * Presupuesto a Venta.
 *
 * Con `required` a secas esos caminos rompen. Con `nullable` la regla no existe. El default cierra
 * las dos puntas: el formulario igual obliga a elegirlo (`validar()` en ventas.js/presupuestos.js
 * avisa antes de enviar), y cualquier alta que llegue sin vendedor toma el configurado en
 * Configuración → Ventas en vez de quedar huérfana.
 *
 * Si no hay vendedor por defecto configurado, no inventa nada: deja el campo vacío y la regla
 * `required` responde con "El campo Vendedor es obligatorio.", que es la respuesta correcta.
 */
trait CompletaVendedorPorDefecto
{
    protected function completarVendedor(): void
    {
        if ($this->filled('vendedor_id')) {
            return;
        }

        $porDefecto = ConfiguracionVentas::first()?->vendedor_id;

        if ($porDefecto !== null) {
            $this->merge(['vendedor_id' => $porDefecto]);
        }
    }
}
