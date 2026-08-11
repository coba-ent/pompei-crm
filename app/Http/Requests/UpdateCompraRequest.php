<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCompraRequest extends FormRequest
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
            'proveedor_id' => 'required|exists:proveedores,id',
            'categoria_id' => 'nullable|exists:categorias,id',
            'deposito_id' => 'required|integer|exists:depositos,id,activo,1',
            'nro_comprobante' => 'required|string|max:20',
            'fecha_emision' => 'required|date',
            'fecha_vto_pago' => 'nullable|date',
            'servicio_desde' => 'nullable|date',
            'servicio_hasta' => 'nullable|date',
            'mes_imputacion_iva' => 'nullable|date',
            'tipo_comprobante' => 'nullable|string|max:10',
            'nota_interna' => 'nullable|string',
            'descuento_general_pct' => 'nullable|numeric|between:0,100',
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

    /** FR-012: un comprobante fiscal aprobado (CAE del Proveedor) es inmutable en Tipo/proveedor/ítems. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $compra = $this->route('compra');
            $comprobante = $compra?->comprobanteFiscal;

            if (! $comprobante || ! $comprobante->aprobado()) {
                return;
            }

            if ((string) $this->input('tipo_comprobante') !== (string) $compra->tipo_comprobante) {
                $validator->errors()->add('tipo_comprobante', 'No se puede modificar el Tipo de Comprobante: ya tiene datos fiscales aprobados.');
            }

            if ((int) $this->input('proveedor_id') !== (int) $compra->proveedor_id) {
                $validator->errors()->add('proveedor_id', 'No se puede modificar el proveedor: la Compra ya tiene datos fiscales aprobados.');
            }

            if (count($this->input('items', [])) !== $compra->items()->count()) {
                $validator->errors()->add('items', 'No se pueden modificar los ítems: la Compra ya tiene datos fiscales aprobados.');
            }
        });
    }
}
