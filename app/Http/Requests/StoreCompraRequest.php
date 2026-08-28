<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreCompraRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'No se salvó la Compra, revise el formulario.',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Un `nro_comprobante` vacío entra como NULL, no como cadena vacía.
     *
     * `compras` tiene `unique(['tipo_comprobante','nro_comprobante'])`: en MySQL dos NULL no colisionan
     * entre sí en un índice único, pero dos `''` sí — así que sin esto la segunda compra sin número
     * fallaría con un error de duplicado incomprensible para el usuario.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('nro_comprobante') === '') {
            $this->merge(['nro_comprobante' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'submit_token' => 'required|string',
            'proveedor_id' => 'required|exists:proveedores,id',
            'categoria_id' => 'nullable|exists:categorias,id',
            'deposito_id' => 'required|integer|exists:depositos,id,activo,1',
            // Obligatorio salvo "Sin Factura" (`tipo_comprobante = 'S'`): esa opción del formulario
            // es justamente una compra sin comprobante fiscal, así que no hay número que pedir. Con
            // `required` a secas no se podía cargar ninguna (952 de las 2.404 migradas no lo tienen).
            'nro_comprobante' => 'required_unless:tipo_comprobante,S|nullable|string|max:20',
            'fecha_emision' => 'required|date',
            'fecha_vto_pago' => 'nullable|date',
            'servicio_desde' => 'nullable|date',
            'servicio_hasta' => 'nullable|date',
            'mes_imputacion_iva' => 'nullable|date',
            'tipo_comprobante' => 'nullable|string|max:10',
            // Datos fiscales declarados por el Proveedor en su propio comprobante (FR-015): el CAE
            // lo emite el Proveedor, este CRM no solicita uno propio para Compras.
            'punto_venta_proveedor' => 'nullable|string|max:10',
            'numero_comprobante_proveedor' => 'nullable|string|max:20',
            'cae_proveedor' => 'nullable|string|max:20',
            'cae_vencimiento_proveedor' => 'nullable|date',
            'nota_interna' => 'nullable|string',
            'descuento_general_tipo' => 'nullable|in:porcentaje,monto',
            'descuento_general_pct' => 'nullable|numeric|between:0,100',
            'descuento_general_monto' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'nullable|exists:productos,id',
            'items.*.descripcion' => 'required|string|max:255',
            'items.*.cantidad' => 'required|numeric|not_in:0',
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
