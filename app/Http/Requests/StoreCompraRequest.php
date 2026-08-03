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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'submit_token' => 'required|string',
            'proveedor_id' => 'required|exists:proveedores,id',
            'categoria_id' => 'nullable|exists:categorias,id',
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
            'descuento_general_pct' => 'nullable|numeric|between:0,100',
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
}
