<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePresupuestoRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'No se salvó el Presupuesto, revise el formulario.',
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
            'cliente_id' => 'required|exists:clientes,id',
            'categoria_id' => 'nullable|exists:categorias,id',
            'lista_precio_id' => 'nullable|exists:listas_precio,id',
            'fecha_emision' => 'required|date',
            'fecha_validez' => 'nullable|date',
            'servicio_desde' => 'nullable|date',
            'servicio_hasta' => 'nullable|date',
            'descuento_general_pct' => 'nullable|numeric|between:0,100',
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
}
