<?php

namespace App\Http\Requests\Concerns;

use App\Models\Producto;
use Illuminate\Validation\Rule;

/**
 * Reglas de validación compartidas entre alta y edición de producto.
 */
trait ReglasProducto
{
    /**
     * @return array<string, mixed>
     */
    protected function reglasProducto(?int $productoId = null): array
    {
        return [
            // Datos básicos (US1)
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', 'in:producto,servicio'],
            'tipo_producto_id' => ['nullable', 'integer', 'exists:tipos_producto,id'],
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'imagen' => ['nullable', 'image', 'max:4096'],
            'imagen_eliminar' => ['nullable', 'boolean'],

            // Económicos y flags (US2)
            'precio_venta' => ['nullable', 'numeric', 'min:0'],
            'iva_venta_pct' => ['nullable', Rule::in(array_keys(Producto::OPCIONES_IVA))],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'iva_compra_pct' => ['nullable', Rule::in(array_keys(Producto::OPCIONES_IVA))],
            'mostrar_en_ventas' => ['nullable', 'boolean'],
            'mostrar_en_compras' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],

            // Punto de reposición (spec 073). `0` = el producto no se controla. Sigue siendo
            // `nullable` como *entrada* (campo vacío del modal, celda vacía de una importación):
            // `Producto::setPuntoReposicionAttribute()` lo normaliza a 0 antes de guardar.
            'punto_reposicion' => ['nullable', 'integer', 'min:0'],

            // Stock inicial (sólo se aplica al crear un producto de tipo=producto)
            'stock_inicial' => ['nullable', 'numeric', 'min:0'],
            'stock_inicial_deposito_id' => ['nullable', 'integer', 'exists:depositos,id'],

            // Variantes (US4)
            'variantes' => ['nullable', 'array'],
            'variantes.*.id' => ['nullable', 'integer'],
            'variantes.*.sku' => ['nullable', 'string', 'max:255'],
            'variantes.*.talle' => ['nullable', 'string', 'max:100'],
            'variantes.*.color' => ['nullable', 'string', 'max:100'],
            'variantes.*.nombre' => ['nullable', 'string', 'max:255'],
            'variantes.*.precio_extra' => ['nullable', 'numeric'],

            // Precios por lista (US5)
            'precios' => ['nullable', 'array'],
            'precios.*.lista_precio_id' => ['nullable', 'integer', 'exists:listas_precio,id'],
            'precios.*.precio' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Valida unicidad de lista de precio dentro del propio payload de precios
     * (no puede repetirse la misma lista dos veces en el mismo alta/edición).
     */
    protected function validarListasEnPayload(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $listas = [];
        foreach ((array) $this->input('precios', []) as $i => $precio) {
            $listaId = $precio['lista_precio_id'] ?? null;
            if (empty($listaId)) {
                continue;
            }
            if (isset($listas[$listaId])) {
                $validator->errors()->add("precios.$i.lista_precio_id", 'La lista de precio está repetida.');
            }
            $listas[$listaId] = true;
        }
    }
}
