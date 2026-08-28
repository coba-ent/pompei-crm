<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreVentaRequest extends FormRequest
{
    use Concerns\CompletaVendedorPorDefecto;

    /** Toma el vendedor por defecto de Configuración → Ventas si el alta no trae uno. */
    protected function prepareForValidation(): void
    {
        $this->completarVendedor();
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'No se salvó la Venta, revise el formulario.',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'submit_token' => 'required|string',
            'presupuesto_id' => 'nullable|exists:presupuestos,id',
            'cliente_id' => 'required|exists:clientes,id',
            'categoria_id' => 'nullable|exists:categorias,id',
            'lista_precio_id' => 'nullable|exists:listas_precio,id',
            // Obligatorio: toda venta/presupuesto se le asigna a alguien. La columna es nullable por los
            // registros migrados de Contagram, que no traían vendedor; la regla aplica de acá en más.
            'vendedor_id' => 'required|integer|exists:vendedores,id',
            'deposito_id' => 'required|integer|exists:depositos,id,activo,1',
            'fecha_emision' => 'required|date',
            'fecha_validez' => 'nullable|date',
            'servicio_desde' => 'nullable|date',
            'servicio_hasta' => 'nullable|date',
            'tipo_comprobante' => 'required|in:A,B,C,E',
            'fecha_vto_cobro' => 'nullable|date',
            'descuento_general_tipo' => 'nullable|in:porcentaje,monto',
            'descuento_general_pct' => 'nullable|numeric|between:0,100',
            'descuento_general_monto' => 'nullable|numeric|min:0',
            'nota_cliente' => 'nullable|string',
            'nota_interna' => 'nullable|string',
            'formas_pago' => 'nullable|string|max:255',
            'metodos_envio' => 'nullable|string|max:255',
            'etiquetas' => 'nullable|array',
            'etiquetas.*' => 'string|max:255',
            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'nullable|exists:productos,id',
            'items.*.descripcion' => 'required|string|max:255',
            'items.*.cantidad' => 'required|numeric|gt:0',
            'items.*.precio_unitario' => 'required|numeric|gte:0',
            'items.*.descuento_pct' => 'nullable|numeric|between:0,100',
            'items.*.iva_pct' => 'nullable|string',
            'conceptos' => 'nullable|array',
            'conceptos.*.tipo' => 'required_with:conceptos|in:percepcion,impuesto_interno,interes',
            'conceptos.*.concepto' => 'required_with:conceptos|string|max:255',
            'conceptos.*.monto' => 'required_with:conceptos|numeric',
        ];
    }

    /** FR-007: el descuento general en modo monto fijo no puede superar el subtotal bruto de los ítems. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('descuento_general_tipo') !== 'monto') {
                return;
            }

            $montoDescuento = (float) $this->input('descuento_general_monto', 0);
            $subtotalBruto = 0.0;

            foreach ($this->input('items', []) as $item) {
                $bruto = (float) ($item['cantidad'] ?? 0) * (float) ($item['precio_unitario'] ?? 0);
                $descuentoPct = (float) ($item['descuento_pct'] ?? 0);
                $subtotalBruto += $bruto - ($bruto * $descuentoPct / 100);
            }

            if ($montoDescuento > $subtotalBruto) {
                $validator->errors()->add('descuento_general_monto', 'El descuento general no puede ser mayor al subtotal del comprobante.');
            }
        });
    }
}
